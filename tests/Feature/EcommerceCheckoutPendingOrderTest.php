<?php

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\InventoryBalance;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ShippingMethod;
use App\Models\ShippingRate;
use App\Models\TaxRate;
use App\Models\User;
use App\Services\CartService;
use App\Services\CheckoutConflictException;
use App\Services\CheckoutService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Mail;
use Stripe\Exception\ApiConnectionException;
use Stripe\WebhookSignature;
use Tests\Support\Stripe\FakeStripeBillingService;

uses(RefreshDatabase::class);

const CONFLICT_NOTICE = 'お支払い待ちのご注文と、入力内容またはカートの内容が異なります。そのまま決済を再開するか、ご注文を取り消してから新しくご注文ください。';

const PENDING_SESSION_URL = 'https://checkout.stripe.test/pay/';

beforeEach(function () {
    Mail::fake();
    config(['services.stripe.ecommerce_webhook_secret' => 'whsec_pending_test']);
    $this->sessions = FakeStripeBillingService::install(app());

    $this->user = User::factory()->create();
    $this->cart = Cart::query()->create(['user_id' => $this->user->id]);
    $this->variant = ProductVariant::factory()
        ->for(Product::factory()->create(['status' => 'published']))
        ->create(['price_minor' => 1000]);
    $this->balance = InventoryBalance::factory()->for($this->variant, 'variant')->create(['on_hand' => 5, 'reserved' => 0]);
    CartItem::query()->create(['cart_id' => $this->cart->id, 'variant_id' => $this->variant->id, 'quantity' => 1]);
    $method = ShippingMethod::query()->create(['code' => 'standard', 'name' => '通常配送', 'is_active' => true]);
    $this->rate = ShippingRate::query()->create([
        'shipping_method_id' => $method->id,
        'country_code' => 'JP',
        'fee_minor' => 500,
        'currency' => 'JPY',
        'is_active' => true,
    ]);
    TaxRate::query()->create(['country_code' => 'JP', 'tax_basis_points' => 1000, 'active_from' => today(), 'is_active' => true]);
    $this->form = [
        'checkout_key' => str_repeat('a', 64),
        'customer_name' => '購入者',
        'customer_email' => 'buyer@example.test',
        'shipping_recipient' => '宛名',
        'shipping_country_code' => 'JP',
        'shipping_postal_code' => '1000001',
        'shipping_city' => '千代田区',
        'shipping_address_line1' => '住所A',
        'shipping_rate_id' => $this->rate->id,
    ];
    $this->actingAs($this->user);
    $this->withoutMiddleware(ThrottleRequests::class);
});

/**
 * @param  array<string, mixed>  $changes
 */
function checkoutWith(mixed $test, array $changes = []): mixed
{
    return $test->post(route('shop.checkout.store'), [...$test->form, ...$changes]);
}

function hiddenCheckoutKey(mixed $response): string
{
    preg_match('/name="checkout_key" value="([a-f0-9]{64})"/', $response->getContent(), $matches);

    return $matches[1];
}

/**
 * @param  array<string, mixed>  $overrides
 */
function paidWebhook(mixed $test, Order $order, array $overrides = []): void
{
    $payment = $order->payments()->sole();
    $payload = json_encode([
        'id' => 'evt_'.bin2hex(random_bytes(8)),
        'object' => 'event',
        'api_version' => '2025-02-24.acacia',
        'created' => now()->timestamp,
        'data' => ['object' => [
            'id' => $payment->provider_checkout_id,
            'object' => 'checkout.session',
            'status' => 'complete',
            'payment_status' => 'paid',
            'amount_total' => $order->total_minor,
            'currency' => 'jpy',
            'client_reference_id' => $order->order_number,
            'payment_intent' => 'pi_'.$order->id,
            'metadata' => ['ec_order_id' => (string) $order->id, 'ec_payment_id' => (string) $payment->id],
            ...$overrides,
        ]],
        'livemode' => false,
        'pending_webhooks' => 1,
        'type' => 'checkout.session.completed',
    ], JSON_THROW_ON_ERROR);

    $test->call('POST', route('stripe.ecommerce.webhook'), server: [
        'HTTP_STRIPE_SIGNATURE' => WebhookSignature::generateSignatureHeader($payload, 'whsec_pending_test'),
        'CONTENT_TYPE' => 'application/json',
    ], content: $payload)->assertOk();
}

