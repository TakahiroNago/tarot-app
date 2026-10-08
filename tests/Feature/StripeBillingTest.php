<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Stripe\HttpClient\CurlClient;
use Stripe\WebhookSignature;

uses(RefreshDatabase::class);

test('checkout actions require an authenticated member', function () {
    $this->post(route('billing.checkout'), ['product' => 'credits_10'])
        ->assertRedirect(route('login'));
});

test('free members see their current plan even when they have a Stripe customer', function () {
    $user = User::factory()->create([
        'plan' => User::PLAN_FREE,
        'stripe_customer_id' => 'cus_test',
    ]);

    $this->actingAs($user)
        ->get(route('plans'))
        ->assertOk()
        ->assertSee('現在のプラン：無料')
        ->assertDontSee('お支払い情報を管理')
        ->assertDontSee('契約・お支払いを管理');
});

test('paid members do not see the checkout button for their current plan', function () {
    config([
        'services.stripe.secret' => 'sk_test',
        'services.stripe.prices.lite' => 'price_lite',
        'services.stripe.prices.premium' => 'price_premium',
    ]);

    foreach ([
        [User::PLAN_LITE, 'ライトに申し込む'],
        [User::PLAN_PREMIUM, 'プレミアムに申し込む'],
    ] as [$plan, $buttonText]) {
        $user = User::factory()->create([
            'plan' => $plan,
            'stripe_customer_id' => 'cus_'.$plan,
            'stripe_subscription_id' => 'sub_'.$plan,
        ]);

        $this->actingAs($user)
            ->get(route('plans'))
            ->assertOk()
            ->assertDontSee($buttonText);
    }
});

test('lite members can immediately upgrade with Stripe prorations', function () {
    config([
        'services.stripe.secret' => 'sk_test',
        'services.stripe.prices.lite' => 'price_lite',
        'services.stripe.prices.premium' => 'price_premium',
    ]);
    $periodStart = now()->subDays(10)->timestamp;
    $periodEnd = now()->addDays(20)->timestamp;
    $httpClient = new class($periodStart, $periodEnd) implements ClientInterface
    {
        public array $requests = [];

        public function __construct(private int $periodStart, private int $periodEnd) {}

        public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
        {
            $this->requests[] = compact('method', 'absUrl', 'params');
            $response = str_contains($absUrl, '/v1/subscriptions/sub_lite')
                && $method === 'get'
                ? [
                    'id' => 'sub_lite',
                    'object' => 'subscription',
                    'customer' => 'cus_lite',
                    'status' => 'active',
                    'current_period_start' => $this->periodStart,
                    'current_period_end' => $this->periodEnd,
                    'cancel_at_period_end' => false,
                    'items' => [
                        'object' => 'list',
                        'data' => [[
                            'id' => 'si_lite',
                            'object' => 'subscription_item',
                            'price' => ['id' => 'price_lite', 'object' => 'price'],
                            'quantity' => 1,
                        ]],
                    ],
                ]
                : [
                    'id' => 'sub_lite',
                    'object' => 'subscription',
                    'pending_update' => null,
                ];

            return [json_encode($response, JSON_THROW_ON_ERROR), 200, ['Content-Type' => 'application/json']];
        }
    };
    ApiRequestor::setHttpClient($httpClient);

    try {
        $user = User::factory()->create([
            'plan' => User::PLAN_LITE,
            'stripe_customer_id' => 'cus_lite',
            'stripe_subscription_id' => 'sub_lite',
        ]);

        $this->actingAs($user)
            ->post(route('billing.plan.change'), ['plan' => User::PLAN_PREMIUM])
            ->assertRedirect(route('account.settings.edit'))
            ->assertSessionHas('status', 'プレミアムプランへ切り替えました。日割り分は今回の請求に反映されています。');

        expect($httpClient->requests)->toHaveCount(2)
            ->and($httpClient->requests[1]['params']['items'][0]['price'])->toBe('price_premium')
            ->and($httpClient->requests[1]['params']['proration_behavior'])->toBe('always_invoice')
            ->and($httpClient->requests[1]['params']['payment_behavior'])->toBe('pending_if_incomplete')
            ->and($user->fresh()->plan)->toBe(User::PLAN_LITE);
    } finally {
        ApiRequestor::setHttpClient(new CurlClient);
    }
});

