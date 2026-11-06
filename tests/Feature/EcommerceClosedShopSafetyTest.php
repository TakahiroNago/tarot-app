<?php

use App\Mail\OrderConfirmationMail;
use App\Mail\OrderRefundCompletedMail;
use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Stripe\WebhookSignature;
use Tests\Support\Stripe\FakeStripeBillingService;

uses(RefreshDatabase::class);

beforeEach(function () {
    Mail::fake();
    config([
        'services.stripe.ecommerce_webhook_secret' => 'whsec_closed_shop',
        'services.stripe.ecommerce_secret' => 'sk_test_fake',
        'services.contact.email' => 'admin@example.test',
    ]);
});

/**
 * An order awaiting payment with its stock reserved, as checkout leaves it.
 *
 * @param  array<string, mixed>  $attributes
 * @return array{order: Order, payment: Payment, balance: InventoryBalance}
 */
function reservedOrder(array $attributes = []): array
{
    $product = Product::factory()->create(['status' => 'published']);
    $variant = ProductVariant::factory()->for($product)->create();
    $balance = InventoryBalance::factory()->for($variant, 'variant')->create(['on_hand' => 5, 'reserved' => 1]);
    $order = Order::query()->create([
        'order_number' => 'EC260101'.strtoupper(bin2hex(random_bytes(4))),
        'idempotency_key' => bin2hex(random_bytes(32)),
        'status' => 'pending_payment',
        'payment_status' => 'pending',
        'customer_name' => '購入者',
        'customer_email' => 'buyer@example.test',
        'shipping_recipient' => '宛名',
        'shipping_country_code' => 'JP',
        'shipping_postal_code' => '1000001',
        'shipping_city' => '千代田区',
        'shipping_address_line1' => '千代田1-1',
        'shipping_method_name' => '通常配送',
        'subtotal_minor' => 1000,
        'shipping_minor' => 500,
        'total_minor' => 1500,
        'currency' => 'JPY',
        'reservation_expires_at' => now()->addMinutes(30),
        ...$attributes,
    ]);
    OrderItem::query()->create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'variant_id' => $variant->id,
        'product_name_snapshot' => $product->name,
        'variant_label_snapshot' => $variant->label,
        'sku_snapshot' => $variant->sku,
        'unit_price_minor' => 1000,
        'quantity' => 1,
        'line_total_minor' => 1000,
        'currency' => 'JPY',
    ]);
    InventoryMovement::query()->create([
        'variant_id' => $variant->id,
        'order_id' => $order->id,
        'movement_type' => 'reservation',
        'reserved_delta' => 1,
        'idempotency_key' => InventoryService::movementKey('reservation', $order->id, $variant->id),
    ]);
    $payment = Payment::query()->create([
        'order_id' => $order->id,
        'provider' => 'stripe',
        'provider_checkout_id' => 'cs_'.$order->order_number,
        'checkout_state' => Payment::CHECKOUT_CREATED,
        'status' => 'pending',
        'amount_minor' => 1500,
        'currency' => 'JPY',
        'idempotency_key' => 'ec-'.$order->order_number,
    ]);

    return compact('order', 'payment', 'balance');
}

/**
 * @param  array<string, mixed>  $object
 */
function closedShopWebhook(mixed $test, string $type, array $object): mixed
{
    $payload = json_encode([
        'id' => 'evt_'.bin2hex(random_bytes(8)),
        'object' => 'event',
        'api_version' => '2025-02-24.acacia',
        'created' => now()->timestamp,
        'data' => ['object' => $object],
        'livemode' => false,
        'pending_webhooks' => 1,
        'type' => $type,
    ], JSON_THROW_ON_ERROR);

    return $test->call('POST', route('stripe.ecommerce.webhook'), server: [
        'HTTP_STRIPE_SIGNATURE' => WebhookSignature::generateSignatureHeader($payload, 'whsec_closed_shop'),
        'CONTENT_TYPE' => 'application/json',
    ], content: $payload);
}

/**
 * @param  array{order: Order, payment: Payment}  $scenario
 */
function payWhileClosed(mixed $test, array $scenario): mixed
{
    return closedShopWebhook($test, 'checkout.session.completed', [
        'id' => $scenario['payment']->provider_checkout_id,
        'object' => 'checkout.session',
        'status' => 'complete',
        'payment_status' => 'paid',
        'amount_total' => 1500,
        'currency' => 'jpy',
        'payment_intent' => 'pi_'.$scenario['order']->order_number,
        'client_reference_id' => $scenario['order']->order_number,
        'metadata' => ['ec_order_id' => (string) $scenario['order']->id, 'ec_payment_id' => (string) $scenario['payment']->id],
    ]);
}

