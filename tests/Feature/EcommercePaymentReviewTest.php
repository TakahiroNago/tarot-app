<?php

use App\Jobs\SendOrderConfirmationEmail;
use App\Mail\AdminOrderAlertMail;
use App\Mail\OrderConfirmationMail;
use App\Mail\OrderPaymentReviewMail;
use App\Mail\OrderRefundCompletedMail;
use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\InventoryService;
use App\Services\RefundService;
use App\Services\StripeBillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Stripe\WebhookSignature;
use Tests\Support\Stripe\FakeStripeBillingService;
use Tests\Support\Stripe\FakeStripeRefunds;

uses(RefreshDatabase::class);

beforeEach(function () {
    Mail::fake();
    config([
        'services.stripe.ecommerce_webhook_secret' => 'whsec_review_test',
        'services.contact.email' => 'admin@example.test',
    ]);
});

/**
 * Builds an order the way checkout does, with the reservation recorded in the ledger.
 *
 * @param  array<string, mixed>  $attributes
 * @return array{order: Order, payment: Payment, item: OrderItem, variant: ProductVariant, balance: InventoryBalance}
 */
function reviewOrder(array $attributes = [], int $onHand = 5, bool $reserved = true, int $quantity = 1): array
{
    $product = Product::factory()->create();
    $variant = ProductVariant::factory()->for($product)->create();
    $balance = InventoryBalance::factory()->for($variant, 'variant')->create([
        'on_hand' => $onHand,
        'reserved' => $reserved ? $quantity : 0,
    ]);
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
        'subtotal_minor' => 1000 * $quantity,
        'shipping_minor' => 500,
        'total_minor' => 1000 * $quantity + 500,
        'currency' => 'JPY',
        'reservation_expires_at' => now()->addMinutes(30),
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
    if ($reserved) {
        InventoryMovement::query()->create([
            'variant_id' => $variant->id,
            'order_id' => $order->id,
            'movement_type' => 'reservation',
            'reserved_delta' => $quantity,
            'idempotency_key' => InventoryService::movementKey('reservation', $order->id, $variant->id),
        ]);
    }
    $payment = Payment::query()->create([
        'order_id' => $order->id,
        'provider' => 'stripe',
        'provider_checkout_id' => 'cs_'.$order->order_number,
        'status' => 'pending',
        'amount_minor' => $order->total_minor,
        'currency' => 'JPY',
        'idempotency_key' => 'ec-'.$order->order_number,
    ]);

    return compact('order', 'payment', 'item', 'variant', 'balance');
}

/**
 * @param  array<string, mixed>  $object
 */
function reviewWebhook(mixed $test, string $type, array $object): mixed
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
        'HTTP_STRIPE_SIGNATURE' => WebhookSignature::generateSignatureHeader($payload, 'whsec_review_test'),
        'CONTENT_TYPE' => 'application/json',
    ], content: $payload);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function paidSessionFor(Order $order, Payment $payment, array $overrides = []): array
{
    return [
        'id' => $payment->provider_checkout_id,
        'object' => 'checkout.session',
        'status' => 'complete',
        'payment_status' => 'paid',
        'amount_total' => $order->total_minor,
        'currency' => 'jpy',
        'client_reference_id' => $order->order_number,
        'payment_intent' => 'pi_'.$order->order_number,
        'metadata' => ['ec_order_id' => (string) $order->id, 'ec_payment_id' => (string) $payment->id],
        ...$overrides,
    ];
}

/**
 * @param  array{order: Order, payment: Payment}  $scenario
 * @param  array<string, mixed>  $overrides
 */
function payOrder(mixed $test, array $scenario, array $overrides = []): void
{
    reviewWebhook($test, 'checkout.session.completed', paidSessionFor($scenario['order'], $scenario['payment'], $overrides))->assertOk();
}

function fakeRefunds(): FakeStripeRefunds
{
    FakeStripeBillingService::install(app());

    return app(StripeBillingService::class)->ecommerceClient()->fakeRefunds;
}

/**
 * @param  array{order: Order, variant: ProductVariant, balance: InventoryBalance}  $scenario
 */