test('an edited address is never applied silently to the existing order', function () {
    checkoutWith($this)->assertRedirect(PENDING_SESSION_URL.'cs_test_fake_1');

    checkoutWith($this, ['shipping_address_line1' => '住所B'])
        ->assertRedirect(route('shop.checkout.index'))
        ->assertSessionHas('status', CONFLICT_NOTICE)
        ->assertSessionHasInput('shipping_address_line1', '住所B');

    expect(Order::query()->sole()->shipping_address_line1)->toBe('住所A')
        ->and($this->sessions->createCallCount())->toBe(1)
        ->and($this->balance->fresh()->reserved)->toBe(1);
});

test('any change to the order details or cart is a conflict', function (string $field, string $value) {
    checkoutWith($this);

    checkoutWith($this, [$field => $value])->assertSessionHas('status', CONFLICT_NOTICE);

    expect(Order::query()->count())->toBe(1);
})->with([
    'name' => ['customer_name', '別の名前'],
    'email' => ['customer_email', 'other@example.test'],
    'phone' => ['customer_phone', '0300000000'],
    'recipient' => ['shipping_recipient', '別の宛名'],
    'postal code' => ['shipping_postal_code', '1500001'],
    'region' => ['shipping_region', '東京都'],
    'city' => ['shipping_city', '渋谷区'],
    'second address line' => ['shipping_address_line2', '101号室'],
]);

test('a changed cart is a conflict, whichever key is used', function (string $key) {
    checkoutWith($this);
    CartItem::query()->update(['quantity' => 2]);

    checkoutWith($this, ['checkout_key' => str_repeat($key, 64)])->assertSessionHas('status', CONFLICT_NOTICE);

    expect(Order::query()->count())->toBe(1)
        ->and(Order::query()->sole()->items()->sole()->quantity)->toBe(1);
})->with(['same key' => 'a', 'new key' => 'b']);

test('the fingerprint stores no readable personal data', function () {
    checkoutWith($this);

    $fingerprint = Order::query()->sole()->checkout_fingerprint;
    expect($fingerprint)->toMatch('/\A[a-f0-9]{64}\z/')
        ->and($fingerprint)->not->toBe(hash('sha256', 'buyer@example.test'));
});

test('reloading the checkout page keeps the same key', function () {
    $first = hiddenCheckoutKey($this->get(route('shop.checkout.index')));

    expect(hiddenCheckoutKey($this->get(route('shop.checkout.index'))))->toBe($first);
});

test('a new key with the same details resumes the order instead of creating a second', function () {
    checkoutWith($this)->assertRedirect(PENDING_SESSION_URL.'cs_test_fake_1');

    checkoutWith($this, ['checkout_key' => str_repeat('b', 64)])->assertRedirect(PENDING_SESSION_URL.'cs_test_fake_1');

    expect(Order::query()->count())->toBe(1)
        ->and($this->balance->fresh()->reserved)->toBe(1)
        ->and($this->sessions->createCallCount())->toBe(1);
});

test('the database itself refuses a second active order for a cart', function () {
    checkoutWith($this);
    $order = Order::query()->sole();

    expect(fn () => Order::query()->create([
        ...$order->only([
            'customer_name', 'customer_email', 'shipping_recipient', 'shipping_country_code', 'shipping_postal_code',
            'shipping_city', 'shipping_address_line1', 'shipping_method_name', 'subtotal_minor', 'shipping_minor', 'total_minor',
        ]),
        'order_number' => 'ECSECOND0001',
        'idempotency_key' => str_repeat('c', 64),
        'status' => 'pending_payment',
        'payment_status' => 'pending',
        'active_cart_id' => $this->cart->id,
    ]))->toThrow(UniqueConstraintViolationException::class);
});

