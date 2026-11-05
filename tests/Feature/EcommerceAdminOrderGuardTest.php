<?php

use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Stripe\WebhookSignature;

uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $attributes
 * @return array{order: Order, item: OrderItem, variant: ProductVariant, balance: InventoryBalance}
 */
function guardedOrder(array $attributes, int $quantity = 2): array
{
    $product = Product::factory()->create();
    $variant = ProductVariant::factory()->for($product)->create();
    $balance = InventoryBalance::factory()->for($variant, 'variant')->create(['on_hand' => 5, 'reserved' => 0]);
    $order = Order::query()->create([
        'order_number' => 'EC260101'.strtoupper(bin2hex(random_bytes(4))),
        'idempotency_key' => bin2hex(random_bytes(32)),
        'customer_name' => '購入者',
        'customer_email' => 'buyer@example.test',
        'shipping_recipient' => '宛名',
        'shipping_country_code' => 'JP',
        'shipping_postal_code' => '1000001',
        'shipping_city' => '千代田区',
        'shipping_address_line1' => '千代田1-1',
        'shipping_method_name' => '通常配送',
        'subtotal_minor' => 1000 * $quantity,
        'shipping_minor' => 500,
        'total_minor' => 1000 * $quantity + 500,
        'currency' => 'JPY',
        ...$attributes,
    ]);
    $item = OrderItem::query()->create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'variant_id' => $variant->id,
        'product_name_snapshot' => $product->name,
        'variant_label_snapshot' => $variant->label,
        'sku_snapshot' => $variant->sku,
        'unit_price_minor' => 1000,
        'quantity' => $quantity,
        'line_total_minor' => 1000 * $quantity,
        'currency' => 'JPY',
    ]);

    return compact('order', 'item', 'variant', 'balance');
}

function recordSale(Order $order, ProductVariant $variant, int $quantity): void
{
    InventoryMovement::query()->create([
        'variant_id' => $variant->id,
        'order_id' => $order->id,
        'movement_type' => 'sale',
        'on_hand_delta' => -$quantity,
        'reserved_delta' => -$quantity,
        'idempotency_key' => bin2hex(random_bytes(32)),
    ]);
}

/**
 * @return array<string, mixed>
 */
function restockForm(int $quantity, string $key = 'a'): array
{
    return ['quantity' => $quantity, 'reason' => '返品受領・検品済み', 'restock_key' => str_repeat($key, 64)];
}

test('orders outside the allowed statuses cannot have their shipment updated', function (string $status, string $paymentStatus) {
    ['order' => $order] = guardedOrder(['status' => $status, 'payment_status' => $paymentStatus]);

    $this->actingAs(User::factory()->admin()->create())
        ->patch(route('admin.orders.shipment.update', $order), ['status' => 'shipped'])
        ->assertUnprocessable();

    expect($order->fresh()->status)->toBe($status)
        ->and($order->shipments()->count())->toBe(0);
})->with([
    'payment review with a paid payment' => ['payment_review', 'paid'],
    'payment review pending a refund decision' => ['payment_review', 'payment_review'],
    'awaiting payment' => ['pending_payment', 'pending'],
    'cancelled' => ['cancelled', 'failed'],
    'refund pending' => ['paid', 'refund_pending'],
    'refunded' => ['refunded', 'refunded'],
]);

test('settled orders can progress through shipment', function (string $status) {
    ['order' => $order] = guardedOrder(['status' => $status, 'payment_status' => 'paid']);

    $this->actingAs(User::factory()->admin()->create())
        ->patch(route('admin.orders.shipment.update', $order), ['status' => 'delivered'])
        ->assertRedirect(route('admin.orders.show', $order));

    expect($order->fresh()->status)->toBe('completed');
})->with(['paid', 'processing', 'shipped', 'completed']);

test('a late payment cannot be shipped, which would erase its review state', function () {
    config(['services.stripe.ecommerce_webhook_secret' => 'whsec_guard_test']);
    ['order' => $order, 'balance' => $balance] = guardedOrder(['status' => 'cancelled', 'payment_status' => 'failed']);
    $payment = Payment::query()->create([
        'order_id' => $order->id,
        'provider' => 'stripe',
        'provider_checkout_id' => 'cs_guard_late',
        'status' => 'failed',
        'amount_minor' => $order->total_minor,
        'currency' => 'JPY',
        'idempotency_key' => 'ec-guard-late',
    ]);
    $payload = json_encode([
        'id' => 'evt_guard_late',
        'object' => 'event',
        'api_version' => '2025-02-24.acacia',
        'created' => now()->timestamp,
        'data' => ['object' => [
            'id' => 'cs_guard_late',
            'object' => 'checkout.session',
            'status' => 'complete',
            'payment_status' => 'paid',
            'amount_total' => $order->total_minor,
            'currency' => 'jpy',
            'client_reference_id' => $order->order_number,
            'payment_intent' => 'pi_guard_late',
            'metadata' => ['ec_order_id' => (string) $order->id, 'ec_payment_id' => (string) $payment->id],
        ]],
        'livemode' => false,
        'pending_webhooks' => 1,
        'type' => 'checkout.session.completed',
    ], JSON_THROW_ON_ERROR);
    $this->call('POST', route('stripe.ecommerce.webhook'), server: [
        'HTTP_STRIPE_SIGNATURE' => WebhookSignature::generateSignatureHeader($payload, 'whsec_guard_test'),
        'CONTENT_TYPE' => 'application/json',
    ], content: $payload)->assertOk();
    expect($order->fresh()->status)->toBe('payment_review');

    $this->actingAs(User::factory()->admin()->create())
        ->patch(route('admin.orders.shipment.update', $order), ['status' => 'shipped'])
        ->assertUnprocessable();

    expect($order->fresh()->status)->toBe('payment_review')
        ->and($order->shipments()->count())->toBe(0)
        ->and($balance->fresh()->on_hand)->toBe(5);
});