function expectLedgerMatchesBalance(array $scenario, int $startingOnHand): void
{
    $movements = InventoryMovement::query()->where('variant_id', $scenario['variant']->id);

    expect($startingOnHand + (int) (clone $movements)->sum('on_hand_delta'))->toBe($scenario['balance']->fresh()->on_hand)
        ->and((int) (clone $movements)->sum('reserved_delta'))->toBe($scenario['balance']->fresh()->reserved);
}

test('a normal payment settles and sends only the confirmation', function () {
    $scenario = reviewOrder();
    payOrder($this, $scenario);

    $order = $scenario['order']->fresh();
    expect($order->status)->toBe('paid')
        ->and($order->payment_status)->toBe('paid')
        ->and($order->review_reason)->toBeNull()
        ->and($scenario['payment']->fresh()->received_amount_minor)->toBe($order->total_minor)
        ->and($scenario['payment']->fresh()->received_currency)->toBe('JPY')
        ->and($scenario['balance']->fresh()->on_hand)->toBe(4)
        ->and($scenario['balance']->fresh()->reserved)->toBe(0)
        ->and(OrderEvent::query()->count())->toBe(0);

    Mail::assertSent(OrderConfirmationMail::class, 1);
    Mail::assertNotSent(OrderPaymentReviewMail::class);
    Mail::assertNotSent(AdminOrderAlertMail::class);
    expectLedgerMatchesBalance($scenario, 5);
});

test('a mismatched payment enters review holding its reservation without a sale', function (array $session, string $reason, int $received, string $currency) {
    $scenario = reviewOrder();
    payOrder($this, $scenario, $session);

    $order = $scenario['order']->fresh();
    expect($order->status)->toBe('payment_review')
        ->and($order->payment_status)->toBe('payment_review')
        ->and($order->review_reason)->toBe($reason)
        ->and($order->review_opened_at)->not->toBeNull()
        ->and($order->reservation_expires_at)->toBeNull()
        ->and($scenario['payment']->fresh()->received_amount_minor)->toBe($received)
        ->and($scenario['payment']->fresh()->received_currency)->toBe($currency)
        ->and($scenario['balance']->fresh()->on_hand)->toBe(5)
        ->and($scenario['balance']->fresh()->reserved)->toBe(1)
        ->and(InventoryMovement::query()->where('movement_type', 'sale')->count())->toBe(0);

    Mail::assertNotSent(OrderConfirmationMail::class);
    Mail::assertSent(OrderPaymentReviewMail::class, fn ($mail) => $mail->hasTo('buyer@example.test'));
    Mail::assertSent(AdminOrderAlertMail::class, fn ($mail) => $mail->hasTo('admin@example.test'));
})->with([
    'amount' => [['amount_total' => 1], 'amount_mismatch', 1, 'JPY'],
    'currency' => [['currency' => 'usd'], 'currency_mismatch', 1500, 'USD'],
]);

test('the expiry job does not release a reservation held for review', function () {
    $scenario = reviewOrder();
    payOrder($this, $scenario, ['amount_total' => 1]);

    $this->travel(2)->hours();
    $this->artisan('ecommerce:expire-reservations')->assertExitCode(0);

    expect($scenario['order']->fresh()->status)->toBe('payment_review')
        ->and($scenario['balance']->fresh()->reserved)->toBe(1);
});

test('a late payment enters review without reserving stock again', function () {
    $scenario = reviewOrder(['status' => 'cancelled', 'payment_status' => 'failed', 'reservation_expires_at' => null], reserved: false);
    payOrder($this, $scenario);

    expect($scenario['order']->fresh()->review_reason)->toBe('late_payment')
        ->and($scenario['balance']->fresh()->reserved)->toBe(0)
        ->and($scenario['balance']->fresh()->on_hand)->toBe(5);
    Mail::assertNotSent(OrderConfirmationMail::class);
    Mail::assertSent(OrderPaymentReviewMail::class, 1);
});

