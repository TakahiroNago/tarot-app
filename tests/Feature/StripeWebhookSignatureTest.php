<?php

use App\Models\Order;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

const WEBHOOK_TEST_SECRET = 'whsec_signature_test';

beforeEach(function () {
    Mail::fake();
    config([
        'services.stripe.webhook_secret' => WEBHOOK_TEST_SECRET,
        'services.stripe.ecommerce_webhook_secret' => WEBHOOK_TEST_SECRET,
        'services.stripe.ecommerce_secret' => 'sk_test_fake',
    ]);
    $this->order = Order::query()->create([
        'order_number' => 'ECSIGNATURE0001',
        'idempotency_key' => str_repeat('s', 64),
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
    $this->payment = Payment::query()->create([
        'order_id' => $this->order->id,
        'provider' => 'stripe',
        'provider_checkout_id' => 'cs_signature',
        'status' => 'pending',
        'amount_minor' => 1500,
        'currency' => 'JPY',
        'idempotency_key' => 'ec-signature',
    ]);
    $this->payload = json_encode([
        'id' => 'evt_signature',
        'object' => 'event',
        'api_version' => '2025-02-24.acacia',
        'created' => now()->timestamp,
        'data' => ['object' => [
            'id' => 'cs_signature',
            'object' => 'checkout.session',
            'status' => 'complete',
            'payment_status' => 'paid',
            'amount_total' => 1500,
            'currency' => 'jpy',
            'payment_intent' => 'pi_signature',
            'client_reference_id' => 'ECSIGNATURE0001',
            'metadata' => ['ec_order_id' => (string) $this->order->id, 'ec_payment_id' => (string) $this->payment->id],
        ]],
        'livemode' => false,
        'pending_webhooks' => 1,
        'type' => 'checkout.session.completed',
    ], JSON_THROW_ON_ERROR);
});

function webhookSignature(string $payload, string $secret = WEBHOOK_TEST_SECRET, ?int $timestamp = null): string
{
    $timestamp ??= time();

    return "t={$timestamp},v1=".hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);
}

function sendSignedWebhook(mixed $test, string $routeName, string $payload, ?string $signature): mixed
{
    $server = ['CONTENT_TYPE' => 'application/json'];
    if ($signature !== null) {
        $server['HTTP_STRIPE_SIGNATURE'] = $signature;
    }

    return $test->call('POST', route($routeName), server: $server, content: $payload);
}

test('a correctly signed event is accepted and applied', function () {
    sendSignedWebhook($this, 'stripe.ecommerce.webhook', $this->payload, webhookSignature($this->payload))->assertOk();

    expect($this->order->fresh()->payment_status)->toBe('paid');
});

test('the shop webhook refuses anything not signed by Stripe and records nothing', function (Closure $attempt) {
    [$body, $signature] = $attempt($this->payload);

    sendSignedWebhook($this, 'stripe.ecommerce.webhook', $body, $signature)->assertBadRequest();

    expect($this->order->fresh()->payment_status)->toBe('pending')
        ->and(DB::table('ec_payment_webhook_events')->count())->toBe(0);
})->with([
    'no signature' => [fn (string $payload) => [$payload, null]],
    'an empty signature' => [fn (string $payload) => [$payload, '']],
    'signed with another secret' => [fn (string $payload) => [$payload, webhookSignature($payload, 'whsec_someone_else')]],
    'a body changed after signing' => [fn (string $payload) => [str_replace('"amount_total":1500', '"amount_total":1', $payload), webhookSignature($payload)]],
    'an old signature replayed' => [fn (string $payload) => [$payload, webhookSignature($payload, timestamp: time() - 301)]],
    'a body that is not JSON' => [fn () => ['not json', webhookSignature('not json')]],
]);

test('a signature within the allowed age is accepted', function () {
    sendSignedWebhook($this, 'stripe.ecommerce.webhook', $this->payload, webhookSignature($this->payload, timestamp: time() - 240))->assertOk();
});

test('a webhook with no secret configured refuses events rather than trusting them', function (string $routeName, string $configKey) {
    config([$configKey => '']);

    sendSignedWebhook($this, $routeName, $this->payload, webhookSignature($this->payload, ''))->assertServiceUnavailable();

    expect($this->order->fresh()->payment_status)->toBe('pending');
})->with([
    'shop' => ['stripe.ecommerce.webhook', 'services.stripe.ecommerce_webhook_secret'],
    'tarot' => ['stripe.webhook', 'services.stripe.webhook_secret'],
]);

test('the tarot webhook refuses anything not signed by Stripe and records nothing', function (Closure $attempt) {
    [$body, $signature] = $attempt($this->payload);

    sendSignedWebhook($this, 'stripe.webhook', $body, $signature)->assertBadRequest();

    expect(DB::table('stripe_webhook_events')->count())->toBe(0);
})->with([
    'no signature' => [fn (string $payload) => [$payload, null]],
    'signed with another secret' => [fn (string $payload) => [$payload, webhookSignature($payload, 'whsec_someone_else')]],
    'a body changed after signing' => [fn (string $payload) => [str_replace('evt_signature', 'evt_forged', $payload), webhookSignature($payload)]],
    'an old signature replayed' => [fn (string $payload) => [$payload, webhookSignature($payload, timestamp: time() - 301)]],
]);

test('a shop event signed for the tarot webhook secret is refused', function () {
    config(['services.stripe.webhook_secret' => 'whsec_tarot_only']);

    sendSignedWebhook($this, 'stripe.ecommerce.webhook', $this->payload, webhookSignature($this->payload, 'whsec_tarot_only'))->assertBadRequest();

    expect($this->order->fresh()->payment_status)->toBe('pending');
});

test('the same signed event delivered twice takes effect once', function () {
    $signature = webhookSignature($this->payload);

    sendSignedWebhook($this, 'stripe.ecommerce.webhook', $this->payload, $signature)->assertOk();
    $paidAt = $this->payment->fresh()->updated_at;
    $this->travel(5)->seconds();
    sendSignedWebhook($this, 'stripe.ecommerce.webhook', $this->payload, $signature)->assertOk();

    expect(DB::table('ec_payment_webhook_events')->where('event_id', 'evt_signature')->count())->toBe(1)
        ->and($this->payment->fresh()->updated_at->equalTo($paidAt))->toBeTrue();
});