test('a closed key is replaced so the buyer cannot loop on the same error', function () {
    checkoutWith($this);
    app(CheckoutService::class)->releaseReservation(Order::query()->sole(), 'test');

    checkoutWith($this)->assertSessionHasErrors('checkout_key');

    $key = hiddenCheckoutKey($this->withSession(['_old_input' => $this->form])->get(route('shop.checkout.index')));
    expect($key)->not->toBe($this->form['checkout_key']);

    checkoutWith($this, ['checkout_key' => $key])->assertRedirect(PENDING_SESSION_URL.'cs_test_fake_2');
    expect(Order::query()->count())->toBe(2)
        ->and($this->balance->fresh()->reserved)->toBe(1);
});

test('the cart survives until payment and then loses only what was ordered', function () {
    checkoutWith($this);
    expect(CartItem::query()->sole()->quantity)->toBe(1);

    $other = ProductVariant::factory()->for(Product::factory()->create(['status' => 'published']))->create();
    CartItem::query()->create(['cart_id' => $this->cart->id, 'variant_id' => $other->id, 'quantity' => 2]);
    CartItem::query()->where('variant_id', $this->variant->id)->update(['quantity' => 3]);

    paidWebhook($this, Order::query()->sole());

    $order = Order::query()->sole();
    expect($order->status)->toBe('paid')
        ->and($order->active_cart_id)->toBeNull()
        ->and(CartItem::query()->where('variant_id', $this->variant->id)->sole()->quantity)->toBe(2)
        ->and(CartItem::query()->where('variant_id', $other->id)->sole()->quantity)->toBe(2);
});

test('the ordered line is removed when the quantities match', function () {
    checkoutWith($this);

    paidWebhook($this, Order::query()->sole());

    expect(CartItem::query()->count())->toBe(0);
});

test('a payment under review also clears the ordered items', function () {
    checkoutWith($this);

    paidWebhook($this, Order::query()->sole(), ['amount_total' => 1]);

    expect(Order::query()->sole()->status)->toBe('payment_review')
        ->and(Order::query()->sole()->active_cart_id)->toBeNull()
        ->and(CartItem::query()->count())->toBe(0);
});

test('an expired or cancelled order keeps the cart and frees it for a new order', function () {
    checkoutWith($this);

    $this->artisan('ecommerce:expire-reservations')->assertExitCode(0);
    $this->travel(33)->minutes();
    $this->artisan('ecommerce:expire-reservations')->assertExitCode(0);

    expect(Order::query()->sole()->status)->toBe('cancelled')
        ->and(Order::query()->sole()->active_cart_id)->toBeNull()
        ->and(CartItem::query()->sole()->quantity)->toBe(1);

    checkoutWith($this, ['checkout_key' => str_repeat('b', 64)])->assertRedirect();
    expect(Order::query()->count())->toBe(2);
});

test('the checkout page offers resume and cancel for a pending order', function () {
    checkoutWith($this);

    $this->get(route('shop.checkout.index'))
        ->assertOk()
        ->assertSee('お支払い待ちのご注文')
        ->assertSee(Order::query()->sole()->order_number)
        ->assertSee('¥1,500')
        ->assertSee(route('shop.checkout.pending.resume'))
        ->assertSee(route('shop.checkout.pending.cancel'))
        ->assertDontSee('name="checkout_key"', false);

    $this->get(route('shop.cart.index'))->assertOk()->assertSee('お支払い待ちのご注文');
});

test('returning from Stripe without paying leads back to the same session', function () {
    checkoutWith($this);

    $this->get(route('shop.checkout.cancel'))->assertRedirect(route('shop.checkout.index'));

    $this->post(route('shop.checkout.pending.resume'))->assertRedirect(PENDING_SESSION_URL.'cs_test_fake_1');

    expect($this->sessions->createCallCount())->toBe(1);
});

