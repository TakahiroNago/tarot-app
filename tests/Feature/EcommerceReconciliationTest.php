<?php

use App\Mail\AdminInventoryAuditMail;
use App\Mail\AdminOrderAlertMail;
use App\Mail\OrderConfirmationMail;
use App\Mail\OrderPaymentReviewMail;
use App\Mail\OrderRefundCompletedMail;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ShippingMethod;
use App\Models\ShippingRate;
use App\Models\TaxRate;
use App\Models\User;
use App\Services\InventoryService;
use App\Services\PaymentReconciliationService;
use App\Services\StripeBillingService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Mail;
use Stripe\Exception\ApiConnectionException;
use Stripe\Refund;
use Stripe\WebhookSignature;
use Tests\Support\Stripe\FakeStripeBillingService;
use Tests\Support\Stripe\FakeStripeRefunds;

uses(RefreshDatabase::class);

const PAST_EXPIRY_AND_GRACE_MINUTES = 33;

beforeEach(function () {
    $this->freezeTime();
    Mail::fake();
    config([
        'services.contact.email' => 'admin@example.test',
        'services.stripe.ecommerce_webhook_secret' => 'whsec_reconcile_test',
    ]);
    $this->sessions = FakeStripeBillingService::install(app());
    $this->refunds = app(StripeBillingService::class)->client()->fakeRefunds;

    $this->user = User::factory()->create();
    $this->cart = Cart::query()->create(['user_id' => $this->user->id]);
    $this->variant = ProductVariant::factory()
        ->for(Product::factory()->create(['status' => 'published']))
        ->create(['price_minor' => 1000]);
    $this->balance = InventoryBalance::factory()->for($this->variant, 'variant')->create(['on_hand' => 5, 'reserved' => 0]);
    CartItem::query()->create(['cart_id' => $this->cart->id, 'variant_id' => $this->variant->id, 'quantity' => 1]);
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
    $this->actingAs($this->user);
    $this->withoutMiddleware(ThrottleRequests::class);
});

function placeOrder(mixed $test): Order
{
    $test->post(route('shop.checkout.store'), $test->form)->assertRedirect();

    return Order::query()->sole();
}

function reconcile(mixed $test): void
{
    $test->artisan('ecommerce:expire-reservations')->assertExitCode(0);
}

test('an expired open session is closed at Stripe before stock is released', function () {
    $order = placeOrder($this);

    $this->travel(PAST_EXPIRY_AND_GRACE_MINUTES)->minutes();
    reconcile($this);

    expect($this->sessions->retrieve('cs_test_fake_1')->status)->toBe('expired')
        ->and($order->fresh()->status)->toBe('cancelled')
        ->and($this->balance->fresh()->reserved)->toBe(0)
        ->and(InventoryMovement::query()->where('movement_type', 'reservation_release')->count())->toBe(1);
});

test('an order is left alone until the expiry and its grace period have passed', function () {
    $order = placeOrder($this);

    $this->travel(1830 + 119)->seconds();
    reconcile($this);
    expect($order->fresh()->status)->toBe('pending_payment')
        ->and($this->sessions->retrieve('cs_test_fake_1')->status)->toBe('open');

    $this->travel(2)->seconds();
    reconcile($this);
    expect($order->fresh()->status)->toBe('cancelled');
});

test('a payment made as the session closes is recorded rather than released', function () {
    $order = placeOrder($this);
    $this->sessions->markPaid('cs_test_fake_1', 'pi_paid');

    $this->travel(PAST_EXPIRY_AND_GRACE_MINUTES)->minutes();
    reconcile($this);

    expect($order->fresh()->status)->toBe('paid')
        ->and($order->fresh()->payment_status)->toBe('paid')
        ->and(Payment::query()->sole()->provider_payment_id)->toBe('pi_paid')
        ->and($this->balance->fresh()->on_hand)->toBe(4)
        ->and($this->balance->fresh()->reserved)->toBe(0)
        ->and(OrderEvent::query()->where('event_type', 'payment_reconciled')->count())->toBe(1)
        ->and(CartItem::query()->count())->toBe(0);
    Mail::assertSent(OrderConfirmationMail::class, 1);
});

