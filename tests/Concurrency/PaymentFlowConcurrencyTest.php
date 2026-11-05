<?php

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\OrderEmail;
use App\Models\OrderEvent;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\EcommercePaymentWebhookService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Stripe\Event;
use Tests\Support\Concurrency\CheckoutScenario;
use Tests\Support\Concurrency\ConcurrencyHarness;
use Tests\Support\Concurrency\ConcurrencyTestCase;

uses(ConcurrencyTestCase::class);

beforeEach(function () {
    ConcurrencyHarness::rebuildSchema();
    $this->rate = CheckoutScenario::shippingRate();
    $this->variant = CheckoutScenario::stockedVariant(5);
});

function balanceOf(ProductVariant $variant): InventoryBalance
{
    return InventoryBalance::query()->findOrFail($variant->id);
}

function movements(string $type): int
{
    return InventoryMovement::query()->where('movement_type', $type)->count();
}

/**
 * @param  array<int, array<string, mixed>>  $results
 */
function expectNoFailures(array $results, string $label): array
{
    $counts = CheckoutScenario::outcomeCounts($results, $label);

    expect($counts['deadlock'])->toBe(0)
        ->and($counts['lock_wait_timeout'])->toBe(0)
        ->and($counts['error'])->toBe(0);

    return $counts;
}

/**
 * @param  array<string, mixed>  $payload
 * @return array{action: string, payload: array<string, mixed>}
 */
function step(string $action, array $payload = []): array
{
    return ['action' => $action, 'payload' => $payload];
}

test('the same event delivered eight times applies once', function () {
    $order = CheckoutScenario::orderWithSession($this->rate, $this->variant, 'same-event');
    $event = CheckoutScenario::paidEvent($order, 'evt_same');

    expectNoFailures(ConcurrencyHarness::run('webhook-event', array_fill(0, 8, ['event' => $event])), 'same event x8');

    expect($order->fresh()->status)->toBe('paid')
        ->and(movements('sale'))->toBe(1)
        ->and(balanceOf($this->variant)->on_hand)->toBe(4)
        ->and(balanceOf($this->variant)->reserved)->toBe(0)
        ->and(DB::table('ec_payment_webhook_events')->count())->toBe(1)
        ->and(OrderEmail::query()->where('email_type', 'order_confirmation')->sole()->attempts)->toBe(1)
        ->and(ConcurrencyHarness::inventoryInvariantViolations())->toBe([]);
});

test('different events for one payment apply once', function () {
    $order = CheckoutScenario::orderWithSession($this->rate, $this->variant, 'many-events');
    $payloads = array_map(
        fn (int $index): array => ['event' => CheckoutScenario::paidEvent(
            $order,
            "evt_many_{$index}",
            type: $index % 2 === 0 ? 'checkout.session.completed' : 'checkout.session.async_payment_succeeded',
        )],
        range(0, 7),
    );

    expectNoFailures(ConcurrencyHarness::run('webhook-event', $payloads), 'different events x8');

    expect($order->fresh()->status)->toBe('paid')
        ->and(movements('sale'))->toBe(1)
        ->and(balanceOf($this->variant)->on_hand)->toBe(4)
        ->and(DB::table('ec_payment_webhook_events')->where('status', 'processed')->count())->toBe(8)
        ->and(ConcurrencyHarness::inventoryInvariantViolations())->toBe([]);
});

test('webhooks racing reconciliation of a paid session apply once', function () {
    $order = CheckoutScenario::orderWithSession($this->rate, $this->variant, 'paid-race');
    CheckoutScenario::markSessionPaid($order);
    Order::query()->whereKey($order->id)->update(['reservation_expires_at' => now()->subHour()]);
    $event = CheckoutScenario::paidEvent($order, 'evt_paid_race');

    expectNoFailures(ConcurrencyHarness::run('mixed', [
        step('webhook-event', ['event' => $event]), step('reconcile'),
        step('webhook-event', ['event' => $event]), step('reconcile'),
        step('webhook-event', ['event' => $event]), step('reconcile'),
    ]), 'webhook vs reconcile, paid');

    expect($order->fresh()->status)->toBe('paid')
        ->and(movements('sale'))->toBe(1)
        ->and(movements('reservation_release'))->toBe(0)
        ->and(balanceOf($this->variant)->on_hand)->toBe(4)
        ->and(balanceOf($this->variant)->reserved)->toBe(0)
        ->and(ConcurrencyHarness::inventoryInvariantViolations())->toBe([]);
});

