<?php

use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\ProductVariant;
use App\Models\User;
use Tests\Support\Concurrency\CheckoutScenario;
use Tests\Support\Concurrency\ConcurrencyHarness;
use Tests\Support\Concurrency\ConcurrencyTestCase;

uses(ConcurrencyTestCase::class);

beforeEach(function () {
    ConcurrencyHarness::rebuildSchema();
});

function latePaymentUnderReview(ProductVariant $variant, string $orderNumber): Order
{
    $order = Order::query()->create([
        'order_number' => $orderNumber,
        'idempotency_key' => bin2hex(random_bytes(32)),
        'status' => 'payment_review',
        'payment_status' => 'payment_review',
        'review_reason' => Order::REVIEW_LATE_PAYMENT,
        'review_opened_at' => now(),
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
    ]);
    OrderItem::query()->create([
        'order_id' => $order->id,
        'product_id' => $variant->product_id,
        'variant_id' => $variant->id,
        'product_name_snapshot' => 'Deck',
        'variant_label_snapshot' => '標準',
        'sku_snapshot' => $variant->sku,
        'unit_price_minor' => 1000,
        'quantity' => 1,
        'line_total_minor' => 1000,
        'currency' => 'JPY',
    ]);
    Payment::query()->create([
        'order_id' => $order->id,
        'provider' => 'stripe',
        'provider_payment_id' => 'pi_'.$orderNumber,
        'status' => 'succeeded',
        'amount_minor' => 1500,
        'received_amount_minor' => 1500,
        'currency' => 'JPY',
        'received_currency' => 'JPY',
        'idempotency_key' => 'ec-'.$orderNumber,
    ]);

    return $order;
}

test('approving the same review from eight requests sells once', function () {
    $variant = CheckoutScenario::stockedVariant(5);
    $order = latePaymentUnderReview($variant, 'ECREVIEWSAME0001');
    $payload = ['order_id' => $order->id, 'actor_id' => User::factory()->admin()->create()->id];

    $counts = CheckoutScenario::outcomeCounts(ConcurrencyHarness::run('approve-review', array_fill(0, 8, $payload)), 'same review approved x8');

    expect($counts['approved'])->toBe(1)
        ->and($counts['rejected'])->toBe(7)
        ->and($counts['deadlock'] + $counts['error'])->toBe(0)
        ->and($order->fresh()->status)->toBe('paid')
        ->and(InventoryMovement::query()->where('movement_type', 'sale')->count())->toBe(1)
        ->and(InventoryBalance::query()->findOrFail($variant->id)->on_hand)->toBe(4)
        ->and(ConcurrencyHarness::inventoryInvariantViolations())->toBe([]);
});

test('approvals competing for the last unit sell it once', function () {
    $variant = CheckoutScenario::stockedVariant(1);
    $actor = User::factory()->admin()->create();
    $payloads = array_map(
        fn (int $index): array => ['order_id' => latePaymentUnderReview($variant, "ECREVIEWLAST{$index}")->id, 'actor_id' => $actor->id],
        range(0, 7),
    );

    $counts = CheckoutScenario::outcomeCounts(ConcurrencyHarness::run('approve-review', $payloads), 'reviews competing for the last unit');

    expect($counts['approved'])->toBe(1)
        ->and($counts['rejected'])->toBe(7)
        ->and($counts['deadlock'] + $counts['error'])->toBe(0)
        ->and(Order::query()->where('status', 'paid')->count())->toBe(1)
        ->and(Order::query()->where('status', 'payment_review')->count())->toBe(7)
        ->and(InventoryBalance::query()->findOrFail($variant->id)->on_hand)->toBe(0)
        ->and(ConcurrencyHarness::inventoryInvariantViolations())->toBe([]);
});