test('members cannot start another plan change while an upgrade payment is pending', function () {
    config([
        'services.stripe.secret' => 'sk_test',
        'services.stripe.prices.lite' => 'price_lite',
        'services.stripe.prices.premium' => 'price_premium',
    ]);
    $httpClient = new class implements ClientInterface
    {
        public array $requests = [];

        public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
        {
            $this->requests[] = compact('method', 'absUrl', 'params');

            return [
                json_encode([
                    'id' => 'sub_pending',
                    'object' => 'subscription',
                    'customer' => 'cus_pending',
                    'status' => 'active',
                    'pending_update' => ['expires_at' => now()->addDay()->timestamp],
                    'items' => [
                        'object' => 'list',
                        'data' => [[
                            'id' => 'si_pending',
                            'object' => 'subscription_item',
                            'price' => ['id' => 'price_lite', 'object' => 'price'],
                            'quantity' => 1,
                        ]],
                    ],
                ], JSON_THROW_ON_ERROR),
                200,
                ['Content-Type' => 'application/json'],
            ];
        }
    };
    ApiRequestor::setHttpClient($httpClient);

    try {
        $user = User::factory()->create([
            'plan' => User::PLAN_LITE,
            'stripe_customer_id' => 'cus_pending',
            'stripe_subscription_id' => 'sub_pending',
        ]);

        $this->actingAs($user)
            ->post(route('billing.plan.change'), ['plan' => User::PLAN_PREMIUM])
            ->assertRedirect()
            ->assertSessionHasErrors('billing');

        expect($httpClient->requests)->toHaveCount(1);
    } finally {
        ApiRequestor::setHttpClient(new CurlClient);
    }
});

test('premium members can schedule a lite downgrade for the current period end', function () {
    config([
        'services.stripe.secret' => 'sk_test',
        'services.stripe.prices.lite' => 'price_lite',
        'services.stripe.prices.premium' => 'price_premium',
    ]);
    $periodStart = now()->subDays(10)->timestamp;
    $periodEnd = now()->addDays(20)->timestamp;
    $httpClient = new class($periodStart, $periodEnd) implements ClientInterface
    {
        public array $requests = [];

        public function __construct(private int $periodStart, private int $periodEnd) {}

        public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
        {
            $this->requests[] = compact('method', 'absUrl', 'params');
            if (str_contains($absUrl, '/v1/subscriptions/sub_premium')) {
                $response = [
                    'id' => 'sub_premium',
                    'object' => 'subscription',
                    'customer' => 'cus_premium',
                    'status' => 'active',
                    'current_period_start' => $this->periodStart,
                    'current_period_end' => $this->periodEnd,
                    'cancel_at_period_end' => false,
                    'items' => [
                        'object' => 'list',
                        'data' => [[
                            'id' => 'si_premium',
                            'object' => 'subscription_item',
                            'price' => ['id' => 'price_premium', 'object' => 'price'],
                            'quantity' => 1,
                        ]],
                    ],
                ];
            } elseif ($method === 'post' && str_ends_with($absUrl, '/v1/subscription_schedules')) {
                $response = [
                    'id' => 'sched_premium',
                    'object' => 'subscription_schedule',
                    'phases' => [[
                        'start_date' => $this->periodStart,
                        'end_date' => $this->periodEnd,
                        'items' => [[
                            'price' => 'price_premium',
                            'quantity' => 1,
                            'tax_rates' => [],
                        ]],
                        'proration_behavior' => 'create_prorations',
                    ]],
                ];
            } else {
                $response = [
                    'id' => 'sched_premium',
                    'object' => 'subscription_schedule',
                    'status' => 'active',
                ];
            }

            return [json_encode($response, JSON_THROW_ON_ERROR), 200, ['Content-Type' => 'application/json']];
        }
    };
    ApiRequestor::setHttpClient($httpClient);

    try {
        $user = User::factory()->create([
            'plan' => User::PLAN_PREMIUM,
            'stripe_customer_id' => 'cus_premium',
            'stripe_subscription_id' => 'sub_premium',
        ]);

        $this->actingAs($user)
            ->post(route('billing.plan.change'), ['plan' => User::PLAN_LITE])
            ->assertRedirect(route('account.settings.edit'))
            ->assertSessionHas('status', 'ライトプランへの変更を予約しました。現在の契約期間終了後に切り替わります。');

        expect($httpClient->requests)->toHaveCount(3)
            ->and($httpClient->requests[1]['params']['from_subscription'])->toBe('sub_premium')
            ->and($httpClient->requests[2]['params']['phases'][1]['start_date'])->toBe($periodEnd)
            ->and($httpClient->requests[2]['params']['phases'][1]['items'][0]['price'])->toBe('price_lite')
            ->and($user->fresh()->stripe_scheduled_plan)->toBe(User::PLAN_LITE)
            ->and($user->fresh()->stripe_subscription_schedule_id)->toBe('sched_premium')
            ->and($user->fresh()->plan)->toBe(User::PLAN_PREMIUM);
    } finally {
        ApiRequestor::setHttpClient(new CurlClient);
    }
});

