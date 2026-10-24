<?php

use App\Models\User;
use App\Services\ConsultationCreditService;
use App\Services\GuestTrialCreditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Responses\Chat\CreateResponse;

uses(RefreshDatabase::class);

test('the plans page explains free membership and does not offer credit purchases', function () {
    $response = $this->get(route('plans'));

    $response->assertOk()
        ->assertSee('1日1回（回数引継ぎなし）')
        ->assertDontSee('未ログインでの無料体験は1回まで')
        ->assertSee('過去3か月（最大50件）までの相談の記録')
        ->assertDontSee('10回分を購入')
        ->assertDontSee('50回分を購入')
        ->assertDontSee('100回分を購入')
        ->assertSee('1日10回（最大50回まで引継ぎ）')
        ->assertSee('相談回数無制限')
        ->assertSee('220円/')
        ->assertSee('580円/');
});

test('the plans page advertises the updated lite and premium allowances', function () {
    $this->get(route('plans'))
        ->assertOk()
        ->assertSee('1日10回（最大50回まで引継ぎ）')
        ->assertSee('相談回数無制限');
});

test('signed-in users cannot purchase credits from the plans page', function () {
    config([
        'services.stripe.secret' => 'sk_test',
        'services.stripe.prices.credits_10' => 'price_credits_10',
        'services.stripe.prices.credits_50' => 'price_credits_50',
        'services.stripe.prices.credits_100' => 'price_credits_100',
    ]);

    $this->actingAs(User::factory()->create())
        ->get(route('plans'))
        ->assertOk()
        ->assertDontSee('plan-credit-button')
        ->assertDontSee('10回分を購入')
        ->assertDontSee('50回分を購入')
        ->assertDontSee('100回分を購入');
});

test('guests can use one trial reading and are prompted to register afterwards', function () {
    $responses = [];
    foreach (['First result.', 'New device result.'] as $interpretation) {
        $responses[] = CreateResponse::fake(['choices' => [['message' => ['content' => $interpretation]]]]);
        $responses[] = CreateResponse::fake(['choices' => [['message' => ['content' => '{"topic":"その他","summary":"テスト要約"}']]]]);
    }
    OpenAI::fake($responses);

    $zenPage = $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10'])
        ->get(route('zen'));
    $zenPage->assertOk()->assertCookie('guest_trial_device');
    $firstDevice = $zenPage->getCookie('guest_trial_device')->getValue();

    $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10'])
        ->withCookie('guest_trial_device', $firstDevice)
        ->post(route('zen.respond'), ['concern' => 'One?'])
        ->assertOk()
        ->assertSee('無料体験を使い切りました。無料会員登録すると毎日1回相談できます。→ 会員登録はこちら');

    $this->assertDatabaseHas('guest_trial_credits', ['used_credits' => 1]);
    $this->assertDatabaseCount('guest_trial_credits', 1);

    $this->flushSession();
    $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.11'])
        ->withCookie('guest_trial_device', $firstDevice)
        ->post(route('zen.respond'), ['concern' => 'Second reading from the same device?'])
        ->assertRedirect(route('zen'))
        ->assertSessionHas('credit_exhausted');

    $secondDevice = str_repeat('b', 64);
    $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10'])
        ->withCookie('guest_trial_device', $secondDevice)
        ->post(route('zen.respond'), ['concern' => 'New connection?'])
        ->assertOk();

    expect(session('zen_consultations'))->toHaveCount(1);
    $this->assertDatabaseCount('zen_consultations', 0);
    $this->assertDatabaseCount('guest_trial_credits', 2);
});

test('guests with no trial credits see a full-width registration link and cannot draw a card', function () {
    $deviceToken = str_repeat('c', 64);
    $guestCredits = app(GuestTrialCreditService::class);
    expect($guestCredits->consume($deviceToken))->toBeTrue()
        ->and($guestCredits->remaining($deviceToken))->toBe(0);

    $this->withCookie('guest_trial_device', $deviceToken)
        ->get(route('zen'))
        ->assertOk()
        ->assertSee('href="'.route('register').'"', false)
        ->assertSee('class="primary-action" type="submit" disabled', false)
        ->assertSee('無料体験を使い切りました。無料会員登録すると毎日1回相談できます。→ 会員登録はこちら');
});

