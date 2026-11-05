<?php

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\ShippingRate;
use App\Models\TaxRate;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Support\Concurrency\CheckoutScenario;
use Tests\Support\Concurrency\ConcurrencyHarness;
use Tests\Support\Concurrency\ConcurrencyTestCase;
use Tests\Support\Concurrency\SharedDatabaseStripeSessions;

uses(ConcurrencyTestCase::class);

beforeEach(function () {
    ConcurrencyHarness::rebuildSchema();
});

test('eight orders for the same plentiful product all reserve without a deadlock', function () {
    $rate = CheckoutScenario::shippingRate();
    $variant = CheckoutScenario::stockedVariant(100);
    $payloads = array_map(
        fn (int $index): array => CheckoutScenario::reservePayload(CheckoutScenario::guestCart($variant), $rate, "same-product-{$index}"),
        range(0, 7),
    );

    $results = ConcurrencyHarness::run('reserve-order', $payloads);

    expect(CheckoutScenario::outcomeCounts($results, 'stock 100, same product'))->toBe(CheckoutScenario::expectedCounts(['reserved' => 8]))
        ->and(Order::query()->count())->toBe(8)
        ->and(InventoryBalance::query()->findOrFail($variant->id)->reserved)->toBe(8)
        ->and(CheckoutScenario::orderViolations())->toBe([])
        ->and(ConcurrencyHarness::inventoryInvariantViolations())->toBe([]);
});

test('eight orders for different products all reserve without a deadlock', function () {
    $rate = CheckoutScenario::shippingRate();
    $payloads = array_map(
        fn (int $index): array => CheckoutScenario::reservePayload(
            CheckoutScenario::guestCart(CheckoutScenario::stockedVariant(100)),
            $rate,
            "different-product-{$index}",
        ),
        range(0, 7),
    );

    $results = ConcurrencyHarness::run('reserve-order', $payloads);

    expect(CheckoutScenario::outcomeCounts($results, 'stock 100, different products'))->toBe(CheckoutScenario::expectedCounts(['reserved' => 8]))
        ->and(Order::query()->count())->toBe(8)
        ->and(InventoryBalance::query()->pluck('reserved')->unique()->all())->toBe([1])
        ->and(CheckoutScenario::orderViolations())->toBe([])
        ->and(ConcurrencyHarness::inventoryInvariantViolations())->toBe([]);
});

test('the same key from eight requests resolves to one order', function (int $stock) {
    $rate = CheckoutScenario::shippingRate();
    $variant = CheckoutScenario::stockedVariant($stock);
    $cart = CheckoutScenario::guestCart($variant);

    $results = ConcurrencyHarness::run(
        'reserve-order',
        array_fill(0, 8, CheckoutScenario::reservePayload($cart, $rate, 'same-key')),
    );

    expect(CheckoutScenario::outcomeCounts($results, "same key, stock {$stock}"))->toBe(CheckoutScenario::expectedCounts(['reserved' => 8]))
        ->and(array_values(array_unique(array_column($results, 'order_id'))))->toBe([Order::query()->sole()->id])
        ->and(InventoryBalance::query()->findOrFail($variant->id)->reserved)->toBe(1)
        ->and(InventoryMovement::query()->where('movement_type', 'reservation')->count())->toBe(1)
        ->and(Payment::query()->count())->toBe(1)
        ->and(CheckoutScenario::orderViolations())->toBe([])
        ->and(ConcurrencyHarness::inventoryInvariantViolations())->toBe([]);
})->with(['plentiful stock' => 100, 'last unit' => 1]);

test('orders placed alongside existing orders do not deadlock', function (int $existingOrders) {
    CheckoutScenario::seedExistingOrders($existingOrders);
    $rate = CheckoutScenario::shippingRate();
    $variant = CheckoutScenario::stockedVariant(100);
    $payloads = array_map(
        fn (int $index): array => CheckoutScenario::reservePayload(CheckoutScenario::guestCart($variant), $rate, "existing-{$existingOrders}-{$index}"),
        range(0, 7),
    );

    $results = ConcurrencyHarness::run('reserve-order', $payloads);

    expect(CheckoutScenario::outcomeCounts($results, "{$existingOrders} existing orders"))->toBe(CheckoutScenario::expectedCounts(['reserved' => 8]))
        ->and(CheckoutScenario::orderViolations())->toBe([])
        ->and(ConcurrencyHarness::inventoryInvariantViolations())->toBe([]);
})->with([10, 100]);

test('an injected deadlock is retried by InnoDB without duplicating any row', function () {
    $rate = CheckoutScenario::shippingRate();
    $variant = CheckoutScenario::stockedVariant(100);
    $payloads = array_map(
        fn (int $index): array => [
            'deadlocks' => 2,
            ...CheckoutScenario::reservePayload(CheckoutScenario::guestCart($variant), $rate, "injected-{$index}"),
        ],
        range(0, 7),
    );

    $results = ConcurrencyHarness::run('reserve-order-with-injected-deadlocks', $payloads);

    expect(CheckoutScenario::outcomeCounts($results, 'two injected deadlocks each'))->toBe(CheckoutScenario::expectedCounts(['reserved' => 8]))
        ->and(Order::query()->count())->toBe(8)
        ->and(OrderItem::query()->count())->toBe(8)
        ->and(Payment::query()->count())->toBe(8)
        ->and(InventoryMovement::query()->where('movement_type', 'reservation')->count())->toBe(8)
        ->and(InventoryBalance::query()->findOrFail($variant->id)->reserved)->toBe(8)
        ->and(CheckoutScenario::orderViolations())->toBe([])
        ->and(ConcurrencyHarness::inventoryInvariantViolations())->toBe([]);
});