test('members can cancel a scheduled downgrade', function () {
    config(['services.stripe.secret' => 'sk_test']);
    $httpClient = new class implements ClientInterface
    {
        public array $requests = [];

        public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
        {
            $this->requests[] = compact('method', 'absUrl', 'params');

            return [
                json_encode([
                    'id' => 'sched_cancel',
                    'object' => 'subscription_schedule',
                    'status' => 'released',
                ], JSON_THROW_ON_ERROR),
                200,
                ['Content-Type' => 'application/json'],
            ];
        }
    };
    ApiRequestor::setHttpClient($httpClient);

    try {
        $user = User::factory()->create([
            'stripe_customer_id' => 'cus_cancel_schedule',
            'stripe_subscription_id' => 'sub_cancel_schedule',
            'stripe_scheduled_plan' => User::PLAN_LITE,
            'stripe_subscription_schedule_id' => 'sched_cancel',
        ]);

        $this->actingAs($user)
            ->delete(route('billing.plan.cancel'))
            ->assertRedirect(route('account.settings.edit'))
            ->assertSessionHas('status', '予約していたプラン変更を取り消しました。');

        expect($httpClient->requests)->toHaveCount(1)
            ->and($httpClient->requests[0]['absUrl'])->toContain('/v1/subscription_schedules/sched_cancel/release')
            ->and($user->fresh()->stripe_scheduled_plan)->toBeNull()
            ->and($user->fresh()->stripe_subscription_schedule_id)->toBeNull();
    } finally {
        ApiRequestor::setHttpClient(new CurlClient);
    }
});

test('subscription schedule webhooks clear the stored scheduled plan', function () {
    config(['services.stripe.webhook_secret' => 'whsec_test']);
    $user = User::factory()->create([
        'stripe_customer_id' => 'cus_schedule_webhook',
        'stripe_subscription_id' => 'sub_schedule_webhook',
        'stripe_scheduled_plan' => User::PLAN_LITE,
        'stripe_subscription_schedule_id' => 'sched_webhook',
    ]);
    $payload = json_encode([
        'id' => 'evt_schedule_released',
        'object' => 'event',
        'api_version' => '2025-02-24.acacia',
        'created' => now()->timestamp,
        'data' => [
            'object' => [
                'id' => 'sched_webhook',
                'object' => 'subscription_schedule',
            ],
        ],
        'livemode' => false,
        'pending_webhooks' => 1,
        'type' => 'subscription_schedule.released',
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

    expect($user->fresh()->stripe_scheduled_plan)->toBeNull()
        ->and($user->fresh()->stripe_subscription_schedule_id)->toBeNull();
});

test('credit checkout is unavailable even for authenticated members', function () {
    config([
        'services.stripe.secret' => 'sk_test',
        'services.stripe.prices.credits_10' => 'price_credits_10',
    ]);

    $this->actingAs(User::factory()->create(['email_verified_at' => now()]))
        ->from(route('plans'))
        ->post(route('billing.checkout'), ['product' => 'credits_10'])
        ->assertRedirect(route('plans'))
        ->assertSessionHasErrors(['billing' => 'クレジットは購入できません。有料プランをご利用ください。']);
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