test('free members receive one reading per day without carrying unused credits forward', function () {
    $user = User::factory()->create();
    $user->forceFill([
        'daily_credits_plan' => User::PLAN_FREE,
        'daily_credits' => 3,
        'daily_credits_refreshed_at' => now()->subDay()->toDateString(),
        'purchased_credits' => 0,
    ])->save();

    $this->actingAs($user)->get(route('zen'))
        ->assertOk()
        ->assertSee('class="credit-balance-link"', false)
        ->assertSeeText('1 回')
        ->assertSee(route('plans'))
        ->assertDontSee('今日の残り')
        ->assertDontSee('購入クレジット');
    expect($user->fresh()->daily_credits)->toBe(1);
});

test('free member balances are capped at one reading for the current day', function () {
    $user = User::factory()->create();
    $user->forceFill([
        'daily_credits_plan' => User::PLAN_FREE,
        'daily_credits' => 3,
        'daily_credits_refreshed_at' => now()->toDateString(),
    ])->save();

    expect(app(ConsultationCreditService::class)->balance($user)['daily'])->toBe(1)
        ->and($user->fresh()->daily_credits)->toBe(1);
});

test('premium members retain unlimited readings without an exhausted-credit notice', function () {
    $freeMember = User::factory()->create();

    $this->actingAs($freeMember)
        ->get(route('zen'))
        ->assertOk()
        ->assertSee('class="credit-balance-link"', false)
        ->assertSee('href="'.route('plans').'"', false);

    $premiumMember = User::factory()->create();
    $premiumMember->forceFill(['plan' => User::PLAN_PREMIUM])->save();

    $this->actingAs($premiumMember)
        ->get(route('zen'))
        ->assertOk()
        ->assertSee('<strong>∞</strong>', false)
        ->assertDontSee('本日利用可能なクレジットを使い切りました。');
});

test('legacy users with a null plan are treated as free members', function () {
    $dailyCreditLimit = new ReflectionMethod(ConsultationCreditService::class, 'dailyCreditLimit');

    expect($dailyCreditLimit->invoke(app(ConsultationCreditService::class), null))->toBe(1);
});

test('purchased credits remain separate and can be spent after daily credits run out', function () {
    OpenAI::fake([
        CreateResponse::fake(['choices' => [['message' => ['content' => 'Purchased result.']]]]),
        CreateResponse::fake(['choices' => [['message' => ['content' => '{"topic":"その他","summary":"テスト要約"}']]]]),
    ]);
    $user = User::factory()->create();
    $user->forceFill([
        'daily_credits' => 0,
        'daily_credits_refreshed_at' => now()->toDateString(),
        'purchased_credits' => 101,
    ])->save();

    $this->actingAs($user)
        ->post(route('zen.respond'), ['concern' => 'A question?'])
        ->assertOk()
        ->assertSee('相談残り 100回');

    expect($user->fresh()->daily_credits)->toBe(0)
        ->and($user->fresh()->purchased_credits)->toBe(100);
});

test('members with no credits see a full-width plans link and cannot draw a card', function () {
    $user = User::factory()->create();
    $user->forceFill([
        'daily_credits' => 0,
        'daily_credits_refreshed_at' => now()->toDateString(),
        'purchased_credits' => 0,
    ])->save();

    $this->actingAs($user)
        ->get(route('zen'))
        ->assertOk()
        ->assertSee('href="'.route('plans').'"', false)
        ->assertSee('class="primary-action" type="submit" disabled', false)
        ->assertSee('本日利用可能なクレジットを使い切りました。→有料プランを見る');
});