test('exhausting the retries leaves nothing behind in InnoDB', function () {
    $rate = CheckoutScenario::shippingRate();
    $variant = CheckoutScenario::stockedVariant(100);

    $results = ConcurrencyHarness::run('reserve-order-with-injected-deadlocks', [[
        'deadlocks' => 3,
        ...CheckoutScenario::reservePayload(CheckoutScenario::guestCart($variant), $rate, 'exhausted'),
    ]]);

    expect(CheckoutScenario::outcomeCounts($results, 'three injected deadlocks'))->toBe(CheckoutScenario::expectedCounts(['deadlock' => 1]))
        ->and(Order::query()->count())->toBe(0)
        ->and(OrderItem::query()->count())->toBe(0)
        ->and(Payment::query()->count())->toBe(0)
        ->and(InventoryMovement::query()->where('movement_type', 'reservation')->count())->toBe(0)
        ->and(InventoryBalance::query()->findOrFail($variant->id)->reserved)->toBe(0)
        ->and(ConcurrencyHarness::inventoryInvariantViolations())->toBe([]);
});

test('the same key over HTTP creates one order and one Stripe session', function () {
    $rate = CheckoutScenario::shippingRate();
    $variant = CheckoutScenario::stockedVariant(100);
    $user = User::factory()->create();
    $cart = Cart::query()->create(['user_id' => $user->id]);
    CartItem::query()->create(['cart_id' => $cart->id, 'variant_id' => $variant->id, 'quantity' => 1]);

    $results = ConcurrencyHarness::run('http-checkout', array_fill(0, 8, [
        'user_id' => $user->id,
        'data' => CheckoutScenario::checkoutData($rate, 'http-same-key'),
    ]));

    $counts = CheckoutScenario::outcomeCounts($results, 'HTTP same key');
    $session = DB::table(SharedDatabaseStripeSessions::TABLE)->sole();

    expect($counts['reserved'])->toBeGreaterThanOrEqual(1)
        ->and($counts['reserved'] + ($counts['stripe_retry_notice'] ?? 0))->toBe(8)
        ->and($counts['rejected'])->toBe(0)
        ->and($counts['deadlock'])->toBe(0)
        ->and($counts['error'])->toBe(0)
        ->and(Order::query()->count())->toBe(1)
        ->and(Order::query()->sole()->payments()->sole()->provider_checkout_id)->toBe($session->session_id)
        ->and(InventoryBalance::query()->findOrFail($variant->id)->reserved)->toBe(1)
        ->and(CheckoutScenario::orderViolations())->toBe([])
        ->and(ConcurrencyHarness::inventoryInvariantViolations())->toBe([]);
});

test('orders racing an admin settings change keep one consistent price set', function () {
    $rate = CheckoutScenario::shippingRate();
    $taxRate = TaxRate::query()->sole();
    $variant = CheckoutScenario::stockedVariant(100);
    $reservations = array_map(fn (int $index): array => [
        'action' => 'reserve-order',
        'payload' => CheckoutScenario::reservePayload(CheckoutScenario::guestCart($variant), $rate, "admin-race-{$index}"),
    ], range(0, 5));
    $settingsChange = [
        'action' => 'change-shipping-and-tax',
        'payload' => ['rate_id' => $rate->id, 'tax_rate_id' => $taxRate->id, 'iterations' => 40],
    ];

    $results = ConcurrencyHarness::run('mixed', [...$reservations, $settingsChange, $settingsChange]);
    $counts = CheckoutScenario::outcomeCounts($results, 'admin settings race');
    $orders = Order::query()->with('payments')->get();

    expect($counts['reserved'])->toBe(6)
        ->and($counts['settings_changed'])->toBe(2)
        ->and($counts['rejected'] + $counts['deadlock'] + $counts['error'])->toBe(0)
        ->and(CheckoutScenario::orderViolations())->toBe([]);

    foreach ($orders as $order) {
        expect($order->shipping_minor)->toBeIn([500, 900])
            ->and($order->tax_rate_basis_points)->toBeIn([800, 1000])
            ->and($order->tax_minor)->toBe($order->tax_rate_basis_points === 1000 ? 91 : 74);
    }

    $snapshot = $orders->map->only(['id', 'shipping_minor', 'tax_minor', 'total_minor', 'tax_rate_basis_points'])->all();
    ShippingRate::query()->findOrFail($rate->id)->update(['fee_minor' => 12345]);
    TaxRate::query()->findOrFail($taxRate->id)->update(['tax_basis_points' => 2500]);

    expect(Order::query()->get()->map->only(['id', 'shipping_minor', 'tax_minor', 'total_minor', 'tax_rate_basis_points'])->all())
        ->toBe($snapshot);
});