test('inventory that no longer matches the reservation enters review', function () {
    $scenario = reviewOrder();
    $scenario['balance']->update(['reserved' => 0]);
    payOrder($this, $scenario);

    expect($scenario['order']->fresh()->review_reason)->toBe('inventory_inconsistent')
        ->and(InventoryMovement::query()->where('movement_type', 'sale')->count())->toBe(0);
});

test('a missing variant enters review and cannot be approved', function () {
    $scenario = reviewOrder();
    $scenario['item']->update(['variant_id' => null]);
    payOrder($this, $scenario);

    expect($scenario['order']->fresh()->review_reason)->toBe('variant_missing');

    $this->actingAs(User::factory()->admin()->create())
        ->post(route('admin.orders.review.approve', $scenario['order']), ['note' => '確認済み'])
        ->assertSessionHasErrors('review');
});

test('approving a late payment sells available stock and notifies the buyer', function () {
    $scenario = reviewOrder(['status' => 'cancelled', 'payment_status' => 'failed', 'reservation_expires_at' => null], reserved: false);
    payOrder($this, $scenario);
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->post(route('admin.orders.review.approve', $scenario['order']), ['note' => '入金確認済み'])
        ->assertRedirect(route('admin.orders.show', $scenario['order']));

    $order = $scenario['order']->fresh();
    expect($order->status)->toBe('paid')
        ->and($order->payment_status)->toBe('paid')
        ->and($order->review_resolved_by)->toBe($admin->id)
        ->and($order->review_note)->toBe('入金確認済み')
        ->and($order->isUnderReview())->toBeFalse()
        ->and($scenario['balance']->fresh()->on_hand)->toBe(4)
        ->and($scenario['balance']->fresh()->reserved)->toBe(0)
        ->and(InventoryMovement::query()->where('movement_type', 'sale')->sole()->on_hand_delta)->toBe(-1)
        ->and(OrderEvent::query()->where('event_type', 'review_approved')->sole()->actor_user_id)->toBe($admin->id);
    Mail::assertSent(OrderConfirmationMail::class, 1);

    $this->patch(route('admin.orders.shipment.update', $order), ['status' => 'shipped'])
        ->assertRedirect(route('admin.orders.show', $order));
});

test('approving an order that still holds its reservation consumes that reservation', function () {
    $scenario = reviewOrder();
    $scenario['order']->update(['reservation_expires_at' => null]);
    payOrder($this, $scenario);
    expect($scenario['order']->fresh()->review_reason)->toBe('inventory_inconsistent');

    $this->actingAs(User::factory()->admin()->create())
        ->post(route('admin.orders.review.approve', $scenario['order']), ['note' => '確認済み'])
        ->assertSessionHasNoErrors();

    expect($scenario['balance']->fresh()->on_hand)->toBe(4)
        ->and($scenario['balance']->fresh()->reserved)->toBe(0);
    expectLedgerMatchesBalance($scenario, 5);
});

test('approval is refused when stock has run out, leaving the review open', function () {
    $scenario = reviewOrder(['status' => 'cancelled', 'payment_status' => 'failed', 'reservation_expires_at' => null], onHand: 2, reserved: false, quantity: 3);
    payOrder($this, $scenario);

    $this->actingAs(User::factory()->admin()->create())
        ->post(route('admin.orders.review.approve', $scenario['order']), ['note' => '確認済み'])
        ->assertSessionHasErrors('review');

    expect($scenario['order']->fresh()->status)->toBe('payment_review')
        ->and($scenario['order']->fresh()->isUnderReview())->toBeTrue()
        ->and($scenario['balance']->fresh()->on_hand)->toBe(2)
        ->and(InventoryMovement::query()->where('movement_type', 'sale')->count())->toBe(0);
    Mail::assertNotSent(OrderConfirmationMail::class);
});

test('stock reserved by another order is not available to an approval', function () {
    $scenario = reviewOrder(['status' => 'cancelled', 'payment_status' => 'failed', 'reservation_expires_at' => null], onHand: 3, reserved: false);
    $scenario['balance']->update(['reserved' => 3]);
    payOrder($this, $scenario);

    $this->actingAs(User::factory()->admin()->create())
        ->post(route('admin.orders.review.approve', $scenario['order']), ['note' => '確認済み'])
        ->assertSessionHasErrors('review');

    expect($scenario['balance']->fresh()->on_hand)->toBe(3)
        ->and($scenario['balance']->fresh()->reserved)->toBe(3);
});

