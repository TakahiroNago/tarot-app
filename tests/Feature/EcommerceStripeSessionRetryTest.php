<?php

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\InventoryBalance;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ShippingMethod;
use App\Models\ShippingRate;
use App\Models\TaxRate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\AuthenticationException;
use Stripe\Exception\IdempotencyException;
use Stripe\Exception\InvalidRequestException;
use Stripe\Exception\PermissionException;
use Stripe\Exception\RateLimitException;
use Tests\Support\Stripe\FakeStripeBillingService;
use Tests\Support\Stripe\FakeStripeCheckoutSessions;

uses(RefreshDatabase::class);

const SESSION_URL = 'https://checkout.stripe.test/pay/';

const STILL_OPEN_NOTICE = '決済の準備状況を確認できませんでした。入力内容と注文情報は保持されています。時間をおいて同じ画面から再試行してください。在庫予約は有効期限まで維持されます。';

const ORDER_CLOSED_NOTICE = '決済を開始できなかったため、ご注文を取り消しました。在庫の予約は解除しています。入力内容をご確認のうえ、もう一度お試しください。';

const EXPIRY_REJECTION = 'The `expires_at` timestamp must be at least 30 minutes from Checkout Session creation.';

beforeEach(function () {
    $this->freezeTime();
    $this->sessions = FakeStripeBillingService::install(app());

    $user = User::factory()->create();
    $this->cart = Cart::query()->create(['user_id' => $user->id]);
    $variant = ProductVariant::factory()
        ->for(Product::factory()->create(['status' => 'published']))
        ->create(['price_minor' => 1000]);
    $this->balance = InventoryBalance::factory()->for($variant, 'variant')->create(['on_hand' => 5, 'reserved' => 0]);
    CartItem::query()->create(['cart_id' => $this->cart->id, 'variant_id' => $variant->id, 'quantity' => 1]);
    $method = ShippingMethod::query()->create(['code' => 'standard', 'name' => '通常配送', 'is_active' => true]);
    $rate = ShippingRate::query()->create([
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
        'shipping_address_line1' => '千代田1-1',
        'shipping_rate_id' => $rate->id,
    ];
    $this->actingAs($user);
    $this->withoutMiddleware(ThrottleRequests::class);
});

function submitCheckout(mixed $test): mixed
{
    return $test->post(route('shop.checkout.store'), $test->form);
}

function expectStillOpen(mixed $response): void
{
    $response->assertRedirect(route('shop.checkout.index'))
        ->assertSessionHas('status', STILL_OPEN_NOTICE)
        ->assertSessionHasInput('checkout_key');
}

function storedPayment(): Payment
{
    return Payment::query()->sole();
}

function seedOrphanSession(FakeStripeCheckoutSessions $sessions, int $paymentId): string
{
    return $sessions->create([
        'mode' => 'payment',
        'expires_at' => now()->addMinutes(30)->timestamp,
        'line_items' => [['price_data' => ['currency' => 'jpy', 'unit_amount' => 1500], 'quantity' => 1]],
        'metadata' => ['ec_payment_id' => (string) $paymentId],
    ], ['idempotency_key' => 'orphan-'.$paymentId])->id;
}

test('the first request succeeds and leaves no saved request behind', function () {
    submitCheckout($this)->assertRedirect(SESSION_URL.'cs_test_fake_1');

    $payment = storedPayment();
    expect($payment->checkout_state)->toBe('created')
        ->and($payment->checkout_attempt)->toBe(1)
        ->and($payment->provider_checkout_id)->toBe('cs_test_fake_1')
        ->and($payment->checkout_request_payload)->toBeNull()
        ->and($payment->idempotency_key)->toBe('ec-'.hash('sha256', $this->form['checkout_key']))
        ->and($this->sessions->createCalls[0]['params']['metadata']['ec_checkout_attempt'])->toBe('1')
        ->and(Order::query()->sole()->reservation_expires_at->timestamp)->toBe($this->sessions->createCalls[0]['params']['expires_at']);
});

test('a lost response is recovered by resending the identical request, even when its expiry has aged', function (int $secondsLater) {
    $this->sessions->loseResponseAfterStripeCreatesSession();

    expectStillOpen(submitCheckout($this));
    $this->travel($secondsLater)->seconds();
    submitCheckout($this)->assertRedirect(SESSION_URL.'cs_test_fake_1');

    [$first, $second] = $this->sessions->createCalls;
    expect($second['params'])->toBe($first['params'])
        ->and($second['idempotency_key'])->toBe($first['idempotency_key'])
        ->and($this->sessions->sessionCount())->toBe(1)
        ->and(storedPayment()->provider_checkout_id)->toBe('cs_test_fake_1')
        ->and(Order::query()->count())->toBe(1)
        ->and($this->balance->fresh()->reserved)->toBe(1);
})->with(['ten seconds' => 10, 'past the thirty minute window' => 45]);

test('a request that never arrived is sent again unchanged', function () {
    $this->sessions->failBeforeStripeReceivesRequest();

    expectStillOpen(submitCheckout($this));
    $this->travel(10)->seconds();
    submitCheckout($this)->assertRedirect(SESSION_URL.'cs_test_fake_1');

    expect($this->sessions->createCalls[1]['params'])->toBe($this->sessions->createCalls[0]['params'])
        ->and($this->sessions->sessionCount())->toBe(1);
});

test('the saved request is encrypted because it holds the email address', function () {
    $this->sessions->failBeforeStripeReceivesRequest();
    submitCheckout($this);

    $raw = DB::table('ec_payments')->value('checkout_request_payload');
    expect($raw)->not->toContain('buyer@example.test')
        ->and(storedPayment()->checkout_request_payload['customer_email'])->toBe('buyer@example.test');
});

test('a rejected expiry starts a new attempt with a new key and expiry', function () {
    $this->sessions->failBeforeStripeReceivesRequest();
    expectStillOpen(submitCheckout($this));
    $this->sessions->rejectInvalidRequest(EXPIRY_REJECTION);
    $this->travel(45)->seconds();

    submitCheckout($this)->assertRedirect(SESSION_URL.'cs_test_fake_1');

    $calls = $this->sessions->createCalls;
    $last = end($calls);
    $payment = storedPayment();
    expect($last['idempotency_key'])->toBe($calls[0]['idempotency_key'].'-a2')
        ->and($last['params']['expires_at'])->toBeGreaterThan($calls[0]['params']['expires_at'])
        ->and($last['params']['metadata']['ec_checkout_attempt'])->toBe('2')
        ->and($payment->checkout_attempt)->toBe(2)
        ->and($payment->idempotency_key)->toBe($last['idempotency_key'])
        ->and($this->sessions->sessionCount())->toBe(1)
        ->and(Order::query()->sole()->reservation_expires_at->timestamp)->toBe($last['params']['expires_at']);
});

test('before a new attempt an existing session is adopted rather than duplicated', function () {
    $this->sessions->failBeforeStripeReceivesRequest();
    expectStillOpen(submitCheckout($this));
    $orphan = seedOrphanSession($this->sessions, storedPayment()->id);
    $this->sessions->rejectInvalidRequest(EXPIRY_REJECTION);

    submitCheckout($this)->assertRedirect(SESSION_URL.$orphan);

    expect($this->sessions->sessionCount())->toBe(1)
        ->and(storedPayment()->provider_checkout_id)->toBe($orphan)
        ->and(storedPayment()->checkout_attempt)->toBe(1);
});

test('sessions for another payment are never adopted', function () {
    $this->sessions->failBeforeStripeReceivesRequest();
    expectStillOpen(submitCheckout($this));
    $other = seedOrphanSession($this->sessions, storedPayment()->id + 1);
    $this->sessions->rejectInvalidRequest(EXPIRY_REJECTION);

    submitCheckout($this)->assertRedirect(SESSION_URL.'cs_test_fake_2');

    expect(storedPayment()->provider_checkout_id)->not->toBe($other);
});

test('extra open sessions are expired when one is adopted, and a paid one wins', function () {
    $this->sessions->failBeforeStripeReceivesRequest();
    expectStillOpen(submitCheckout($this));
    $paymentId = storedPayment()->id;
    $open = seedOrphanSession($this->sessions, $paymentId);
    $paid = $this->sessions->create([
        'mode' => 'payment',
        'expires_at' => now()->addMinutes(30)->timestamp,
        'line_items' => [['price_data' => ['currency' => 'jpy', 'unit_amount' => 1500], 'quantity' => 1]],
        'metadata' => ['ec_payment_id' => (string) $paymentId],
    ], ['idempotency_key' => 'paid-orphan'])->id;
    $this->sessions->markPaid($paid, 'pi_fake');
    $this->sessions->rejectInvalidRequest(EXPIRY_REJECTION);

    submitCheckout($this);

    expect(storedPayment()->provider_checkout_id)->toBe($paid)
        ->and($this->sessions->retrieve($open)->status)->toBe('expired');
});

test('an unknown result is not replaced by a new attempt until it has been looked up and has aged', function () {
    $this->sessions->failWithStoredServerError();

    expectStillOpen(submitCheckout($this));
    expect(storedPayment()->checkout_state)->toBe('uncertain');

    $this->travel(10)->seconds();
    expectStillOpen(submitCheckout($this));
    expect(storedPayment()->checkout_state)->toBe('uncertain')
        ->and(storedPayment()->checkout_attempt)->toBe(1);

    $this->travel(61)->seconds();
    expectStillOpen(submitCheckout($this));
    expect(storedPayment()->checkout_state)->toBe('needs_new_attempt');

    submitCheckout($this)->assertRedirect(SESSION_URL.'cs_test_fake_1');
    expect(storedPayment()->checkout_attempt)->toBe(2)
        ->and($this->sessions->sessionCount())->toBe(1);
});

test('a stored server error adopts a session the lookup finds', function () {
    $this->sessions->failWithStoredServerError();
    expectStillOpen(submitCheckout($this));
    $orphan = seedOrphanSession($this->sessions, storedPayment()->id);

    submitCheckout($this)->assertRedirect(SESSION_URL.$orphan);

    expect($this->sessions->sessionCount())->toBe(1);
});

test('a failed lookup leaves the attempt untouched', function () {
    $this->sessions->failBeforeStripeReceivesRequest();
    expectStillOpen(submitCheckout($this));
    $this->sessions->rejectInvalidRequest(EXPIRY_REJECTION)
        ->failNextList(new ApiConnectionException('Fake: lookup failed.'));

    expectStillOpen(submitCheckout($this));

    expect(storedPayment()->checkout_attempt)->toBe(1)
        ->and(storedPayment()->checkout_state)->toBe('uncertain')
        ->and($this->sessions->sessionCount())->toBe(0);
});

test('a request still running at Stripe is waited for with the same key', function () {
    $this->sessions->failWithKeyInUse();

    expectStillOpen(submitCheckout($this));
    expect(storedPayment()->checkout_state)->toBe('uncertain')
        ->and($this->sessions->sessionCount())->toBe(0);

    submitCheckout($this)->assertRedirect(SESSION_URL.'cs_test_fake_1');

    expect($this->sessions->createCalls[1]['idempotency_key'])->toBe($this->sessions->createCalls[0]['idempotency_key'])
        ->and(storedPayment()->checkout_attempt)->toBe(1);
});

test('rate limiting keeps the order open', function () {
    $this->sessions->failWith(RateLimitException::factory('Fake: rate limited.', 429));

    expectStillOpen(submitCheckout($this));

    expect(Order::query()->sole()->status)->toBe('pending_payment');
});

test('an idempotency conflict never creates another session', function () {
    $this->sessions->failWith(IdempotencyException::factory('Fake: parameters differ.', 400));

    expectStillOpen(submitCheckout($this));

    expect($this->sessions->sessionCount())->toBe(0)
        ->and(storedPayment()->checkout_attempt)->toBe(1)
        ->and(storedPayment()->checkout_state)->toBe('uncertain');
});

test('a definite refusal cancels the order, releases stock, and keeps the input without the key', function (Closure $refusal) {
    $this->sessions->failWith($refusal());

    submitCheckout($this)
        ->assertRedirect(route('shop.checkout.index'))
        ->assertSessionHas('status', ORDER_CLOSED_NOTICE)
        ->assertSessionHasInput('customer_name', '購入者')
        ->assertSessionMissing('_old_input.checkout_key');

    $order = Order::query()->sole();
    expect($order->status)->toBe('cancelled')
        ->and(storedPayment()->checkout_state)->toBe('failed')
        ->and(storedPayment()->checkout_request_payload)->toBeNull()
        ->and($this->balance->fresh()->reserved)->toBe(0)
        ->and(CartItem::query()->count())->toBe(1)
        ->and($this->sessions->sessionCount())->toBe(0);
})->with([
    'invalid request' => [fn () => InvalidRequestException::factory('Fake: amount too small.', 400)],
    'authentication' => [fn () => AuthenticationException::factory('Fake: bad key.', 401)],
    'permission' => [fn () => PermissionException::factory('Fake: forbidden.', 403)],
]);

test('a new checkout works after a definite refusal', function () {
    $this->sessions->failWith(AuthenticationException::factory('Fake: bad key.', 401));
    submitCheckout($this)->assertSessionHas('status', ORDER_CLOSED_NOTICE);

    $this->form['checkout_key'] = str_repeat('b', 64);
    submitCheckout($this)->assertRedirect(SESSION_URL.'cs_test_fake_1');

    expect(Order::query()->count())->toBe(2)
        ->and($this->balance->fresh()->reserved)->toBe(1);
});

test('a second expiry rejection on a fresh attempt is treated as a refusal', function () {
    $this->sessions->rejectInvalidRequest(EXPIRY_REJECTION)->rejectInvalidRequest(EXPIRY_REJECTION);

    submitCheckout($this)->assertSessionHas('status', ORDER_CLOSED_NOTICE);

    expect(Order::query()->sole()->status)->toBe('cancelled');
});

test('a concurrent request does not call Stripe while a claim is live, and a stale claim is taken over', function () {
    $this->sessions->failBeforeStripeReceivesRequest();
    expectStillOpen(submitCheckout($this));
    storedPayment()->update(['checkout_state' => 'creating', 'checkout_claimed_at' => now()]);
    $calls = $this->sessions->createCallCount();

    $this->travel(119)->seconds();
    expectStillOpen(submitCheckout($this));
    expect($this->sessions->createCallCount())->toBe($calls);

    $this->travel(2)->seconds();
    submitCheckout($this)->assertRedirect(SESSION_URL.'cs_test_fake_1');
    expect($this->sessions->createCallCount())->toBe($calls + 1);
});

test('a session created for an order cancelled mid-request is expired and never offered', function () {
    $this->sessions->duringNextCreate(fn () => Order::query()->update(['status' => 'cancelled', 'payment_status' => 'failed']));

    submitCheckout($this)
        ->assertRedirect(route('shop.checkout.index'))
        ->assertSessionHas('status', ORDER_CLOSED_NOTICE);

    expect($this->sessions->retrieve('cs_test_fake_1')->status)->toBe('expired')
        ->and(storedPayment()->provider_checkout_id)->toBeNull();
});

test('new attempts stop at three, leaving the reservation to its expiry', function () {
    $this->sessions->failBeforeStripeReceivesRequest();
    expectStillOpen(submitCheckout($this));
    storedPayment()->update(['checkout_attempt' => 3, 'checkout_state' => 'needs_new_attempt']);
    $calls = $this->sessions->createCallCount();

    expectStillOpen(submitCheckout($this));
    expectStillOpen(submitCheckout($this));

    expect(storedPayment()->checkout_state)->toBe('exhausted')
        ->and($this->sessions->createCallCount())->toBe($calls)
        ->and(Order::query()->sole()->status)->toBe('pending_payment');
});

test('no new attempt may run past sixty minutes from the first order', function () {
    $this->sessions->failBeforeStripeReceivesRequest();
    expectStillOpen(submitCheckout($this));
    storedPayment()->update(['checkout_state' => 'needs_new_attempt']);
    Order::query()->update(['reservation_expires_at' => now()->addHours(2)]);
    $this->travel(30)->minutes();

    expectStillOpen(submitCheckout($this));

    expect(storedPayment()->checkout_state)->toBe('exhausted')
        ->and($this->sessions->createCallCount())->toBe(1);
});

test('the expiry job releases stock after an unresolved attempt expires, and not before', function () {
    $this->sessions->failBeforeStripeReceivesRequest();
    expectStillOpen(submitCheckout($this));

    $this->travel(30)->minutes();
    $this->artisan('ecommerce:expire-reservations')->assertExitCode(0);
    expect(Order::query()->sole()->status)->toBe('pending_payment')
        ->and($this->balance->fresh()->reserved)->toBe(1);

    $this->travel(151)->seconds();
    $this->artisan('ecommerce:expire-reservations')->assertExitCode(0);
    expect(Order::query()->sole()->status)->toBe('cancelled')
        ->and($this->balance->fresh()->reserved)->toBe(0);
});

test('a session offering more than cards is expired and the order is cancelled', function (array $methods) {
    $this->sessions->offering($methods);

    submitCheckout($this)
        ->assertRedirect(route('shop.checkout.index'))
        ->assertSessionHas('status', ORDER_CLOSED_NOTICE);

    expect($this->sessions->retrieve('cs_test_fake_1')->status)->toBe('expired')
        ->and(storedPayment()->provider_checkout_id)->toBeNull()
        ->and(storedPayment()->checkout_state)->toBe('failed')
        ->and(Order::query()->sole()->status)->toBe('cancelled')
        ->and($this->balance->fresh()->reserved)->toBe(0)
        ->and(CartItem::query()->count())->toBe(1);
})->with([
    'card and link, as the account offers today' => [['card', 'link']],
    'no card at all' => [['link']],
    'nothing reported' => [[]],
]);

test('an open orphan session offering more than cards is not adopted', function () {
    $this->sessions->failBeforeStripeReceivesRequest();
    expectStillOpen(submitCheckout($this));
    $this->sessions->offering(['card', 'link']);
    $orphan = seedOrphanSession($this->sessions, storedPayment()->id);
    $this->sessions->rejectInvalidRequest(EXPIRY_REJECTION);

    submitCheckout($this)->assertSessionHas('status', ORDER_CLOSED_NOTICE);

    expect($this->sessions->retrieve($orphan)->status)->toBe('expired')
        ->and(Order::query()->sole()->status)->toBe('cancelled');
});

test('a paid session is still recorded whatever methods it offered', function () {
    $this->sessions->failBeforeStripeReceivesRequest();
    expectStillOpen(submitCheckout($this));
    $this->sessions->offering(['card', 'link']);
    $paid = seedOrphanSession($this->sessions, storedPayment()->id);
    $this->sessions->markPaid($paid, 'pi_fake');
    $this->sessions->rejectInvalidRequest(EXPIRY_REJECTION);

    submitCheckout($this);

    expect(storedPayment()->provider_checkout_id)->toBe($paid)
        ->and(Order::query()->sole()->status)->toBe('pending_payment');
});

test('the attempt columns roll back cleanly', function () {
    $migration = require database_path('migrations/2026_11_05_130000_add_checkout_attempt_to_ec_payments.php');

    $migration->down();
    expect(Schema::hasColumn('ec_payments', 'checkout_request_payload'))->toBeFalse();

    $migration->up();
    expect(Schema::hasColumn('ec_payments', 'checkout_request_payload'))->toBeTrue();
});

test('a saved session that cannot be fetched does not create another', function () {
    submitCheckout($this)->assertRedirect(SESSION_URL.'cs_test_fake_1');
    storedPayment()->update(['provider_checkout_id' => 'cs_missing']);

    expectStillOpen(submitCheckout($this));

    expect($this->sessions->createCallCount())->toBe(1);
});

test('a cancelled order with a saved session reaches the existing not-resumable error', function () {
    submitCheckout($this)->assertRedirect(SESSION_URL.'cs_test_fake_1');
    Order::query()->update(['status' => 'cancelled']);

    submitCheckout($this)->assertSessionHasErrors('checkout_key');

    expect($this->sessions->createCallCount())->toBe(1);
});