test('a paid webhook racing expiry ends sold or in review, never both', function () {
    $order = CheckoutScenario::orderWithSession($this->rate, $this->variant, 'expiry-race');
    Order::query()->whereKey($order->id)->update(['reservation_expires_at' => now()->subHour()]);
    $event = CheckoutScenario::paidEvent($order, 'evt_expiry_race');

    expectNoFailures(ConcurrencyHarness::run('mixed', [
        step('reconcile'), step('webhook-event', ['event' => $event]),
        step('reconcile'), step('webhook-event', ['event' => $event]),
    ]), 'webhook vs reconcile, expiring');

    $order = $order->fresh();
    $sold = movements('sale');
    $released = movements('reservation_release');

    expect($order->status)->toBeIn(['paid', 'payment_review'])
        ->and($sold + $released)->toBe(1)
        ->and($order->status === 'paid')->toBe($sold === 1)
        ->and(balanceOf($this->variant)->reserved)->toBe(0)
        ->and(balanceOf($this->variant)->on_hand)->toBe(5 - $sold)
        ->and(ConcurrencyHarness::inventoryInvariantViolations())->toBe([]);
});

test('a buyer cancelling while a payment arrives ends sold or in review, never both', function () {
    $order = CheckoutScenario::orderWithSession($this->rate, $this->variant, 'cancel-race');
    $event = CheckoutScenario::paidEvent($order, 'evt_cancel_race');

    expectNoFailures(ConcurrencyHarness::run('mixed', [
        step('cancel-pending', ['order_id' => $order->id]), step('webhook-event', ['event' => $event]),
        step('cancel-pending', ['order_id' => $order->id]), step('webhook-event', ['event' => $event]),
    ]), 'cancel vs payment');

    $order = $order->fresh();
    $sold = movements('sale');

    expect($order->status)->toBeIn(['paid', 'payment_review'])
        ->and($sold + movements('reservation_release'))->toBe(1)
        ->and(balanceOf($this->variant)->reserved)->toBe(0)
        ->and(balanceOf($this->variant)->on_hand)->toBe(5 - $sold)
        ->and(ConcurrencyHarness::inventoryInvariantViolations())->toBe([]);
});

test('reconciliation and cancelling together release an order once', function () {
    $order = CheckoutScenario::orderWithSession($this->rate, $this->variant, 'release-race');
    Order::query()->whereKey($order->id)->update(['reservation_expires_at' => now()->subHour()]);

    expectNoFailures(ConcurrencyHarness::run('mixed', [
        step('reconcile'), step('cancel-pending', ['order_id' => $order->id]),
        step('reconcile'), step('cancel-pending', ['order_id' => $order->id]),
    ]), 'reconcile vs cancel');

    expect($order->fresh()->status)->toBe('cancelled')
        ->and(movements('reservation_release'))->toBe(1)
        ->and(balanceOf($this->variant)->reserved)->toBe(0)
        ->and(ConcurrencyHarness::inventoryInvariantViolations())->toBe([]);
});

test('refund events arriving together complete a refund once', function () {
    $order = CheckoutScenario::orderWithSession($this->rate, $this->variant, 'refund-race');
    app(EcommercePaymentWebhookService::class)->handle(Event::constructFrom(CheckoutScenario::paidEvent($order, 'evt_refund_paid')));
    $paymentIntent = $order->payments()->sole()->provider_payment_id;
    $payloads = array_map(fn (int $index): array => ['event' => $index % 2 === 0
        ? CheckoutScenario::event("evt_refund_{$index}", 'refund.updated', [
            'id' => "re_{$index}", 'object' => 'refund', 'payment_intent' => $paymentIntent,
            'status' => 'succeeded', 'amount' => $order->total_minor, 'currency' => 'jpy',
        ])
        : CheckoutScenario::event("evt_refund_{$index}", 'charge.refunded', [
            'id' => "ch_{$index}", 'object' => 'charge', 'payment_intent' => $paymentIntent,
            'refunded' => true, 'amount_refunded' => $order->total_minor, 'currency' => 'jpy',
        ])], range(0, 7));

    expectNoFailures(ConcurrencyHarness::run('webhook-event', $payloads), 'refund events x8');

    expect($order->fresh()->status)->toBe('refunded')
        ->and(OrderEvent::query()->where('event_type', 'refund_completed')->count())->toBe(1)
        ->and(OrderEmail::query()->where('email_type', 'refund_completed')->sole()->attempts)->toBe(1)
        ->and(balanceOf($this->variant)->on_hand)->toBe(4)
        ->and(ConcurrencyHarness::inventoryInvariantViolations())->toBe([]);
});

