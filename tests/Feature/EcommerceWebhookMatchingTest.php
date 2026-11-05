<?php

use App\Mail\AdminWebhookAlertMail;
use App\Mail\OrderConfirmationMail;
use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Stripe\WebhookSignature;
use Tests\Support\Stripe\FakeStripeBillingService;

uses(RefreshDatabase::class);

beforeEach(function () {
    Mail::fake();
    config([
        'services.stripe.ecommerce_webhook_secret' => 'whsec_matching_test',
        'services.contact.email' => 'admin@example.test',
    ]);

    $product = Product::factory()->create();
    $this->variant = ProductVariant::factory()->for($product)->create();
    $this->balance = InventoryBalance::factory()->for($this->variant, 'variant')->create(['on_hand' => 5, 'reserved' => 1]);
    $this->order = Order::query()->create([
        'order_number' => 'EC260101MATCH001',
        'idempotency_key' => str_repeat('a', 64),
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
    ]);
    OrderItem::query()->create([
        'order_id' => $this->order->id,
        'product_id' => $product->id,
        'variant_id' => $this->variant->id,
        'product_name_snapshot' => $product->name,
        'variant_label_snapshot' => $this->variant->label,
        'sku_snapshot' => $this->variant->sku,
        'unit_price_minor' => 1000,
        'quantity' => 1,
        'line_total_minor' => 1000,
        'currency' => 'JPY',
    ]);
    InventoryMovement::query()->create([
        'variant_id' => $this->variant->id,
        'order_id' => $this->order->id,
        'movement_type' => 'reservation',
        'reserved_delta' => 1,
        'idempotency_key' => InventoryService::movementKey('reservation', $this->order->id, $this->variant->id),
    ]);
    $this->payment = Payment::query()->create([
        'order_id' => $this->order->id,
        'provider' => 'stripe',
        'status' => 'pending',
        'amount_minor' => 1500,
        'currency' => 'JPY',
        'idempotency_key' => 'ec-matching',
        'checkout_state' => 'uncertain',
        'checkout_request_payload' => ['mode' => 'payment'],
    ]);
});

/**
 * @param  array<string, mixed>  $object
 * @param  array<string, mixed>  $event
 * @return array{response: mixed, id: string}
 */