test('resume recovers an uncertain attempt with the saved request', function () {
    $this->sessions->loseResponseAfterStripeCreatesSession();
    checkoutWith($this)->assertRedirect(route('shop.checkout.index'));
    $this->travel(5)->seconds();

    $this->post(route('shop.checkout.pending.resume'))->assertRedirect(PENDING_SESSION_URL.'cs_test_fake_1');

    expect($this->sessions->sessionCount())->toBe(1);
});

test('resume with no pending order explains and creates nothing', function () {
    $this->post(route('shop.checkout.pending.resume'))
        ->assertRedirect(route('shop.checkout.index'))
        ->assertSessionHas('status', 'お支払い待ちのご注文はありません。');

    expect(Order::query()->count())->toBe(0);
});

test('resume sends a paid session to the status page without changing the order', function () {
    checkoutWith($this);
    $order = Order::query()->sole();
    $this->sessions->markPaid('cs_test_fake_1', 'pi_fake');

    $this->post(route('shop.checkout.pending.resume'))
        ->assertRedirect(route('shop.checkout.success', ['orderNumber' => $order->order_number]).'?session_id=cs_test_fake_1');
    expect(Order::query()->sole()->status)->toBe('pending_payment');
});

test('resume releases stock when Stripe says the session has expired', function () {
    checkoutWith($this);
    $this->sessions->expire('cs_test_fake_1');

    $this->post(route('shop.checkout.pending.resume'))->assertRedirect(route('shop.checkout.index'));

    expect(Order::query()->sole()->status)->toBe('cancelled')
        ->and($this->balance->fresh()->reserved)->toBe(0);
});

test('cancelling expires the open session, releases stock, keeps the cart, and allows a new order', function () {
    checkoutWith($this);

    $this->post(route('shop.checkout.pending.cancel'))
        ->assertRedirect(route('shop.checkout.index'))
        ->assertSessionHas('status', 'ご注文を取り消しました。在庫の予約を解除しています。カートの内容はそのまま残っています。');

    $order = Order::query()->sole();
    expect($this->sessions->retrieve('cs_test_fake_1')->status)->toBe('expired')
        ->and($order->status)->toBe('cancelled')
        ->and($order->active_cart_id)->toBeNull()
        ->and($this->balance->fresh()->reserved)->toBe(0)
        ->and(CartItem::query()->sole()->quantity)->toBe(1)
        ->and(OrderEvent::query()->where('event_type', 'customer_cancelled')->count())->toBe(1);

    checkoutWith($this, ['checkout_key' => str_repeat('b', 64), 'shipping_address_line1' => '住所B'])->assertRedirect();
    expect(Order::query()->where('status', 'pending_payment')->sole()->shipping_address_line1)->toBe('住所B');
});

test('an edited form survives the conflict and the cancel', function () {
    checkoutWith($this);
    checkoutWith($this, ['shipping_address_line1' => '住所B'])->assertSessionHas('status', CONFLICT_NOTICE);
    $this->get(route('shop.checkout.index'))->assertOk();

    $this->post(route('shop.checkout.pending.cancel'))->assertSessionHasInput('shipping_address_line1', '住所B');

    $this->get(route('shop.checkout.index'))
        ->assertSee('value="住所B"', false)
        ->assertDontSee('value="'.$this->form['checkout_key'].'"', false);
});

test('a paid session cannot be cancelled', function () {
    checkoutWith($this);
    $this->sessions->markPaid('cs_test_fake_1', 'pi_fake');

    $this->post(route('shop.checkout.pending.cancel'))
        ->assertSessionHas('status', 'このご注文はお支払いが完了している可能性があるため、取り消せませんでした。確認が済むまでお待ちください。');

    expect(Order::query()->sole()->status)->toBe('pending_payment')
        ->and($this->balance->fresh()->reserved)->toBe(1)
        ->and(OrderEvent::query()->count())->toBe(0);
});

test('nothing is cancelled when Stripe cannot confirm the session', function () {
    checkoutWith($this);
    Payment::query()->update(['provider_checkout_id' => 'cs_missing']);

    $this->post(route('shop.checkout.pending.cancel'))
        ->assertSessionHas('status', '決済の状況を確認できなかったため、ご注文を取り消せませんでした。時間をおいて再度お試しください。');

    expect(Order::query()->sole()->status)->toBe('pending_payment');
});