test('a session Stripe has already expired is released', function () {
    $order = placeOrder($this);
    $this->sessions->expire('cs_test_fake_1');

    $this->travel(PAST_EXPIRY_AND_GRACE_MINUTES)->minutes();
    reconcile($this);

    expect($order->fresh()->status)->toBe('cancelled');
});

test('a missed webhook is caught before the session expires', function () {
    $order = placeOrder($this);
    $this->sessions->markPaid('cs_test_fake_1', 'pi_missed');

    $this->travel(9)->minutes();
    reconcile($this);
    expect($order->fresh()->status)->toBe('pending_payment');

    $this->travel(1)->minutes();
    reconcile($this);
    expect($order->fresh()->status)->toBe('paid');
});

test('a late webhook after reconciling applies nothing twice', function () {
    $order = placeOrder($this);
    $payment = Payment::query()->sole();
    $this->sessions->markPaid('cs_test_fake_1', 'pi_late');
    $this->travel(10)->minutes();
    reconcile($this);

    $payload = json_encode([
        'id' => 'evt_late_webhook',
        'object' => 'event',
        'api_version' => '2025-02-24.acacia',
        'created' => now()->timestamp,
        'data' => ['object' => [
            'id' => 'cs_test_fake_1',
            'object' => 'checkout.session',
            'status' => 'complete',
            'payment_status' => 'paid',
            'amount_total' => $order->total_minor,
            'currency' => 'jpy',
            'client_reference_id' => $order->order_number,
            'payment_intent' => 'pi_late',
            'metadata' => ['ec_order_id' => (string) $order->id, 'ec_payment_id' => (string) $payment->id],
        ]],
        'livemode' => false,
        'pending_webhooks' => 1,
        'type' => 'checkout.session.completed',
    ], JSON_THROW_ON_ERROR);
    $this->call('POST', route('stripe.ecommerce.webhook'), server: [
        'HTTP_STRIPE_SIGNATURE' => WebhookSignature::generateSignatureHeader($payload, 'whsec_reconcile_test'),
        'CONTENT_TYPE' => 'application/json',
    ], content: $payload)->assertOk();

    expect($this->balance->fresh()->on_hand)->toBe(4)
        ->and(InventoryMovement::query()->where('movement_type', 'sale')->count())->toBe(1);
    Mail::assertSent(OrderConfirmationMail::class, 1);
});

test('a reconciled payment with the wrong amount goes to review', function () {
    $order = placeOrder($this);
    $this->sessions->markPaid('cs_test_fake_1', 'pi_short');
    Payment::query()->update(['amount_minor' => 9999]);

    $this->travel(10)->minutes();
    reconcile($this);

    expect($order->fresh()->status)->toBe('payment_review')
        ->and($order->fresh()->review_reason)->toBe('amount_mismatch');
    Mail::assertNotSent(OrderConfirmationMail::class);
    Mail::assertSent(OrderPaymentReviewMail::class, 1);
});

test('a session with mismatched references is never applied', function () {
    $order = placeOrder($this);
    $this->sessions->markPaid('cs_test_fake_1', 'pi_other');
    Order::query()->update(['order_number' => 'ECOTHER000001']);

    $this->travel(10)->minutes();
    reconcile($this);

    expect($order->fresh()->status)->toBe('pending_payment')
        ->and($this->balance->fresh()->on_hand)->toBe(5);
});

test('a completed but unpaid session holds the stock and alerts once', function () {
    $order = placeOrder($this);
    $paid = $this->sessions->markPaid('cs_test_fake_1', 'pi_unpaid');
    $this->sessions->replaceForTest('cs_test_fake_1', ['payment_status' => 'unpaid']);

    $this->travel(PAST_EXPIRY_AND_GRACE_MINUTES)->minutes();
    reconcile($this);
    reconcile($this);

    expect($order->fresh()->status)->toBe('pending_payment')
        ->and($this->balance->fresh()->reserved)->toBe(1)
        ->and($paid->status)->toBe('complete');
    Mail::assertSent(AdminOrderAlertMail::class, fn ($mail) => $mail->alertType === 'unpaid_complete');
    Mail::assertSent(AdminOrderAlertMail::class, 1);
});