function sendStripeEvent(mixed $test, string $type, array $object, array $event = []): array
{
    $id = $event['id'] ?? 'evt_'.bin2hex(random_bytes(8));
    $payload = json_encode([
        'id' => $id,
        'object' => 'event',
        'api_version' => '2025-02-24.acacia',
        'created' => now()->timestamp,
        'data' => ['object' => $object],
        'livemode' => false,
        'pending_webhooks' => 1,
        'type' => $type,
        ...$event,
    ], JSON_THROW_ON_ERROR);

    return [
        'id' => $id,
        'response' => $test->call('POST', route('stripe.ecommerce.webhook'), server: [
            'HTTP_STRIPE_SIGNATURE' => WebhookSignature::generateSignatureHeader($payload, 'whsec_matching_test'),
            'CONTENT_TYPE' => 'application/json',
        ], content: $payload),
    ];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function matchingSession(mixed $test, string $id, array $overrides = []): array
{
    return [
        'id' => $id,
        'object' => 'checkout.session',
        'status' => 'complete',
        'payment_status' => 'paid',
        'amount_total' => 1500,
        'currency' => 'jpy',
        'client_reference_id' => $test->order->order_number,
        'payment_intent' => 'pi_'.$id,
        'metadata' => ['ec_order_id' => (string) $test->order->id, 'ec_payment_id' => (string) $test->payment->id],
        ...$overrides,
    ];
}

/**
 * @return object{status: string, failure_reason: string|null, processed_at: string|null}
 */
function ledgerEntry(string $eventId): object
{
    return DB::table('ec_payment_webhook_events')->where('event_id', $eventId)->sole();
}

test('a paid session whose id was never saved is adopted and settles the order', function () {
    $sent = sendStripeEvent($this, 'checkout.session.completed', matchingSession($this, 'cs_orphan'));

    $sent['response']->assertOk();
    $payment = $this->payment->fresh();
    expect($payment->provider_checkout_id)->toBe('cs_orphan')
        ->and($payment->provider_payment_id)->toBe('pi_cs_orphan')
        ->and($payment->checkout_state)->toBe('created')
        ->and($payment->checkout_request_payload)->toBeNull()
        ->and($this->order->fresh()->status)->toBe('paid')
        ->and($this->balance->fresh()->on_hand)->toBe(4)
        ->and(ledgerEntry($sent['id'])->status)->toBe('processed');
    Mail::assertSent(OrderConfirmationMail::class, 1);
    Mail::assertNotSent(AdminWebhookAlertMail::class);
});

test('a paid unsaved session on a released order goes to review, not a sale', function () {
    $this->order->update(['status' => 'cancelled', 'payment_status' => 'failed', 'reservation_expires_at' => null]);
    $this->balance->update(['reserved' => 0]);

    $sent = sendStripeEvent($this, 'checkout.session.completed', matchingSession($this, 'cs_orphan'));

    $sent['response']->assertOk();
    expect($this->order->fresh()->status)->toBe('payment_review')
        ->and($this->order->fresh()->review_reason)->toBe('late_payment')
        ->and($this->payment->fresh()->provider_checkout_id)->toBe('cs_orphan')
        ->and($this->balance->fresh()->on_hand)->toBe(5);
});

test('an expired or failed unsaved session changes nothing', function (string $type, array $session) {
    $sent = sendStripeEvent($this, $type, matchingSession($this, 'cs_orphan', $session));

    $sent['response']->assertOk();
    expect($this->order->fresh()->status)->toBe('pending_payment')
        ->and($this->payment->fresh()->provider_checkout_id)->toBeNull()
        ->and($this->payment->fresh()->checkout_state)->toBe('uncertain')
        ->and($this->balance->fresh()->reserved)->toBe(1)
        ->and(ledgerEntry($sent['id'])->status)->toBe('ignored');
    Mail::assertNotSent(AdminWebhookAlertMail::class);
})->with([
    'expired' => ['checkout.session.expired', ['status' => 'expired', 'payment_status' => 'unpaid']],
    'async failed' => ['checkout.session.async_payment_failed', ['payment_status' => 'unpaid']],
    'completed but unpaid' => ['checkout.session.completed', ['payment_status' => 'unpaid']],
]);

test('a paid session replacing a saved open one expires the old session', function () {
    $sessions = FakeStripeBillingService::install(app());
    $saved = $sessions->create([
        'mode' => 'payment',
        'expires_at' => now()->addMinutes(30)->timestamp,
        'line_items' => [['price_data' => ['currency' => 'jpy', 'unit_amount' => 1500], 'quantity' => 1]],
    ], ['idempotency_key' => 'saved']);
    $this->payment->update(['provider_checkout_id' => $saved->id]);

    sendStripeEvent($this, 'checkout.session.completed', matchingSession($this, 'cs_paid_earlier'))['response']->assertOk();

    expect($this->payment->fresh()->provider_checkout_id)->toBe('cs_paid_earlier')
        ->and($this->order->fresh()->status)->toBe('paid')
        ->and($sessions->retrieve($saved->id)->status)->toBe('expired');
});

test('failing to expire the old session does not undo the payment', function () {
    $this->payment->update(['provider_checkout_id' => 'cs_saved']);

    sendStripeEvent($this, 'checkout.session.completed', matchingSession($this, 'cs_paid_earlier'))['response']->assertOk();

    expect($this->order->fresh()->status)->toBe('paid');
});

test('a second paid session leaves the valid order alone and asks for a manual refund', function (string $status, string $paymentStatus) {
    $this->order->update(['status' => $status, 'payment_status' => $paymentStatus]);
    $this->payment->update(['provider_checkout_id' => 'cs_first', 'provider_payment_id' => 'pi_first', 'status' => 'succeeded']);
    $onHand = $this->balance->fresh()->on_hand;

    $sent = sendStripeEvent($this, 'checkout.session.completed', matchingSession($this, 'cs_second'));

    $sent['response']->assertOk();
    expect($this->order->fresh()->status)->toBe($status)
        ->and($this->order->fresh()->payment_status)->toBe($paymentStatus)
        ->and($this->payment->fresh()->provider_checkout_id)->toBe('cs_first')
        ->and($this->payment->fresh()->provider_payment_id)->toBe('pi_first')
        ->and($this->balance->fresh()->on_hand)->toBe($onHand)
        ->and(ledgerEntry($sent['id'])->status)->toBe('unmatched')
        ->and(ledgerEntry($sent['id'])->failure_reason)->toContain('pi_cs_second')
        ->and(OrderEvent::query()->where('event_type', 'duplicate_payment_detected')->sole()->note)->toContain('pi_cs_second');
    Mail::assertSent(AdminWebhookAlertMail::class, fn ($mail) => str_contains($mail->reason, 'pi_cs_second') && $mail->hasTo('admin@example.test'));
    Mail::assertNotSent(OrderConfirmationMail::class);
})->with([
    'paid' => ['paid', 'paid'],
    'shipped' => ['shipped', 'paid'],
    'in review' => ['payment_review', 'payment_review'],
    'refunding' => ['paid', 'refund_pending'],
    'refunded' => ['refunded', 'refunded'],
]);

test('a session with no ecommerce metadata is ignored without alarm', function () {
    $sent = sendStripeEvent($this, 'checkout.session.completed', [
        'id' => 'cs_other_product',
        'object' => 'checkout.session',
        'status' => 'complete',
        'payment_status' => 'paid',
        'metadata' => [],
    ]);

    $sent['response']->assertOk();
    expect(ledgerEntry($sent['id'])->status)->toBe('ignored')
        ->and($this->order->fresh()->status)->toBe('pending_payment');
    Mail::assertNotSent(AdminWebhookAlertMail::class);
});

test('ecommerce metadata naming no payment is recorded and raises an alert', function () {
    $sent = sendStripeEvent($this, 'checkout.session.completed', matchingSession($this, 'cs_lost', [
        'metadata' => ['ec_order_id' => '999', 'ec_payment_id' => '999'],
    ]));

    $sent['response']->assertOk();
    expect(ledgerEntry($sent['id'])->status)->toBe('unmatched');
    Mail::assertSent(AdminWebhookAlertMail::class, 1);
});

test('metadata that disagrees with the order is never applied', function (Closure $session, bool $saved) {
    if ($saved) {
        $this->payment->update(['provider_checkout_id' => 'cs_mismatch']);
    }

    $sent = sendStripeEvent($this, 'checkout.session.completed', $session($this));

    $sent['response']->assertOk();
    expect(ledgerEntry($sent['id'])->status)->toBe('unmatched')
        ->and($this->order->fresh()->status)->toBe('pending_payment')
        ->and($this->balance->fresh()->on_hand)->toBe(5)
        ->and($this->payment->fresh()->provider_payment_id)->toBeNull();
    Mail::assertSent(AdminWebhookAlertMail::class, 1);
    Mail::assertNotSent(OrderConfirmationMail::class);
})->with([
    'wrong order id, saved session' => [fn (mixed $test) => matchingSession($test, 'cs_mismatch', ['metadata' => ['ec_order_id' => '999', 'ec_payment_id' => (string) $test->payment->id]]), true],
    'wrong reference, saved session' => [fn (mixed $test) => matchingSession($test, 'cs_mismatch', ['client_reference_id' => 'EC-OTHER']), true],
    'wrong order id, unsaved session' => [fn (mixed $test) => matchingSession($test, 'cs_mismatch', ['metadata' => ['ec_order_id' => '999', 'ec_payment_id' => (string) $test->payment->id]]), false],
    'wrong reference, unsaved session' => [fn (mixed $test) => matchingSession($test, 'cs_mismatch', ['client_reference_id' => 'EC-OTHER']), false],
]);

test('async success without a paid status raises an alert instead of a retry loop', function () {
    $this->payment->update(['provider_checkout_id' => 'cs_saved']);

    $sent = sendStripeEvent($this, 'checkout.session.async_payment_succeeded', matchingSession($this, 'cs_saved', ['payment_status' => 'unpaid']));

    $sent['response']->assertOk();
    expect(ledgerEntry($sent['id'])->status)->toBe('unmatched')
        ->and($this->order->fresh()->status)->toBe('pending_payment');
});

test('an event type the service does not handle is ignored', function () {
    $sent = sendStripeEvent($this, 'customer.created', ['id' => 'cus_1', 'object' => 'customer']);

    $sent['response']->assertOk();
    expect(ledgerEntry($sent['id'])->status)->toBe('ignored')
        ->and(ledgerEntry($sent['id'])->processed_at)->not->toBeNull();
    Mail::assertNotSent(AdminWebhookAlertMail::class);
});

test('an event from the wrong Stripe mode changes nothing', function (string $secret, bool $livemode) {
    config(['services.stripe.secret' => $secret]);
    $this->payment->update(['provider_checkout_id' => 'cs_saved']);

    $sent = sendStripeEvent($this, 'checkout.session.completed', matchingSession($this, 'cs_saved'), ['livemode' => $livemode]);

    $sent['response']->assertOk();
    expect(ledgerEntry($sent['id'])->status)->toBe('ignored')
        ->and($this->order->fresh()->status)->toBe('pending_payment');
    Mail::assertSent(AdminWebhookAlertMail::class, 1);
})->with([
    'live event, test key' => ['sk_test_fake', true],
    'test event, live key' => ['sk_live_fake', false],
    'test event, restricted live key' => ['rk_live_fake', false],
]);

test('an event from the matching Stripe mode is applied', function (string $secret, bool $livemode) {
    config(['services.stripe.secret' => $secret]);
    $this->payment->update(['provider_checkout_id' => 'cs_saved']);

    sendStripeEvent($this, 'checkout.session.completed', matchingSession($this, 'cs_saved'), ['livemode' => $livemode])['response']->assertOk();

    expect($this->order->fresh()->status)->toBe('paid');
})->with([
    'test' => ['sk_test_fake', false],
    'live' => ['sk_live_fake', true],
]);

test('a duplicate delivery raises no second alert', function () {
    $event = ['id' => 'evt_unmatched_once'];
    $session = matchingSession($this, 'cs_lost', ['metadata' => ['ec_order_id' => '999', 'ec_payment_id' => '999']]);

    sendStripeEvent($this, 'checkout.session.completed', $session, $event)['response']->assertOk();
    sendStripeEvent($this, 'checkout.session.completed', $session, $event)['response']->assertOk();

    Mail::assertSent(AdminWebhookAlertMail::class, 1);
});

test('a refund for a payment not yet recorded asks Stripe to retry when it is ours', function () {
    $sent = sendStripeEvent($this, 'charge.refunded', [
        'id' => 'ch_1',
        'object' => 'charge',
        'payment_intent' => 'pi_not_yet',
        'refunded' => true,
        'amount_refunded' => 1500,
        'currency' => 'jpy',
        'metadata' => ['ec_order_id' => (string) $this->order->id],
    ]);

    $sent['response']->assertServerError();
    expect(DB::table('ec_payment_webhook_events')->where('event_id', $sent['id'])->exists())->toBeFalse();
    Mail::assertNotSent(AdminWebhookAlertMail::class);
});

test('the retried refund applies once its payment has been recorded', function () {
    $event = ['id' => 'evt_refund_retried'];
    $charge = [
        'id' => 'ch_1',
        'object' => 'charge',
        'payment_intent' => 'pi_cs_saved',
        'refunded' => true,
        'amount_refunded' => 1500,
        'currency' => 'jpy',
        'metadata' => ['ec_order_id' => (string) $this->order->id],
    ];
    $this->payment->update(['provider_checkout_id' => 'cs_saved']);

    sendStripeEvent($this, 'charge.refunded', $charge, $event)['response']->assertServerError();
    sendStripeEvent($this, 'checkout.session.completed', matchingSession($this, 'cs_saved'))['response']->assertOk();
    sendStripeEvent($this, 'charge.refunded', $charge, $event)['response']->assertOk();

    expect($this->order->fresh()->status)->toBe('refunded')
        ->and(ledgerEntry('evt_refund_retried')->status)->toBe('processed');
});

test('a refund with no way to match is recorded and raises an alert', function (string $type, array $object) {
    $sent = sendStripeEvent($this, $type, ['payment_intent' => 'pi_someone_else', 'currency' => 'jpy', ...$object]);

    $sent['response']->assertOk();
    expect(ledgerEntry($sent['id'])->status)->toBe('unmatched');
    Mail::assertSent(AdminWebhookAlertMail::class, 1);
})->with([
    'refund.updated' => ['refund.updated', ['id' => 're_1', 'object' => 'refund', 'status' => 'succeeded', 'amount' => 100]],
    'charge without metadata' => ['charge.refunded', ['id' => 'ch_2', 'object' => 'charge', 'refunded' => true, 'amount_refunded' => 100, 'metadata' => []]],
]);

test('a refund with no payment intent is ignored', function () {
    $sent = sendStripeEvent($this, 'refund.updated', ['id' => 're_2', 'object' => 'refund', 'status' => 'succeeded', 'amount' => 100]);

    $sent['response']->assertOk();
    expect(ledgerEntry($sent['id'])->status)->toBe('ignored');
});

test('a successful refund is recorded as processed', function () {
    $this->order->update(['status' => 'paid', 'payment_status' => 'paid']);
    $this->payment->update(['provider_payment_id' => 'pi_refund', 'provider_checkout_id' => 'cs_saved', 'status' => 'succeeded']);

    $sent = sendStripeEvent($this, 'refund.updated', [
        'id' => 're_3',
        'object' => 'refund',
        'payment_intent' => 'pi_refund',
        'status' => 'succeeded',
        'amount' => 1500,
        'currency' => 'jpy',
    ]);

    expect(ledgerEntry($sent['id'])->status)->toBe('processed')
        ->and($this->order->fresh()->status)->toBe('refunded');
});

test('a database failure still reaches Stripe as a retryable error, and nothing is half applied', function () {
    $this->payment->update(['provider_checkout_id' => 'cs_saved']);
    InventoryMovement::creating(function (): void {
        throw new RuntimeException('Simulated database failure.');
    });

    $sent = sendStripeEvent($this, 'checkout.session.completed', matchingSession($this, 'cs_saved'));

    $sent['response']->assertServerError();
    expect(DB::table('ec_payment_webhook_events')->where('event_id', $sent['id'])->exists())->toBeFalse()
        ->and($this->order->fresh()->status)->toBe('pending_payment')
        ->and($this->balance->fresh()->on_hand)->toBe(5);
});

test('the alert names the problem and no customer details', function () {
    $mail = new AdminWebhookAlertMail('evt_1', 'charge.refunded', 'No payment matches payment intent pi_1.');

    $mail->assertSeeInHtml('evt_1');
    $mail->assertSeeInHtml('pi_1');
    $mail->assertDontSeeInHtml('buyer@example.test');
});

test('the alert is skipped when no contact address is configured', function () {
    config(['services.contact.email' => '']);

    sendStripeEvent($this, 'checkout.session.completed', matchingSession($this, 'cs_lost', [
        'metadata' => ['ec_order_id' => '999', 'ec_payment_id' => '999'],
    ]))['response']->assertOk();

    Mail::assertNotSent(AdminWebhookAlertMail::class);
});