test('refund events on a mismatched review release its reservation once', function () {
    $order = CheckoutScenario::orderWithSession($this->rate, $this->variant, 'review-refund');
    app(EcommercePaymentWebhookService::class)->handle(Event::constructFrom(
        CheckoutScenario::paidEvent($order, 'evt_mismatch', ['amount_total' => 1]),
    ));
    expect($order->fresh()->status)->toBe('payment_review')
        ->and(balanceOf($this->variant)->reserved)->toBe(1);
    $paymentIntent = $order->payments()->sole()->provider_payment_id;
    $payloads = array_map(fn (int $index): array => ['event' => CheckoutScenario::event("evt_review_refund_{$index}", 'refund.updated', [
        'id' => "re_{$index}", 'object' => 'refund', 'payment_intent' => $paymentIntent,
        'status' => 'succeeded', 'amount' => 1, 'currency' => 'jpy',
    ])], range(0, 7));

    expectNoFailures(ConcurrencyHarness::run('webhook-event', $payloads), 'review refund x8');

    expect($order->fresh()->status)->toBe('refunded')
        ->and(movements('reservation_release'))->toBe(1)
        ->and(movements('sale'))->toBe(0)
        ->and(balanceOf($this->variant)->reserved)->toBe(0)
        ->and(balanceOf($this->variant)->on_hand)->toBe(5)
        ->and(ConcurrencyHarness::inventoryInvariantViolations())->toBe([]);
});

test('approving a review while its refund completes ends refunded with consistent stock', function () {
    $order = CheckoutScenario::orderWithSession($this->rate, $this->variant, 'approve-race');
    app(CheckoutService::class)->releaseReservation($order, 'test');
    app(EcommercePaymentWebhookService::class)->handle(Event::constructFrom(CheckoutScenario::paidEvent($order, 'evt_late')));
    expect($order->fresh()->review_reason)->toBe('late_payment');
    $admin = User::factory()->admin()->create();
    $refund = CheckoutScenario::event('evt_approve_refund', 'refund.updated', [
        'id' => 're_1', 'object' => 'refund', 'payment_intent' => $order->payments()->sole()->provider_payment_id,
        'status' => 'succeeded', 'amount' => $order->total_minor, 'currency' => 'jpy',
    ]);

    expectNoFailures(ConcurrencyHarness::run('mixed', [
        step('approve-review', ['order_id' => $order->id, 'actor_id' => $admin->id]),
        step('webhook-event', ['event' => $refund]),
        step('approve-review', ['order_id' => $order->id, 'actor_id' => $admin->id]),
        step('webhook-event', ['event' => $refund]),
    ]), 'approve vs refund');

    expect($order->fresh()->status)->toBe('refunded')
        ->and(movements('sale'))->toBeLessThanOrEqual(1)
        ->and(balanceOf($this->variant)->reserved)->toBe(0)
        ->and(balanceOf($this->variant)->on_hand)->toBe(5 - movements('sale'))
        ->and(ConcurrencyHarness::inventoryInvariantViolations())->toBe([]);
});

test('a stock adjustment racing orders keeps stock above what is reserved', function () {
    $variant = CheckoutScenario::stockedVariant(10);
    $admin = User::factory()->admin()->create();
    $reservations = array_map(fn (int $index): array => step(
        'reserve-order',
        CheckoutScenario::reservePayload(CheckoutScenario::guestCart($variant), $this->rate, "adjust-{$index}"),
    ), range(0, 5));

    $counts = expectNoFailures(ConcurrencyHarness::run('mixed', [
        ...$reservations,
        step('set-on-hand', ['variant_id' => $variant->id, 'quantity' => 1, 'actor_id' => $admin->id]),
        step('set-on-hand', ['variant_id' => $variant->id, 'quantity' => 3, 'actor_id' => $admin->id]),
    ]), 'stock adjustment vs orders');

    expect(balanceOf($variant)->reserved)->toBe($counts['reserved'])
        ->and(balanceOf($variant)->on_hand)->toBeGreaterThanOrEqual(balanceOf($variant)->reserved)
        ->and(CheckoutScenario::orderViolations())->toBe([])
        ->and(ConcurrencyHarness::inventoryInvariantViolations())->toBe([]);
});