test('a checkout paid after the shop closed settles fully and notifies the buyer', function () {
    $scenario = reservedOrder();
    config(['ecommerce.enabled' => false]);

    payWhileClosed($this, $scenario)->assertOk();

    expect($scenario['order']->fresh()->status)->toBe('paid')
        ->and($scenario['order']->fresh()->payment_status)->toBe('paid')
        ->and($scenario['balance']->fresh()->on_hand)->toBe(4)
        ->and($scenario['balance']->fresh()->reserved)->toBe(0);
    Mail::assertSent(OrderConfirmationMail::class, 1);
});

test('a refund completing while closed is recorded and the buyer is told', function () {
    $scenario = reservedOrder();
    payWhileClosed($this, $scenario);
    config(['ecommerce.enabled' => false]);

    closedShopWebhook($this, 'refund.updated', [
        'object' => 'refund',
        'payment_intent' => 'pi_'.$scenario['order']->order_number,
        'amount' => 1500,
        'currency' => 'jpy',
        'status' => 'succeeded',
    ])->assertOk();

    expect($scenario['order']->fresh()->status)->toBe('refunded');
    Mail::assertSent(OrderRefundCompletedMail::class, 1);
});

test('reconciliation still releases an abandoned reservation while closed', function () {
    $scenario = reservedOrder(['reservation_expires_at' => now()->subHour()]);
    $scenario['payment']->update(['checkout_state' => Payment::CHECKOUT_NOT_STARTED, 'provider_checkout_id' => null]);
    FakeStripeBillingService::install(app());
    config(['ecommerce.enabled' => false]);

    $this->artisan('ecommerce:expire-reservations')->assertSuccessful();

    expect($scenario['order']->fresh()->status)->toBe('cancelled')
        ->and($scenario['balance']->fresh()->reserved)->toBe(0);
});

test('an administrator can still ship a paid order while closed', function () {
    $scenario = reservedOrder();
    payWhileClosed($this, $scenario);
    config(['ecommerce.enabled' => false]);

    $this->actingAs(User::factory()->admin()->create())
        ->patch(route('admin.orders.shipment.update', $scenario['order']), [
            'status' => 'shipped',
            'carrier_name' => 'ヤマト運輸',
            'tracking_number' => 'A-1',
        ])
        ->assertRedirect(route('admin.orders.show', $scenario['order']));

    expect($scenario['order']->fresh()->status)->toBe('shipped');
});

test('a guest link keeps working, unchanged, while closed and after reopening', function () {
    $scenario = reservedOrder(['guest_access_expires_at' => now()->addDays(30)]);
    $url = app(CheckoutService::class)->guestOrderUrl($scenario['order']);
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    $scenario['order']->forceFill(['guest_access_token_hash' => hash('sha256', $query['signature'])])->save();
    payWhileClosed($this, $scenario);
    $hashBefore = $scenario['order']->fresh()->guest_access_token_hash;

    $this->get($url)->assertOk();

    config(['ecommerce.enabled' => false]);
    $this->get($url)->assertOk()->assertSee($scenario['order']->order_number);
    expect($scenario['order']->fresh()->guest_access_token_hash)->toBe($hashBefore);

    config(['ecommerce.enabled' => true]);
    $this->get($url)->assertOk()->assertSee($scenario['order']->order_number);
});

test('a member can see their order while closed and after reopening', function () {
    $user = User::factory()->create();
    $scenario = reservedOrder(['user_id' => $user->id]);
    payWhileClosed($this, $scenario);
    $this->actingAs($user);

    config(['ecommerce.enabled' => false]);
    $this->get(route('account.orders.show', $scenario['order']))->assertOk()->assertSee($scenario['order']->order_number);

    config(['ecommerce.enabled' => true]);
    $this->get(route('account.orders.show', $scenario['order']))
        ->assertOk()
        ->assertSee($scenario['order']->order_number);
    expect($scenario['order']->fresh()->payment_status)->toBe('paid');
});

test('the page a buyer returns to after paying shows the payment while closed', function () {
    $scenario = reservedOrder();
    config(['ecommerce.enabled' => false]);
    payWhileClosed($this, $scenario);

    $this->get(route('shop.checkout.success', ['orderNumber' => $scenario['order']->order_number]).'?session_id='.$scenario['payment']->provider_checkout_id)
        ->assertOk()
        ->assertSee('お支払いを確認しました')
        ->assertSee($scenario['order']->order_number);

    expect($scenario['order']->fresh()->payment_status)->toBe('paid');
});

test('a buyer cannot cancel or resume a pending order while closed, and it still expires', function () {
    $user = User::factory()->create();
    $scenario = reservedOrder(['user_id' => $user->id]);
    config(['ecommerce.enabled' => false]);

    $this->actingAs($user)->post(route('shop.checkout.pending.cancel'))->assertNotFound();
    $this->post(route('shop.checkout.pending.resume'))->assertNotFound();

    expect($scenario['order']->fresh()->status)->toBe('pending_payment');
});