test('the result page links the entire exhausted member-credit notice to plans', function () {
    OpenAI::fake([
        CreateResponse::fake(['choices' => [['message' => ['content' => 'Last credit result.']]]]),
        CreateResponse::fake(['choices' => [['message' => ['content' => '{"topic":"その他","summary":"テスト要約"}']]]]),
    ]);
    $user = User::factory()->create();
    $user->forceFill([
        'daily_credits' => 1,
        'daily_credits_refreshed_at' => now()->toDateString(),
        'purchased_credits' => 0,
    ])->save();

    $this->actingAs($user)
        ->post(route('zen.respond'), ['concern' => 'Last question?'])
        ->assertOk()
        ->assertSee('<a class="form-notice credit-notice credit-notice-link" href="'.route('plans').'">', false)
        ->assertSee('本日利用可能なクレジットを使い切りました。→ 有料プランを見る');
});

test('lite members receive ten daily credits up to a fifty-credit carryover cap', function () {
    $user = User::factory()->create();
    $user->forceFill([
        'plan' => User::PLAN_LITE,
        'daily_credits_plan' => User::PLAN_LITE,
        'daily_credits' => 148,
        'daily_credits_refreshed_at' => now()->subDays(2)->toDateString(),
        'purchased_credits' => 105,
    ])->save();

    $this->actingAs($user)->get(route('zen'))->assertOk();

    expect(app(ConsultationCreditService::class)->balance($user)['total'])->toBe(155)
        ->and($user->fresh()->daily_credits)->toBe(50)
        ->and($user->fresh()->purchased_credits)->toBe(105);
});

test('upgrading to lite grants ten daily credits and upgrading to premium enables unlimited readings', function () {
    $upgrades = [
        [User::PLAN_FREE, User::PLAN_LITE, 10],
        [User::PLAN_FREE, User::PLAN_PREMIUM, 0],
        [User::PLAN_LITE, User::PLAN_PREMIUM, 0],
    ];
    $creditService = app(ConsultationCreditService::class);

    foreach ($upgrades as [$previousPlan, $newPlan, $expectedCredits]) {
        $user = User::factory()->create();
        $user->forceFill([
            'plan' => $previousPlan,
            'daily_credits_plan' => $previousPlan,
            'daily_credits' => 0,
            'daily_credits_refreshed_at' => now()->toDateString(),
        ])->save();
        $creditService->changePlan($user, $newPlan);

        $balance = $creditService->balance($user);
        expect($balance['daily'])->toBe($expectedCredits)
            ->and($balance['unlimited'])->toBe($newPlan === User::PLAN_PREMIUM)
            ->and($balance['total'])->toBe($newPlan === User::PLAN_PREMIUM ? null : $expectedCredits)
            ->and($user->fresh()->daily_credits_plan)->toBe($newPlan)
            ->and($user->fresh()->daily_credits_refreshed_at->toDateString())->toBe(now()->toDateString());
    }
});

test('upgrading from free to lite adds the remaining free reading', function () {
    $user = User::factory()->create();
    $user->forceFill([
        'plan' => User::PLAN_FREE,
        'daily_credits_plan' => User::PLAN_FREE,
        'daily_credits' => 1,
        'daily_credits_refreshed_at' => now()->toDateString(),
    ])->save();

    app(ConsultationCreditService::class)->changePlan($user, User::PLAN_LITE);

    expect(app(ConsultationCreditService::class)->balance($user)['daily'])->toBe(11);
});

test('returning to lite preserves the previous lite balance when it exceeds ten', function () {
    $user = User::factory()->create();
    $user->forceFill([
        'plan' => User::PLAN_LITE,
        'daily_credits_plan' => User::PLAN_LITE,
        'daily_credits' => 17,
        'daily_credits_refreshed_at' => now()->toDateString(),
    ])->save();
    $creditService = app(ConsultationCreditService::class);

    $creditService->changePlan($user, User::PLAN_PREMIUM);
    expect($user->fresh()->daily_credits)->toBe(0);

    $creditService->changePlan($user, User::PLAN_LITE);

    expect($creditService->balance($user)['daily'])->toBe(17);
});