test('amount and currency mismatches can only be refunded', function (array $session) {
    $scenario = reviewOrder();
    payOrder($this, $scenario, $session);

    $this->actingAs(User::factory()->admin()->create())
        ->post(route('admin.orders.review.approve', $scenario['order']), ['note' => '確認済み'])
        ->assertSessionHasErrors('review');

    expect($scenario['order']->fresh()->status)->toBe('payment_review')
        ->and($scenario['balance']->fresh()->on_hand)->toBe(5);
    Mail::assertNotSent(OrderConfirmationMail::class);
})->with([
    'amount' => [['amount_total' => 1]],
    'currency' => [['currency' => 'usd']],
]);

test('approval needs a note, an admin, and an open review', function () {
    $scenario = reviewOrder(['status' => 'cancelled', 'payment_status' => 'failed', 'reservation_expires_at' => null], reserved: false);
    payOrder($this, $scenario);
    $route = route('admin.orders.review.approve', $scenario['order']);

    $this->actingAs(User::factory()->create())->post($route, ['note' => '確認済み'])->assertForbidden();
    $this->actingAs(User::factory()->admin()->create())->post($route, ['note' => ''])->assertSessionHasErrors('note');
    expect($scenario['order']->fresh()->status)->toBe('payment_review');

    $this->post($route, ['note' => '確認済み'])->assertSessionHasNoErrors();
    $this->post($route, ['note' => '二度目'])->assertSessionHasErrors('review');

    expect(InventoryMovement::query()->where('movement_type', 'sale')->count())->toBe(1)
        ->and($scenario['balance']->fresh()->on_hand)->toBe(4)
        ->and($scenario['order']->fresh()->review_note)->toBe('確認済み');
    Mail::assertSent(OrderConfirmationMail::class, 1);
});

test('refunding a mismatched payment returns what was received and releases the reservation once', function () {
    $refunds = fakeRefunds()->willReturn('succeeded', 1);
    $scenario = reviewOrder();
    payOrder($this, $scenario, ['amount_total' => 1]);

    $status = app(RefundService::class)->refundFull($scenario['order'], User::factory()->admin()->create(), '返金理由');

    $order = $scenario['order']->fresh();
    expect($status)->toBe('succeeded')
        ->and($order->status)->toBe('refunded')
        ->and($order->payment_status)->toBe('refunded')
        ->and($order->isUnderReview())->toBeFalse()
        ->and($scenario['balance']->fresh()->reserved)->toBe(0)
        ->and($scenario['balance']->fresh()->on_hand)->toBe(5)
        ->and(InventoryMovement::query()->where('movement_type', 'reservation_release')->count())->toBe(1)
        ->and($refunds->createCalls[0]['params']['payment_intent'])->toBe('pi_'.$order->order_number);
    Mail::assertSent(OrderRefundCompletedMail::class, fn ($mail) => $mail->refundedAmount === 1 && $mail->hasTo('buyer@example.test'));
    Mail::assertNotSent(OrderConfirmationMail::class);
    expectLedgerMatchesBalance($scenario, 5);

    reviewWebhook($this, 'refund.updated', [
        'object' => 'refund',
        'payment_intent' => 'pi_'.$order->order_number,
        'amount' => 1,
        'currency' => 'jpy',
        'status' => 'succeeded',
    ])->assertOk();

    expect(InventoryMovement::query()->where('movement_type', 'reservation_release')->count())->toBe(1)
        ->and($scenario['balance']->fresh()->reserved)->toBe(0);
    Mail::assertSent(OrderRefundCompletedMail::class, 1);
});