test('the order page hides the shipment form during review but keeps the refund form', function () {
    ['order' => $order] = guardedOrder(['status' => 'payment_review', 'payment_status' => 'paid']);
    Payment::query()->create([
        'order_id' => $order->id,
        'provider' => 'stripe',
        'provider_payment_id' => 'pi_guard_review',
        'status' => 'succeeded',
        'amount_minor' => $order->total_minor,
        'currency' => 'JPY',
        'idempotency_key' => 'ec-guard-review',
    ]);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.orders.show', $order))
        ->assertOk()
        ->assertDontSee(route('admin.orders.shipment.update', $order))
        ->assertSee(route('admin.orders.refund', $order));
});

test('the order page shows the shipment form for a settled order', function () {
    ['order' => $order] = guardedOrder(['status' => 'paid', 'payment_status' => 'paid']);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.orders.show', $order))
        ->assertOk()
        ->assertSee(route('admin.orders.shipment.update', $order));
});

test('a refunded order with no sale cannot add phantom stock', function () {
    ['order' => $order, 'item' => $item, 'balance' => $balance] = guardedOrder(['status' => 'refunded', 'payment_status' => 'refunded']);

    $this->actingAs(User::factory()->admin()->create())
        ->from(route('admin.orders.show', $order))
        ->post(route('admin.orders.items.restock', [$order, $item]), restockForm(1))
        ->assertRedirect(route('admin.orders.show', $order))
        ->assertSessionHasErrors('quantity');

    expect($balance->fresh()->on_hand)->toBe(5)
        ->and(InventoryMovement::query()->where('order_id', $order->id)->count())->toBe(0);
});

test('restocking is limited by the recorded sale across partial returns', function () {
    ['order' => $order, 'item' => $item, 'variant' => $variant, 'balance' => $balance] = guardedOrder(
        ['status' => 'refunded', 'payment_status' => 'refunded'],
        quantity: 3,
    );
    recordSale($order, $variant, 2);
    $this->actingAs(User::factory()->admin()->create());

    $this->post(route('admin.orders.items.restock', [$order, $item]), restockForm(3, 'a'))
        ->assertSessionHasErrors('quantity');
    expect($balance->fresh()->on_hand)->toBe(5);

    $this->post(route('admin.orders.items.restock', [$order, $item]), restockForm(1, 'b'))
        ->assertSessionHasNoErrors();
    $this->post(route('admin.orders.items.restock', [$order, $item]), restockForm(1, 'c'))
        ->assertSessionHasNoErrors();
    expect($balance->fresh()->on_hand)->toBe(7);

    $this->post(route('admin.orders.items.restock', [$order, $item]), restockForm(1, 'd'))
        ->assertSessionHasErrors('quantity');

    expect($balance->fresh()->on_hand)->toBe(7)
        ->and(InventoryMovement::query()->where('order_id', $order->id)->where('movement_type', 'return_restock')->count())->toBe(2);
});

test('a sale for another order or variant does not permit a restock', function () {
    ['order' => $order, 'item' => $item, 'variant' => $variant, 'balance' => $balance] = guardedOrder(['status' => 'refunded', 'payment_status' => 'refunded']);
    ['order' => $otherOrder, 'variant' => $otherVariant] = guardedOrder(['status' => 'paid', 'payment_status' => 'paid']);
    recordSale($otherOrder, $variant, 2);
    recordSale($order, $otherVariant, 2);

    $this->actingAs(User::factory()->admin()->create())
        ->post(route('admin.orders.items.restock', [$order, $item]), restockForm(1))
        ->assertSessionHasErrors('quantity');

    expect($balance->fresh()->on_hand)->toBe(5);
});

test('replaying a restock with its key still succeeds without adding stock twice', function () {
    ['order' => $order, 'item' => $item, 'variant' => $variant, 'balance' => $balance] = guardedOrder(['status' => 'refunded', 'payment_status' => 'refunded']);
    recordSale($order, $variant, 2);
    $this->actingAs(User::factory()->admin()->create());

    $this->post(route('admin.orders.items.restock', [$order, $item]), restockForm(2))->assertSessionHasNoErrors();
    $this->post(route('admin.orders.items.restock', [$order, $item]), restockForm(2))->assertSessionHasNoErrors();

    expect($balance->fresh()->on_hand)->toBe(7);
});

test('the refunded order page only offers a restock backed by a sale', function () {
    $admin = User::factory()->admin()->create();
    ['order' => $unsold, 'item' => $unsoldItem] = guardedOrder(['status' => 'refunded', 'payment_status' => 'refunded']);
    ['order' => $sold, 'item' => $soldItem, 'variant' => $soldVariant] = guardedOrder(['status' => 'refunded', 'payment_status' => 'refunded']);
    recordSale($sold, $soldVariant, 1);

    $this->actingAs($admin)->get(route('admin.orders.show', $unsold))
        ->assertOk()
        ->assertSee('販売確定の記録がないため')
        ->assertDontSee(route('admin.orders.items.restock', [$unsold, $unsoldItem]));

    $this->get(route('admin.orders.show', $sold))
        ->assertOk()
        ->assertDontSee('販売確定の記録がないため')
        ->assertSee(route('admin.orders.items.restock', [$sold, $soldItem]))
        ->assertSee('max="1"', false);
});