test('returning to lite from free adds the last free reading to the previous lite balance', function () {
    $user = User::factory()->create();
    $user->forceFill([
        'plan' => User::PLAN_LITE,
        'daily_credits_plan' => User::PLAN_LITE,
        'daily_credits' => 17,
        'daily_credits_refreshed_at' => now()->toDateString(),
    ])->save();
    $creditService = app(ConsultationCreditService::class);

    $creditService->changePlan($user, User::PLAN_FREE);
    expect($user->fresh()->daily_credits)->toBe(1);

    $creditService->changePlan($user, User::PLAN_LITE);

    expect($creditService->balance($user)['daily'])->toBe(18);
});

test('returning to lite caps the restored balance and free reading at fifty', function () {
    $user = User::factory()->create();
    $user->forceFill([
        'plan' => User::PLAN_LITE,
        'daily_credits_plan' => User::PLAN_LITE,
        'daily_credits' => 50,
        'daily_credits_refreshed_at' => now()->toDateString(),
    ])->save();
    $creditService = app(ConsultationCreditService::class);

    $creditService->changePlan($user, User::PLAN_FREE);
    $creditService->changePlan($user, User::PLAN_LITE);

    expect($creditService->balance($user)['daily'])->toBe(50);
});

test('plan upgrades do not grant paid daily credits for days before the upgrade', function () {
    $user = User::factory()->create();
    $user->forceFill([
        'plan' => User::PLAN_FREE,
        'daily_credits_plan' => User::PLAN_FREE,
        'daily_credits' => 1,
        'daily_credits_refreshed_at' => now()->subDays(5)->toDateString(),
    ])->save();
    $user->forceFill(['plan' => User::PLAN_PREMIUM])->save();

    expect(app(ConsultationCreditService::class)->balance($user)['daily'])->toBe(0)
        ->and(app(ConsultationCreditService::class)->balance($user)['unlimited'])->toBeTrue()
        ->and($user->fresh()->daily_credits_refreshed_at->toDateString())->toBe(now()->toDateString());
});

test('premium members can draw without spending daily or purchased credits', function () {
    OpenAI::fake([
        CreateResponse::fake(['choices' => [['message' => ['content' => '仕事']]]]),
        CreateResponse::fake(['choices' => [['message' => ['content' => 'An unlimited reading.']]]]),
        CreateResponse::fake(['choices' => [['message' => ['content' => '{"summary":"無制限の占い結果です。"}']]]]),
    ]);
    $user = User::factory()->create();
    $user->forceFill([
        'plan' => User::PLAN_PREMIUM,
        'daily_credits' => 0,
        'purchased_credits' => 7,
    ])->save();

    $this->actingAs($user)
        ->post(route('zen.respond'), ['concern' => 'How will work go?'])
        ->assertOk()
        ->assertSee('相談回数は無制限です');

    expect($user->fresh()->daily_credits)->toBe(0)
        ->and($user->fresh()->purchased_credits)->toBe(7);
});

test('premium members are limited to five readings per minute', function () {
    $responses = [];
    foreach (range(1, 5) as $index) {
        $responses[] = CreateResponse::fake(['choices' => [['message' => ['content' => 'その他']]]]);
        $responses[] = CreateResponse::fake(['choices' => [['message' => ['content' => "Premium result {$index}."]]]]);
        $responses[] = CreateResponse::fake(['choices' => [['message' => ['content' => '{"summary":"プレミアム結果です。"}']]]]);
    }
    OpenAI::fake($responses);

    $user = User::factory()->create();
    $user->forceFill(['plan' => User::PLAN_PREMIUM])->save();
    $rateLimitKey = 'premium-consultation:'.$user->getAuthIdentifier();
    RateLimiter::clear($rateLimitKey);

    $this->actingAs($user);
    foreach (range(1, 5) as $index) {
        $this->post(route('zen.respond'), ['concern' => "Question {$index}?"])->assertOk();
    }

    $this->post(route('zen.respond'), ['concern' => 'One more question?'])
        ->assertRedirect(route('zen'))
        ->assertSessionHas('consultation_rate_limited');
    $this->get(route('zen'))
        ->assertOk()
        ->assertSee('1分あたり5回までです。');

    expect($user->fresh()->daily_credits)->toBe(0);
    RateLimiter::clear($rateLimitKey);
});