test('an uncertain attempt with no session is released once it can no longer be paid', function () {
    $this->sessions->failBeforeStripeReceivesRequest();
    $order = placeOrder($this);

    $this->travel(PAST_EXPIRY_AND_GRACE_MINUTES)->minutes();
    reconcile($this);

    expect($order->fresh()->status)->toBe('cancelled');
});

test('an uncertain attempt with an open session has that session closed first', function () {
    $this->sessions->loseResponseAfterStripeCreatesSession();
    $order = placeOrder($this);

    $this->travel(PAST_EXPIRY_AND_GRACE_MINUTES)->minutes();
    reconcile($this);

    expect($this->sessions->retrieve('cs_test_fake_1')->status)->toBe('expired')
        ->and($order->fresh()->status)->toBe('cancelled');
});

test('an uncertain attempt whose session was paid records the payment', function () {
    $this->sessions->loseResponseAfterStripeCreatesSession();
    $order = placeOrder($this);
    $this->sessions->markPaid('cs_test_fake_1', 'pi_lost_response');

    $this->travel(PAST_EXPIRY_AND_GRACE_MINUTES)->minutes();
    reconcile($this);

    $payment = Payment::query()->sole();
    expect($order->fresh()->status)->toBe('paid')
        ->and($payment->provider_checkout_id)->toBe('cs_test_fake_1')
        ->and($payment->checkout_state)->toBe('created')
        ->and($payment->checkout_request_payload)->toBeNull();
});

test('nothing is released while Stripe is unreachable, and a long outage alerts once', function () {
    $this->sessions->failBeforeStripeReceivesRequest();
    $order = placeOrder($this);

    $this->travel(PAST_EXPIRY_AND_GRACE_MINUTES)->minutes();
    $this->sessions->failNextList(new ApiConnectionException('Fake: outage.'));
    reconcile($this);

    expect($order->fresh()->status)->toBe('pending_payment')
        ->and($this->balance->fresh()->reserved)->toBe(1);
    Mail::assertNotSent(AdminOrderAlertMail::class);

    $this->travel(30)->minutes();
    $this->sessions->failNextList(new ApiConnectionException('Fake: outage.'));
    reconcile($this);
    $this->sessions->failNextList(new ApiConnectionException('Fake: outage.'));
    reconcile($this);

    expect($order->fresh()->status)->toBe('pending_payment');
    Mail::assertSent(AdminOrderAlertMail::class, fn ($mail) => $mail->alertType === 'reconcile_stuck');
    Mail::assertSent(AdminOrderAlertMail::class, 1);

    reconcile($this);
    expect($order->fresh()->status)->toBe('cancelled');
});

test('a request still in flight is not reconciled', function () {
    $this->sessions->failBeforeStripeReceivesRequest();
    $order = placeOrder($this);

    $this->travel(PAST_EXPIRY_AND_GRACE_MINUTES)->minutes();
    Payment::query()->update(['checkout_state' => 'creating', 'checkout_claimed_at' => now()]);
    reconcile($this);

    expect($order->fresh()->status)->toBe('pending_payment');
});

test('one failing order does not stop the others', function () {
    $broken = placeOrder($this);
    Payment::query()->update(['checkout_state' => 'not_started']);
    $broken->items()->update(['variant_id' => null]);
    Order::query()->update(['reservation_expires_at' => now()->subHour()]);

    $this->form['checkout_key'] = str_repeat('b', 64);
    $this->form['shipping_city'] = '渋谷区';
    $secondUser = User::factory()->create();
    $secondCart = Cart::query()->create(['user_id' => $secondUser->id]);
    CartItem::query()->create(['cart_id' => $secondCart->id, 'variant_id' => $this->variant->id, 'quantity' => 1]);
    $this->actingAs($secondUser)->post(route('shop.checkout.store'), $this->form)->assertRedirect();
    $healthy = Order::query()->whereKeyNot($broken->id)->sole();

    $this->travel(PAST_EXPIRY_AND_GRACE_MINUTES)->minutes();
    reconcile($this);

    expect($broken->fresh()->status)->toBe('pending_payment')
        ->and($healthy->fresh()->status)->toBe('cancelled');
});