test('cancelling an uncertain attempt expires the session the lookup finds', function () {
    $this->sessions->loseResponseAfterStripeCreatesSession();
    checkoutWith($this);
    expect(Payment::query()->sole()->checkout_state)->toBe('uncertain');

    $this->post(route('shop.checkout.pending.cancel'))->assertRedirect(route('shop.checkout.index'));

    expect($this->sessions->retrieve('cs_test_fake_1')->status)->toBe('expired')
        ->and(Order::query()->sole()->status)->toBe('cancelled');
});

test('an uncertain attempt is not cancelled when the lookup fails', function () {
    $this->sessions->failBeforeStripeReceivesRequest();
    checkoutWith($this);
    $this->sessions->failNextList(new ApiConnectionException('Fake: lookup failed.'));

    $this->post(route('shop.checkout.pending.cancel'));

    expect(Order::query()->sole()->status)->toBe('pending_payment')
        ->and($this->balance->fresh()->reserved)->toBe(1);
});

test('a request in flight at Stripe blocks cancelling', function () {
    $this->sessions->failBeforeStripeReceivesRequest();
    checkoutWith($this);
    Payment::query()->update(['checkout_state' => 'creating', 'checkout_claimed_at' => now()]);

    $this->post(route('shop.checkout.pending.cancel'))
        ->assertSessionHas('status', '決済の状況を確認できなかったため、ご注文を取り消せませんでした。時間をおいて再度お試しください。');

    expect(Order::query()->sole()->status)->toBe('pending_payment');
});

test('another customer cannot resume or cancel the order', function () {
    checkoutWith($this);

    $this->actingAs(User::factory()->create());
    $this->post(route('shop.checkout.pending.cancel'))->assertSessionHas('status', 'お支払い待ちのご注文はありません。');
    $this->post(route('shop.checkout.pending.resume'))->assertSessionHas('status', 'お支払い待ちのご注文はありません。');

    expect(Order::query()->sole()->status)->toBe('pending_payment');
});

test('a guest cart is served by its own cookie', function () {
    auth()->logout();
    $token = str_repeat('c', 64);
    $guestCart = Cart::query()->create(['guest_token_hash' => hash('sha256', $token), 'expires_at' => now()->addDay()]);
    CartItem::query()->create(['cart_id' => $guestCart->id, 'variant_id' => $this->variant->id, 'quantity' => 1]);

    $this->withCookie('ec_cart', $token);
    checkoutWith($this)->assertRedirect(PENDING_SESSION_URL.'cs_test_fake_1');
    $this->post(route('shop.checkout.pending.resume'))->assertRedirect(PENDING_SESSION_URL.'cs_test_fake_1');

    expect(Order::query()->sole()->active_cart_id)->toBe($guestCart->id)
        ->and(Order::query()->sole()->user_id)->toBeNull();
});

test('reserveOrder reports a conflict from the service too', function () {
    $service = app(CheckoutService::class);
    $service->reserveOrder($this->cart, $this->form, $this->user);

    expect(fn () => $service->reserveOrder($this->cart, [...$this->form, 'shipping_city' => '渋谷区'], $this->user))
        ->toThrow(CheckoutConflictException::class);
});

test('removing ordered items tolerates a line that has already gone', function () {
    checkoutWith($this);
    $order = Order::query()->sole();
    CartItem::query()->delete();

    app(CartService::class)->removeOrderedItems($this->cart->id, $order);

    expect(CartItem::query()->count())->toBe(0);
});

test('the identity columns roll back cleanly', function () {
    $migration = require database_path('migrations/2026_11_05_140000_add_checkout_identity_to_ec_orders.php');

    $migration->down();
    expect(Schema::hasColumn('ec_orders', 'active_cart_id'))->toBeFalse();

    $migration->up();
    expect(Schema::hasColumn('ec_orders', 'active_cart_id'))->toBeTrue();
});