test('a refunded review order has no sale, so it cannot be restocked', function () {
    fakeRefunds()->willReturn('succeeded', 1);
    $scenario = reviewOrder();
    payOrder($this, $scenario, ['amount_total' => 1]);
    app(RefundService::class)->refundFull($scenario['order'], User::factory()->admin()->create(), '返金理由');

    $this->actingAs(User::factory()->admin()->create())
        ->post(route('admin.orders.items.restock', [$scenario['order'], $scenario['item']]), [
            'quantity' => 1,
            'reason' => '検品済み',
            'restock_key' => str_repeat('a', 64),
        ])
        ->assertSessionHasErrors('quantity');

    expect($scenario['balance']->fresh()->on_hand)->toBe(5);
});

test('a review refund waits for the webhook, and a failure returns it to review', function () {
    fakeRefunds()->willReturn('pending', 1500);
    $scenario = reviewOrder(['status' => 'cancelled', 'payment_status' => 'failed', 'reservation_expires_at' => null], reserved: false);
    payOrder($this, $scenario);

    expect(app(RefundService::class)->refundFull($scenario['order'], User::factory()->admin()->create(), '返金理由'))->toBe('pending')
        ->and($scenario['order']->fresh()->payment_status)->toBe('refund_pending')
        ->and($scenario['order']->fresh()->status)->toBe('payment_review');

    $this->actingAs(User::factory()->admin()->create())
        ->post(route('admin.orders.review.approve', $scenario['order']), ['note' => '確認済み'])
        ->assertSessionHasErrors('review');
    Mail::assertNotSent(OrderRefundCompletedMail::class);

    reviewWebhook($this, 'refund.updated', [
        'object' => 'refund',
        'payment_intent' => 'pi_'.$scenario['order']->order_number,
        'amount' => 1500,
        'currency' => 'jpy',
        'status' => 'failed',
    ])->assertOk();

    $order = $scenario['order']->fresh();
    expect($order->payment_status)->toBe('payment_review')
        ->and($order->status)->toBe('payment_review')
        ->and($order->isUnderReview())->toBeTrue()
        ->and(OrderEvent::query()->where('event_type', 'refund_failed')->count())->toBe(1);
    Mail::assertNotSent(OrderRefundCompletedMail::class);
});

test('a failed refund of a settled order returns it to paid', function () {
    fakeRefunds()->willReturn('pending', 1500);
    $scenario = reviewOrder();
    payOrder($this, $scenario);
    app(RefundService::class)->refundFull($scenario['order'], User::factory()->admin()->create(), '返金理由');

    reviewWebhook($this, 'refund.updated', [
        'object' => 'refund',
        'payment_intent' => 'pi_'.$scenario['order']->order_number,
        'amount' => 1500,
        'currency' => 'jpy',
        'status' => 'failed',
    ])->assertOk();

    expect($scenario['order']->fresh()->payment_status)->toBe('paid')
        ->and($scenario['order']->fresh()->status)->toBe('paid')
        ->and($scenario['balance']->fresh()->on_hand)->toBe(4);
});

test('refunding a settled order keeps its stock and notifies the buyer', function () {
    fakeRefunds()->willReturn('succeeded', 1500);
    $scenario = reviewOrder();
    payOrder($this, $scenario);

    app(RefundService::class)->refundFull($scenario['order'], User::factory()->admin()->create(), '返金理由');

    expect($scenario['order']->fresh()->status)->toBe('refunded')
        ->and($scenario['balance']->fresh()->on_hand)->toBe(4);
    Mail::assertSent(OrderRefundCompletedMail::class, fn ($mail) => $mail->refundedAmount === 1500);
});

test('a refund response smaller than the amount received is rejected', function () {
    fakeRefunds()->willReturn('succeeded', 1);
    $scenario = reviewOrder();
    payOrder($this, $scenario);

    expect(fn () => app(RefundService::class)->refundFull($scenario['order'], User::factory()->admin()->create(), '返金理由'))
        ->toThrow(ValidationException::class);

    expect($scenario['order']->fresh()->status)->toBe('paid');
});