test('premium members are limited to one thousand readings per calendar day', function () {
    OpenAI::fake([
        CreateResponse::fake(['choices' => [['message' => ['content' => '仕事']]]]),
        CreateResponse::fake(['choices' => [['message' => ['content' => 'The thousandth reading.']]]]),
        CreateResponse::fake(['choices' => [['message' => ['content' => '{"summary":"上限の占い結果です。"}']]]]),
    ]);

    $user = User::factory()->create();
    $user->forceFill(['plan' => User::PLAN_PREMIUM])->save();
    $dailyRateLimitKey = 'premium-daily-consultation:'.$user->getAuthIdentifier().':'.now()->toDateString();
    $minuteRateLimitKey = 'premium-consultation:'.$user->getAuthIdentifier();
    RateLimiter::clear($dailyRateLimitKey);
    RateLimiter::clear($minuteRateLimitKey);
    foreach (range(1, 999) as $unused) {
        RateLimiter::hit($dailyRateLimitKey, 86400);
    }

    $this->actingAs($user)
        ->post(route('zen.respond'), ['concern' => 'The thousandth question?'])
        ->assertOk();

    $this->post(route('zen.respond'), ['concern' => 'The next question?'])
        ->assertRedirect(route('zen'))
        ->assertSessionHas('consultation_rate_limited', '本日のプレミアムプランの利用上限に達しました。明日以降に再度お試しください。');

    expect(RateLimiter::attempts($dailyRateLimitKey))->toBe(1000);
    RateLimiter::clear($dailyRateLimitKey);
    RateLimiter::clear($minuteRateLimitKey);
});

test('only one reading can run concurrently for each member or guest device', function (?string $plan) {
    $deviceToken = str_repeat('d', 64);
    if ($plan === null) {
        $lockIdentity = 'guest:'.hash('sha256', $deviceToken);
        $user = null;
    } else {
        $user = User::factory()->create(['plan' => $plan]);
        $lockIdentity = 'user:'.$user->getAuthIdentifier();
    }

    $lock = Cache::lock('zen-consultation:'.$lockIdentity, 300);
    expect($lock->get())->toBeTrue();

    if ($user instanceof User) {
        $this->actingAs($user);
    } else {
        $this->withCookie('guest_trial_device', $deviceToken);
    }

    $this->post(route('zen.respond'), ['concern' => 'Another reading?'])
        ->assertRedirect(route('zen'))
        ->assertSessionHas('consultation_rate_limited', '別の相談に回答中です。完了後にもう一度お試しください。');

    $lock->release();
})->with([
    'free member' => [User::PLAN_FREE],
    'lite member' => [User::PLAN_LITE],
    'premium member' => [User::PLAN_PREMIUM],
    'guest' => [null],
]);

