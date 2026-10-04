<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Stripe\WebhookSignature;

uses(RefreshDatabase::class);

test('checkout actions require an authenticated member', function () {
    $this->post(route('billing.checkout'), ['product' => 'credits_10'])
        ->assertRedirect(route('login'));
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

test('paid credit checkout webhooks add credits once even when Stripe retries them', function () {
    config([
        'services.stripe.webhook_secret' => 'whsec_test',
        'services.stripe.prices.credits_10' => 'price_credits_10',
    ]);
    $user = User::factory()->create();
    $session = [
        'id' => 'cs_test_credits',
        'object' => 'checkout.session',
        'amount_total' => 100,
        'currency' => 'jpy',
        'mode' => 'payment',
        'payment_status' => 'paid',
        'metadata' => [
            'user_id' => (string) $user->id,
            'product_key' => 'credits_10',
            'price_id' => 'price_credits_10',
        ],
    ];
    $sendEvent = function (string $eventId) use ($session) {
        $payload = json_encode([
            'id' => $eventId,
            'object' => 'event',
            'api_version' => '2025-02-24.acacia',
            'created' => now()->timestamp,
            'data' => ['object' => $session],
            'livemode' => false,
            'pending_webhooks' => 1,
            'type' => 'checkout.session.completed',
        ], JSON_THROW_ON_ERROR);

        return $this->call(
            'POST',
            route('stripe.webhook'),
            server: [
                'HTTP_STRIPE_SIGNATURE' => WebhookSignature::generateSignatureHeader($payload, 'whsec_test'),
                'CONTENT_TYPE' => 'application/json',
            ],
            content: $payload,
        );
    };

    $sendEvent('evt_checkout_first')->assertOk();
    $sendEvent('evt_checkout_first')->assertOk();
    $sendEvent('evt_checkout_retry_different_event_id')->assertOk();

    expect($user->fresh()->purchased_credits)->toBe(10)
        ->and(DB::table('stripe_credit_purchases')->where('checkout_session_id', 'cs_test_credits')->count())->toBe(1)
        ->and(DB::table('stripe_webhook_events')->count())->toBe(2);
});

test('subscription webhooks activate paid plans and preserve period-end cancellation access', function () {
    config([
        'services.stripe.webhook_secret' => 'whsec_test',
        'services.stripe.prices.premium' => 'price_premium',
    ]);
    $user = User::factory()->create();
    $user->forceFill([
        'stripe_customer_id' => 'cus_test',
        'stripe_pending_subscription_checkout_id' => 'cs_pending',
        'stripe_pending_subscription_product' => 'premium',
    ])->save();
    $eventCreatedAt = now()->timestamp;
    $payload = json_encode([
        'id' => 'evt_subscription_updated',
        'object' => 'event',
        'api_version' => '2025-02-24.acacia',
        'created' => $eventCreatedAt,
        'data' => [
            'object' => [
                'id' => 'sub_test',
                'object' => 'subscription',
                'customer' => 'cus_test',
                'status' => 'active',
                'cancel_at_period_end' => true,
                'current_period_end' => $eventCreatedAt + 86400,
                'items' => [
                    'object' => 'list',
                    'data' => [[
                        'id' => 'si_test',
                        'object' => 'subscription_item',
                        'current_period_end' => $eventCreatedAt + 86400,
                        'price' => ['id' => 'price_premium', 'object' => 'price'],
                    ]],
                    'has_more' => false,
                    'url' => '/v1/subscription_items',
                ],
            ],
        ],
        'livemode' => false,
        'pending_webhooks' => 1,
        'type' => 'customer.subscription.updated',
    ], JSON_THROW_ON_ERROR);
    $signature = WebhookSignature::generateSignatureHeader($payload, 'whsec_test');

    $this->call(
        'POST',
        route('stripe.webhook'),
        server: ['HTTP_STRIPE_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'],
        content: $payload,
    )->assertOk();

    expect($user->fresh()->plan)->toBe(User::PLAN_PREMIUM)
        ->and($user->fresh()->stripe_subscription_status)->toBe('active')
        ->and($user->fresh()->stripe_cancel_at_period_end)->toBeTrue()
        ->and($user->fresh()->stripe_current_period_end->timestamp)->toBe($eventCreatedAt + 86400)
        ->and($user->fresh()->stripe_pending_subscription_checkout_id)->toBeNull();
});

test('expired subscription checkout webhooks release the pending plan selection', function () {
    config(['services.stripe.webhook_secret' => 'whsec_test']);
    $user = User::factory()->create();
    $user->forceFill([
        'stripe_pending_subscription_checkout_id' => 'cs_expired',
        'stripe_pending_subscription_product' => 'lite',
    ])->save();
    $payload = json_encode([
        'id' => 'evt_checkout_expired',
        'object' => 'event',
        'api_version' => '2025-02-24.acacia',
        'created' => now()->timestamp,
        'data' => [
            'object' => [
                'id' => 'cs_expired',
                'object' => 'checkout.session',
                'mode' => 'subscription',
                'metadata' => [
                    'user_id' => (string) $user->id,
                    'product_key' => 'lite',
                ],
            ],
        ],
        'livemode' => false,
        'pending_webhooks' => 1,
        'type' => 'checkout.session.expired',
    ], JSON_THROW_ON_ERROR);

    $this->call(
        'POST',
        route('stripe.webhook'),
        server: [
            'HTTP_STRIPE_SIGNATURE' => WebhookSignature::generateSignatureHeader($payload, 'whsec_test'),
            'CONTENT_TYPE' => 'application/json',
        ],
        content: $payload,
    )->assertOk();

    expect($user->fresh()->stripe_pending_subscription_checkout_id)->toBeNull()
        ->and($user->fresh()->stripe_pending_subscription_product)->toBeNull();
});

test('subscription deletion returns a member to free and stale webhooks cannot reactivate it', function () {
    config([
        'services.stripe.webhook_secret' => 'whsec_test',
        'services.stripe.prices.lite' => 'price_lite',
    ]);
    $user = User::factory()->create();
    $user->forceFill([
        'stripe_customer_id' => 'cus_test',
        'stripe_subscription_id' => 'sub_test',
        'plan' => User::PLAN_LITE,
    ])->save();
    $sendEvent = function (string $eventId, string $type, int $created, string $status) {
        $payload = json_encode([
            'id' => $eventId,
            'object' => 'event',
            'api_version' => '2025-02-24.acacia',
            'created' => $created,
            'data' => [
                'object' => [
                    'id' => 'sub_test',
                    'object' => 'subscription',
                    'customer' => 'cus_test',
                    'status' => $status,
                    'cancel_at_period_end' => false,
                    'current_period_end' => $created + 86400,
                    'items' => [
                        'object' => 'list',
                        'data' => [[
                            'id' => 'si_test',
                            'object' => 'subscription_item',
                            'current_period_end' => $created + 86400,
                            'price' => ['id' => 'price_lite', 'object' => 'price'],
                        ]],
                        'has_more' => false,
                        'url' => '/v1/subscription_items',
                    ],
                ],
            ],
            'livemode' => false,
            'pending_webhooks' => 1,
            'type' => $type,
        ], JSON_THROW_ON_ERROR);

        return $this->call(
            'POST',
            route('stripe.webhook'),
            server: [
                'HTTP_STRIPE_SIGNATURE' => WebhookSignature::generateSignatureHeader($payload, 'whsec_test'),
                'CONTENT_TYPE' => 'application/json',
            ],
            content: $payload,
        );
    };

    $sendEvent('evt_subscription_deleted', 'customer.subscription.deleted', 200, 'canceled')->assertOk();
    $sendEvent('evt_subscription_stale', 'customer.subscription.updated', 199, 'active')->assertOk();

    expect($user->fresh()->plan)->toBe(User::PLAN_FREE)
        ->and($user->fresh()->stripe_subscription_id)->toBeNull()
        ->and($user->fresh()->stripe_subscription_status)->toBeNull();
});