test('a pending refund is settled from Stripe when its webhook never comes', function (array $listed, string $paymentStatus, string $status) {
    $order = placeOrder($this);
    $this->sessions->markPaid('cs_test_fake_1', 'pi_refund');
    $this->travel(10)->minutes();
    reconcile($this);
    Order::query()->update(['payment_status' => 'refund_pending']);
    $this->refunds->willList($listed);

    $this->travel(11)->minutes();
    reconcile($this);

    expect($order->fresh()->payment_status)->toBe($paymentStatus)
        ->and($order->fresh()->status)->toBe($status);
})->with([
    'succeeded' => [[['status' => 'succeeded', 'amount' => 1500]], 'refunded', 'refunded'],
    'still pending' => [[['status' => 'pending', 'amount' => 1500]], 'refund_pending', 'paid'],
    'failed' => [[['status' => 'failed', 'amount' => 1500]], 'paid', 'paid'],
    'partly succeeded' => [[['status' => 'succeeded', 'amount' => 500]], 'refund_review', 'paid'],
    'partly succeeded, rest pending' => [[['status' => 'succeeded', 'amount' => 500], ['status' => 'pending', 'amount' => 1000]], 'refund_pending', 'paid'],
    'split but complete' => [[['status' => 'succeeded', 'amount' => 500], ['status' => 'succeeded', 'amount' => 1000]], 'refunded', 'refunded'],
    'nothing listed' => [[], 'refund_pending', 'paid'],
]);

test('a refund is not checked until it has had time to arrive by webhook', function () {
    $order = placeOrder($this);
    $this->sessions->markPaid('cs_test_fake_1', 'pi_refund');
    $this->travel(10)->minutes();
    reconcile($this);
    Order::query()->update(['payment_status' => 'refund_pending']);
    $this->refunds->willList([['status' => 'succeeded', 'amount' => 1500]]);

    $this->travel(9)->minutes();
    reconcile($this);

    expect($order->fresh()->payment_status)->toBe('refund_pending');
    Mail::assertNotSent(OrderRefundCompletedMail::class);
});

test('a refund check that fails changes nothing', function () {
    $order = placeOrder($this);
    $this->sessions->markPaid('cs_test_fake_1', 'pi_refund');
    $this->travel(10)->minutes();
    reconcile($this);
    Order::query()->update(['payment_status' => 'refund_pending']);
    $this->refunds->willFail(new ApiConnectionException('Fake: outage.'));

    $this->travel(11)->minutes();
    reconcile($this);

    expect($order->fresh()->payment_status)->toBe('refund_pending');
});

test('an overdue review alerts once and is never refunded automatically', function () {
    $order = placeOrder($this);
    $this->sessions->markPaid('cs_test_fake_1', 'pi_review');
    Payment::query()->update(['amount_minor' => 9999]);
    $this->travel(10)->minutes();
    reconcile($this);
    expect($order->fresh()->status)->toBe('payment_review');
    Mail::assertSent(AdminOrderAlertMail::class, 1);

    $this->travel(23)->hours();
    reconcile($this);
    Mail::assertSent(AdminOrderAlertMail::class, 1);

    $this->travel(1)->hours();
    reconcile($this);
    reconcile($this);

    Mail::assertSent(AdminOrderAlertMail::class, 2);
    Mail::assertSent(AdminOrderAlertMail::class, fn ($mail) => $mail->alertType === 'review_overdue');
    expect($order->fresh()->status)->toBe('payment_review')
        ->and($this->refunds->createCalls)->toBe([]);
});