test('payment events after a refund cannot reopen the order', function () {
    fakeRefunds()->willReturn('succeeded', 1500);
    $scenario = reviewOrder();
    payOrder($this, $scenario);
    app(RefundService::class)->refundFull($scenario['order'], User::factory()->admin()->create(), '返金理由');

    reviewWebhook($this, 'checkout.session.async_payment_succeeded', paidSessionFor($scenario['order'], $scenario['payment']))->assertOk();

    expect($scenario['order']->fresh()->status)->toBe('refunded')
        ->and($scenario['order']->fresh()->payment_status)->toBe('refunded')
        ->and($scenario['payment']->fresh()->status)->toBe('refunded')
        ->and($scenario['balance']->fresh()->on_hand)->toBe(4);
    Mail::assertSent(OrderConfirmationMail::class, 1);
});

test('later payment events cannot change a settled or reviewed order', function (array $firstSession, array $laterSession, string $status) {
    $scenario = reviewOrder();
    payOrder($this, $scenario, $firstSession);
    $reservedBefore = $scenario['balance']->fresh()->reserved;

    reviewWebhook($this, 'checkout.session.async_payment_succeeded', paidSessionFor($scenario['order'], $scenario['payment'], $laterSession))->assertOk();

    expect($scenario['order']->fresh()->status)->toBe($status)
        ->and($scenario['balance']->fresh()->reserved)->toBe($reservedBefore)
        ->and(InventoryMovement::query()->where('movement_type', 'sale')->count())->toBe($status === 'paid' ? 1 : 0)
        ->and(OrderEvent::query()->where('event_type', 'review_opened')->count())->toBe($status === 'paid' ? 0 : 1);
    Mail::assertSent(OrderPaymentReviewMail::class, $status === 'paid' ? 0 : 1);
})->with([
    'settled order, mismatching later event' => [[], ['amount_total' => 1], 'paid'],
    'reviewed order, matching later event' => [['amount_total' => 1], [], 'payment_review'],
]);

test('a partial refund is flagged without changing the fulfilment status', function (string $type, array $object) {
    $scenario = reviewOrder();
    payOrder($this, $scenario);
    $scenario['order']->update(['status' => 'shipped']);

    reviewWebhook($this, $type, [
        'payment_intent' => 'pi_'.$scenario['order']->order_number,
        'currency' => 'jpy',
        ...$object,
    ])->assertOk();

    $order = $scenario['order']->fresh();
    expect($order->status)->toBe('shipped')
        ->and($order->payment_status)->toBe('refund_review')
        ->and($scenario['payment']->fresh()->status)->toBe('refund_review')
        ->and(OrderEvent::query()->where('event_type', 'partial_refund_detected')->count())->toBe(1);
    Mail::assertSent(AdminOrderAlertMail::class, fn ($mail) => $mail->alertType === 'refund_review');
    Mail::assertNotSent(OrderRefundCompletedMail::class);

    $this->actingAs(User::factory()->admin()->create())
        ->patch(route('admin.orders.shipment.update', $order), ['status' => 'delivered'])
        ->assertUnprocessable();
})->with([
    'charge.refunded' => ['charge.refunded', ['object' => 'charge', 'refunded' => false, 'amount_refunded' => 500]],
    'refund.updated' => ['refund.updated', ['object' => 'refund', 'status' => 'succeeded', 'amount' => 500]],
]);

test('a full refund after a partial one completes the order', function () {
    $scenario = reviewOrder();
    payOrder($this, $scenario);
    $base = ['object' => 'charge', 'payment_intent' => 'pi_'.$scenario['order']->order_number, 'currency' => 'jpy'];

    reviewWebhook($this, 'charge.refunded', [...$base, 'refunded' => false, 'amount_refunded' => 500])->assertOk();
    reviewWebhook($this, 'charge.refunded', [...$base, 'refunded' => true, 'amount_refunded' => 1500])->assertOk();

    expect($scenario['order']->fresh()->payment_status)->toBe('refunded');
    Mail::assertSent(OrderRefundCompletedMail::class, 1);
});

test('a charge event with nothing refunded changes nothing', function () {
    $scenario = reviewOrder();
    payOrder($this, $scenario);

    reviewWebhook($this, 'charge.refunded', [
        'object' => 'charge',
        'payment_intent' => 'pi_'.$scenario['order']->order_number,
        'currency' => 'jpy',
        'refunded' => false,
        'amount_refunded' => 0,
    ])->assertOk();

    expect($scenario['order']->fresh()->payment_status)->toBe('paid');
});

