<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Stripe\WebhookSignature;

uses(RefreshDatabase::class);

test('subscription plan routes no longer exist', function () {
    $this->get('/plans')->assertNotFound();
    $this->post('/billing/checkout')->assertNotFound();
    $this->post('/billing/plan')->assertNotFound();
});

test('invalid webhook signatures are rejected', function () {
    config(['services.stripe.webhook_secret' => 'whsec_test']);

    $this->call(
        'POST',
        route('stripe.webhook'),
        server: ['HTTP_STRIPE_SIGNATURE' => 't=1,v1=invalid', 'CONTENT_TYPE' => 'application/json'],
        content: '{}',
    )->assertBadRequest();
});

test('signed webhook events are recorded once', function () {
    config(['services.stripe.webhook_secret' => 'whsec_test']);
    $payload = json_encode([
        'id' => 'evt_test_once',
        'object' => 'event',
        'api_version' => '2025-02-24.acacia',
        'created' => now()->timestamp,
        'data' => ['object' => ['id' => 'cs_test', 'object' => 'checkout.session']],
        'livemode' => false,
        'pending_webhooks' => 1,
        'type' => 'checkout.session.completed',
    ], JSON_THROW_ON_ERROR);
    $send = fn () => $this->call(
        'POST',
        route('stripe.webhook'),
        server: [
            'HTTP_STRIPE_SIGNATURE' => WebhookSignature::generateSignatureHeader($payload, 'whsec_test'),
            'CONTENT_TYPE' => 'application/json',
        ],
        content: $payload,
    );

    $send()->assertOk();
    $send()->assertOk();

    expect(DB::table('stripe_webhook_events')->where('stripe_event_id', 'evt_test_once')->count())->toBe(1);
});

test('the CA bundle setting chooses the certificates used for Stripe', function (string $configured, ?string $expected) {
    $_ENV['STRIPE_CA_BUNDLE'] = $_SERVER['STRIPE_CA_BUNDLE'] = $configured;

    try {
        $caBundle = (require config_path('services.php'))['stripe']['ca_bundle'];
    } finally {
        unset($_ENV['STRIPE_CA_BUNDLE'], $_SERVER['STRIPE_CA_BUNDLE']);
    }

    expect($caBundle)->toBe($expected);
})->with([
    'an empty value uses the PHP setting' => ['', ini_get('curl.cainfo') ?: null],
    'an explicit path wins' => ['C:\\certificates\\custom.pem', 'C:\\certificates\\custom.pem'],
]);