test('a resolved review raises no overdue alert', function () {
    $order = placeOrder($this);
    $this->sessions->markPaid('cs_test_fake_1', 'pi_review');
    Payment::query()->update(['amount_minor' => 9999]);
    $this->travel(10)->minutes();
    reconcile($this);
    Order::query()->update(['review_resolved_at' => now()]);

    $this->travel(25)->hours();
    reconcile($this);

    Mail::assertSent(AdminOrderAlertMail::class, 1);
});

test('the audit passes when balances match the ledger', function () {
    app(InventoryService::class)->setOnHand($this->variant, 5, User::factory()->admin()->create(), 'seed');
    InventoryMovement::query()->create([
        'variant_id' => $this->variant->id,
        'movement_type' => 'admin_adjustment',
        'on_hand_delta' => 5,
        'idempotency_key' => str_repeat('c', 64),
    ]);

    $this->artisan('ecommerce:audit-inventory')->assertExitCode(0);

    Mail::assertNothingSent();
});

test('the audit reports differences and changes nothing', function (array $balance) {
    InventoryMovement::query()->create([
        'variant_id' => $this->variant->id,
        'movement_type' => 'admin_adjustment',
        'on_hand_delta' => 5,
        'idempotency_key' => str_repeat('c', 64),
    ]);
    $this->balance->update($balance);
    $before = $this->balance->fresh()->only(['on_hand', 'reserved']);

    $this->artisan('ecommerce:audit-inventory')->assertExitCode(1);

    expect($this->balance->fresh()->only(['on_hand', 'reserved']))->toBe($before)
        ->and(InventoryMovement::query()->count())->toBe(1);
    Mail::assertSent(AdminInventoryAuditMail::class, fn ($mail) => $mail->hasTo('admin@example.test')
        && $mail->differences[0]['variant_id'] === $this->variant->id);
})->with([
    'on hand differs' => [['on_hand' => 9]],
    'reserved differs' => [['reserved' => 2]],
]);

test('the audit still exits with failure when no contact address is configured', function () {
    config(['services.contact.email' => '']);
    $this->balance->update(['on_hand' => 9]);

    $this->artisan('ecommerce:audit-inventory')->assertExitCode(1);

    Mail::assertNothingSent();
});

test('the audit email lists the difference', function () {
    $mail = new AdminInventoryAuditMail([['variant_id' => 7, 'on_hand' => 9, 'reserved' => 0, 'ledger_on_hand' => 5, 'ledger_reserved' => 0]]);

    $mail->assertSeeInHtml('商品バリエーション 7');
    $mail->assertSeeInHtml('在庫は自動で修正していません');
});

test('both jobs are scheduled', function () {
    $commands = collect(app(Schedule::class)->events())->map(fn ($event) => [$event->command, $event->expression]);

    expect($commands->first(fn (array $event) => str_contains($event[0], 'ecommerce:expire-reservations'))[1])->toBe('* * * * *')
        ->and($commands->first(fn (array $event) => str_contains($event[0], 'ecommerce:audit-inventory'))[1])->toBe('0 3 * * *');
});

test('the service resolves without a configured Stripe key for orders that never reached Stripe', function () {
    app()->forgetInstance(StripeBillingService::class);
    config(['services.stripe.secret' => null]);
    $order = Order::query()->create([
        'order_number' => 'ECNOTSTARTED01',
        'idempotency_key' => str_repeat('d', 64),
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
        'subtotal_minor' => 0,
        'shipping_minor' => 0,
        'total_minor' => 0,
        'currency' => 'JPY',
        'reservation_expires_at' => now()->subSecond(),
    ]);
    Payment::query()->create([
        'order_id' => $order->id,
        'provider' => 'stripe',
        'status' => 'pending',
        'amount_minor' => 0,
        'currency' => 'JPY',
        'idempotency_key' => 'ec-not-started',
    ]);

    app(PaymentReconciliationService::class)->run();

    expect($order->fresh()->status)->toBe('cancelled');
});

test('the fake refunds list stands in for the real API', function () {
    $refunds = (new FakeStripeRefunds)->willList([['status' => 'succeeded', 'amount' => 10]]);

    expect($refunds->all()->data[0])->toBeInstanceOf(Refund::class);
});