test('free members see only recent readings within the fifty-reading cap and receive an upgrade prompt', function () {
    $user = User::factory()->create();

    foreach (range(0, 54) as $index) {
        $createdAt = now()->subDays(54 - $index);
        $reading = $user->zenConsultations()->create([
            'concern' => match ($index) {
                4 => 'Outside-cap marker',
                5 => 'Inside-cap marker',
                default => "Recent question {$index}",
            },
            'response' => 'A reading.',
        ]);
        $reading->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();
    }

    $oldReading = $user->zenConsultations()->create([
        'concern' => 'Older question',
        'response' => 'An old reading.',
    ]);
    $oldCreatedAt = now()->subMonths(4);
    $oldReading->forceFill(['created_at' => $oldCreatedAt, 'updated_at' => $oldCreatedAt])->save();

    expect($user->plan)->toBe(User::PLAN_FREE)
        ->and($user->zenConsultations()->where('created_at', '>=', now()->subMonths(3))->count())->toBe(55);
    $response = $this->actingAs($user)->get(route('consultations.index', ['page' => 3]));
    $response->assertOk();
    expect($response->viewData('historyUpgradePrompt'))->toBeTrue()
        ->and($response->viewData('databaseConsultations')->total())->toBe(50)
        ->and($response->viewData('databaseConsultations')->perPage())->toBe(20)
        ->and($response->viewData('databaseConsultations')->count())->toBe(10);
    $response->assertSee('有料プランを見る')
        ->assertSee('page=2');
    $consultationsOnPage = $response->viewData('databaseConsultations');
    expect($consultationsOnPage->total())->toBe(50)
        ->and($consultationsOnPage->count())->toBe(10)
        ->and($consultationsOnPage->getCollection()->pluck('concern')->all())
        ->toContain('Inside-cap marker')
        ->not->toContain('Outside-cap marker', 'Older question');
});

test('free members cannot open history older than three months or beyond the fifty-reading cap', function () {
    $user = User::factory()->create();
    $olderReading = $user->zenConsultations()->create([
        'concern' => 'Older than three months',
        'response' => 'An old reading.',
    ]);
    $olderCreatedAt = now()->subMonths(4);
    $olderReading->forceFill(['created_at' => $olderCreatedAt, 'updated_at' => $olderCreatedAt])->save();

    $readings = [];
    foreach (range(1, 51) as $index) {
        $createdAt = now()->subDays(51 - $index);
        $reading = $user->zenConsultations()->create([
            'concern' => "Newer question {$index}",
            'response' => 'A recent reading.',
        ]);
        $reading->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();
        $readings[] = $reading;
    }

    expect($user->plan)->toBe(User::PLAN_FREE)
        ->and($user->zenConsultations()->where('created_at', '>', $readings[0]->created_at)->count())->toBe(50)
        ->and($readings[0]->created_at->toDateString())->toBe(now()->subDays(50)->toDateString());

    $this->actingAs($user)
        ->get(route('consultations.show', $olderReading->id))
        ->assertNotFound();
    $this->get(route('consultations.show', $readings[0]->id))->assertNotFound();
    $this->get(route('consultations.show', $readings[1]->id))->assertOk();
});

test('premium members can open older history without a display cap', function () {
    $user = User::factory()->create();
    $user->forceFill(['plan' => User::PLAN_PREMIUM])->save();
    $oldReading = $user->zenConsultations()->create([
        'concern' => 'Premium history',
        'response' => 'A preserved reading.',
    ]);
    $oldCreatedAt = now()->subYears(2);
    $oldReading->forceFill(['created_at' => $oldCreatedAt, 'updated_at' => $oldCreatedAt])->save();

    $this->actingAs($user)
        ->get(route('consultations.show', $oldReading->id))
        ->assertOk()
        ->assertSee('A preserved reading.');
});

test('category filtering does not reveal readings outside the free history cap', function () {
    $user = User::factory()->create();
    foreach (range(1, 51) as $daysAgo) {
        $reading = $user->zenConsultations()->create([
            'concern' => "Recent question {$daysAgo}",
            'response' => 'A reading.',
            'topic' => $daysAgo === 51 ? '仕事' : '恋愛',
            'summary' => 'テスト要約です。',
        ]);
        $createdAt = now()->subDays($daysAgo);
        $reading->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();
    }

    $categoryResponse = $this->actingAs($user)
        ->get(route('consultations.index'))
        ->assertOk();
    expect($categoryResponse->viewData('topics')->all())->toBe(['恋愛']);

    $response = $this->get(route('consultations.index', ['topic' => '仕事']));

    $response->assertOk()
        ->assertSee('「仕事」テーマの相談記録はありません。')
        ->assertDontSee('Recent question 51');

    expect($response->viewData('historyUpgradePrompt'))->toBeTrue()
        ->and($response->viewData('databaseConsultations')->total())->toBe(0);
});