test('payments settling while new orders reserve the same product do not deadlock', function () {
    $variant = CheckoutScenario::stockedVariant(100);
    $steps = [];
    foreach (range(0, 3) as $index) {
        $order = CheckoutScenario::orderWithSession($this->rate, $variant, "settling-{$index}");
        $steps[] = step('webhook-event', ['event' => CheckoutScenario::paidEvent($order, "evt_settling_{$index}")]);
        $steps[] = step('reserve-order', CheckoutScenario::reservePayload(CheckoutScenario::guestCart($variant), $this->rate, "reserving-{$index}"));
    }

    $counts = expectNoFailures(ConcurrencyHarness::run('mixed', $steps), 'settling vs reserving');

    expect($counts['reserved'])->toBe(4)
        ->and($counts['handled'])->toBe(4)
        ->and(movements('sale'))->toBe(4)
        ->and(balanceOf($variant)->on_hand)->toBe(96)
        ->and(balanceOf($variant)->reserved)->toBe(4)
        ->and(ConcurrencyHarness::inventoryInvariantViolations())->toBe([]);
});

test('reservations being released while new orders reserve the same product do not deadlock', function () {
    $variant = CheckoutScenario::stockedVariant(100);
    $steps = [];
    foreach (range(0, 3) as $index) {
        $order = CheckoutScenario::orderWithSession($this->rate, $variant, "releasing-{$index}");
        $steps[] = step('cancel-pending', ['order_id' => $order->id]);
        $steps[] = step('reserve-order', CheckoutScenario::reservePayload(CheckoutScenario::guestCart($variant), $this->rate, "taking-{$index}"));
    }

    $counts = expectNoFailures(ConcurrencyHarness::run('mixed', $steps), 'releasing vs reserving');

    expect($counts['reserved'])->toBe(4)
        ->and(movements('reservation_release'))->toBe(4)
        ->and(balanceOf($variant)->reserved)->toBe(4)
        ->and(ConcurrencyHarness::inventoryInvariantViolations())->toBe([]);
});

test('carts holding the same products in opposite orders do not deadlock or oversell', function (int $stock, int $reserved, int $rejected) {
    $first = CheckoutScenario::stockedVariant($stock);
    $second = CheckoutScenario::stockedVariant($stock);
    $payloads = array_map(function (int $index) use ($first, $second): array {
        $cart = Cart::query()->create(['guest_token_hash' => bin2hex(random_bytes(32)), 'expires_at' => now()->addDay()]);
        foreach ($index % 2 === 0 ? [$first, $second] : [$second, $first] as $variant) {
            CartItem::query()->create(['cart_id' => $cart->id, 'variant_id' => $variant->id, 'quantity' => 1]);
        }

        return CheckoutScenario::reservePayload($cart, $this->rate, "two-lines-{$index}");
    }, range(0, 7));

    $counts = expectNoFailures(ConcurrencyHarness::run('reserve-order', $payloads), "two line carts, stock {$stock}");

    expect($counts['reserved'])->toBe($reserved)
        ->and($counts['rejected'])->toBe($rejected)
        ->and(balanceOf($first)->reserved)->toBe($reserved)
        ->and(balanceOf($second)->reserved)->toBe($reserved)
        ->and(CheckoutScenario::orderViolations())->toBe([])
        ->and(ConcurrencyHarness::inventoryInvariantViolations())->toBe([]);
})->with([
    'plenty of stock' => [100, 8, 0],
    'less stock than orders' => [3, 3, 5],
]);

test('a payment emptying the cart while the buyer edits it does not deadlock', function () {
    $cart = CheckoutScenario::guestCart($this->variant);
    $order = app(CheckoutService::class)->reserveOrder($cart, CheckoutScenario::checkoutData($this->rate, 'cart-payment'), null);
    $order->payments()->sole()->update(['provider_checkout_id' => 'cs_cart_payment', 'checkout_state' => 'created']);
    $event = CheckoutScenario::paidEvent($order->fresh(), 'evt_cart_payment');
    $add = step('add-to-cart', ['cart_id' => $cart->id, 'variant_id' => $this->variant->id]);

    expectNoFailures(ConcurrencyHarness::run('mixed', [
        step('webhook-event', ['event' => $event]), $add,
        step('webhook-event', ['event' => $event]), $add,
    ]), 'payment vs cart edit');

    expect($order->fresh()->status)->toBe('paid')
        ->and($order->fresh()->active_cart_id)->toBeNull()
        ->and(movements('sale'))->toBe(1)
        ->and(ConcurrencyHarness::inventoryInvariantViolations())->toBe([]);
});

test('the database refuses to reserve more than is on hand', function () {
    expect(fn () => DB::table('ec_inventory_balances')->where('variant_id', $this->variant->id)->update(['reserved' => 6]))
        ->toThrow(QueryException::class, 'ec_inventory_balances_on_hand_covers_reserved');

    expect(balanceOf($this->variant)->reserved)->toBe(0)
        ->and(Schema::hasTable('ec_inventory_balances'))->toBeTrue();
});