test('the confirmation job refuses an order that entered review after dispatch', function () {
    $scenario = reviewOrder(['status' => 'payment_review', 'payment_status' => 'payment_review', 'review_reason' => 'late_payment']);

    (new SendOrderConfirmationEmail($scenario['order']->id))->handle(app(CheckoutService::class));

    Mail::assertNotSent(OrderConfirmationMail::class);
});

test('the admin alert is skipped when no contact address is configured', function () {
    config(['services.contact.email' => '']);
    $scenario = reviewOrder();
    payOrder($this, $scenario, ['amount_total' => 1]);

    Mail::assertNotSent(AdminOrderAlertMail::class);
    Mail::assertSent(OrderPaymentReviewMail::class, 1);
});

test('notice emails say the right thing and reveal no internal reason', function () {
    $scenario = reviewOrder();
    $order = $scenario['order'];
    $order->update(['review_reason' => 'amount_mismatch']);

    $review = new OrderPaymentReviewMail($order, null);
    $review->assertSeeInHtml('発送手続きを保留');
    $review->assertDontSeeInHtml('注文を受け付けました');
    $review->assertDontSeeInHtml('amount_mismatch');

    (new OrderRefundCompletedMail($order, null, 1500))->assertSeeInHtml('¥1,500');

    $alert = new AdminOrderAlertMail($order, 'payment_review');
    $alert->assertSeeInHtml($order->order_number);
    $alert->assertSeeInHtml('amount_mismatch');
    $alert->assertDontSeeInHtml('buyer@example.test');
    $alert->assertDontSeeInHtml('千代田1-1');
});

test('the admin page offers approval only when it is allowed', function () {
    $admin = User::factory()->admin()->create();
    $late = reviewOrder(['status' => 'cancelled', 'payment_status' => 'failed', 'reservation_expires_at' => null], reserved: false);
    payOrder($this, $late);
    $mismatch = reviewOrder();
    payOrder($this, $mismatch, ['amount_total' => 1]);

    $this->actingAs($admin)->get(route('admin.orders.show', $late['order']))
        ->assertOk()
        ->assertSee('予約解放後の支払い')
        ->assertSee(route('admin.orders.review.approve', $late['order']))
        ->assertSee(route('admin.orders.refund', $late['order']))
        ->assertDontSee(route('admin.orders.shipment.update', $late['order']));

    $this->get(route('admin.orders.show', $mismatch['order']))
        ->assertOk()
        ->assertSee('この注文は承認できません')
        ->assertDontSee(route('admin.orders.review.approve', $mismatch['order']))
        ->assertSee(route('admin.orders.refund', $mismatch['order']));
});

test('admins can filter orders needing review', function () {
    $review = reviewOrder();
    payOrder($this, $review, ['amount_total' => 1]);
    $settled = reviewOrder();
    payOrder($this, $settled);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.orders.index', ['payment_status' => 'payment_review']))
        ->assertOk()
        ->assertSee($review['order']->order_number)
        ->assertDontSee($settled['order']->order_number)
        ->assertSee('<option value="payment_review"', false);
});

test('the buyer completion page explains a review', function () {
    $scenario = reviewOrder();
    payOrder($this, $scenario, ['amount_total' => 1]);

    $this->get(route('shop.checkout.success', [
        'orderNumber' => $scenario['order']->order_number,
        'session_id' => $scenario['payment']->provider_checkout_id,
    ]))->assertOk()->assertSee('発送手続きを保留')->assertDontSee('ご注文ありがとうございます');
});

test('the new columns roll back cleanly', function () {
    $migration = require database_path('migrations/2026_11_05_120000_add_payment_review_to_ec_tables.php');

    $migration->down();

    expect(Schema::hasTable('ec_order_events'))->toBeFalse()
        ->and(Schema::hasColumn('ec_orders', 'review_reason'))->toBeFalse()
        ->and(Schema::hasColumn('ec_payments', 'received_amount_minor'))->toBeFalse();

    $migration->up();

    expect(Schema::hasTable('ec_order_events'))->toBeTrue();
});
