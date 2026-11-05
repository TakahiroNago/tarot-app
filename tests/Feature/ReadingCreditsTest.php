<?php

use App\Models\User;
use App\Services\GuestTrialCreditService;
use App\Services\ReadingCreditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Responses\Chat\CreateResponse;

uses(RefreshDatabase::class);

test('the plans page and paid plan routes no longer exist', function () {
    $this->get('/plans')->assertNotFound();
    $this->post('/billing/checkout')->assertNotFound();
});

test('guests can use three trial readings and are prompted to register afterwards', function () {
    $responses = [];
    foreach (['First result.', 'Second result.', 'Third result.', 'New device result.'] as $interpretation) {
        $responses[] = CreateResponse::fake(['choices' => [['message' => ['content' => $interpretation]]]]);
        $responses[] = CreateResponse::fake(['choices' => [['message' => ['content' => '{"category":"その他","summary":"テスト要約"}']]]]);
    }
    OpenAI::fake($responses);

    $tarotPage = $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10'])
        ->get(route('tarot'));
    $tarotPage->assertOk()->assertCookie('guest_trial_device');
    $firstDevice = $tarotPage->getCookie('guest_trial_device')->getValue();

    foreach (['One?', 'Two?', 'Three?'] as $question) {
        $response = $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10'])
            ->withCookie('guest_trial_device', $firstDevice)
            ->post(route('tarot.draw'), ['question' => $question]);

        $response->assertOk();
        if ($question === 'Three?') {
            $response->assertSee('<a class="form-notice credit-notice credit-notice-link" href="'.route('register').'">', false)
                ->assertSee('無料体験を使い切りました。無料会員登録すると毎日3回占えます。→ 会員登録はこちら');
        }
    }

    $this->assertDatabaseHas('guest_trial_credits', ['used_credits' => 3]);
    $this->assertDatabaseMissing('guest_trial_credits', ['device_hash' => $firstDevice]);
    $this->assertDatabaseCount('guest_trial_credits', 1);

    $this->flushSession();
    $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.11'])
        ->withCookie('guest_trial_device', $firstDevice)
        ->post(route('tarot.draw'), ['question' => 'Same device from a new IP?'])
        ->assertRedirect(route('tarot'))
        ->assertSessionHas('credit_exhausted');

    $secondDevice = str_repeat('b', 64);
    $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10'])
        ->withCookie('guest_trial_device', $secondDevice)
        ->post(route('tarot.draw'), ['question' => 'New connection?'])
        ->assertOk();

    expect(session('tarot_readings'))->toHaveCount(1);
    $this->assertDatabaseCount('tarot_readings', 0);
    $this->assertDatabaseCount('guest_trial_credits', 2);
});

test('guests with no trial credits see a full-width registration link and cannot draw a card', function () {
    $deviceToken = str_repeat('c', 64);
    $guestCredits = app(GuestTrialCreditService::class);
    foreach (range(1, 3) as $unused) {
        $guestCredits->consume($deviceToken);
    }

    $this->withCookie('guest_trial_device', $deviceToken)
        ->get(route('tarot'))
        ->assertOk()
        ->assertSee('href="'.route('register').'"', false)
        ->assertSee('class="primary-action" type="submit" disabled', false)
        ->assertSee('無料体験を使い切りました。無料会員登録すると毎日3回占えます。→ 会員登録はこちら');
});

test('free members receive three readings per day without carrying unused credits forward', function () {
    $user = User::factory()->create();
    $user->forceFill([
        'daily_credits' => 1,
        'daily_credits_refreshed_at' => now()->subDay()->toDateString(),
        'purchased_credits' => 0,
    ])->save();

    $this->actingAs($user)->get(route('tarot'))
        ->assertOk()
        ->assertSee('class="credit-balance-link"', false)
        ->assertSee('<strong>3</strong> 回', false)
        ->assertDontSee('今日の残り')
        ->assertDontSee('購入クレジット');
    expect($user->fresh()->daily_credits)->toBe(3);
});

test('members on removed paid plans are treated as free members', function () {
    foreach (['lite', 'premium'] as $legacyPlan) {
        $user = User::factory()->create();
        $user->forceFill([
            'plan' => $legacyPlan,
            'daily_credits_plan' => $legacyPlan,
            'daily_credits' => 50,
            'daily_credits_refreshed_at' => now()->toDateString(),
            'purchased_credits' => 4,
        ])->save();

        $balance = app(ReadingCreditService::class)->balance($user);

        expect($balance)->toBe(['daily' => 3, 'purchased' => 4, 'total' => 7])
            ->and($user->fresh()->plan)->toBe(User::PLAN_FREE)
            ->and($user->fresh()->daily_credits_plan)->toBe(User::PLAN_FREE);
    }
});

test('the tarot page no longer links to plans', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('tarot'))
        ->assertOk()
        ->assertDontSee('料金・プラン')
        ->assertDontSee('有料プラン');
});

test('purchased credits remain separate and can be spent after daily credits run out', function () {
    OpenAI::fake([
        CreateResponse::fake(['choices' => [['message' => ['content' => 'Purchased result.']]]]),
        CreateResponse::fake(['choices' => [['message' => ['content' => '{"category":"その他","summary":"テスト要約"}']]]]),
    ]);
    $user = User::factory()->create();
    $user->forceFill([
        'daily_credits' => 0,
        'daily_credits_refreshed_at' => now()->toDateString(),
        'purchased_credits' => 101,
    ])->save();

    $this->actingAs($user)
        ->post(route('tarot.draw'), ['question' => 'A question?'])
        ->assertOk()
        ->assertSee('占い残り 100回');

    expect($user->fresh()->daily_credits)->toBe(0)
        ->and($user->fresh()->purchased_credits)->toBe(100);
});

test('members with no credits see an exhausted notice and cannot draw a card', function () {
    $user = User::factory()->create();
    $user->forceFill([
        'daily_credits' => 0,
        'daily_credits_refreshed_at' => now()->toDateString(),
        'purchased_credits' => 0,
    ])->save();

    $this->actingAs($user)
        ->get(route('tarot'))
        ->assertOk()
        ->assertSee('class="primary-action" type="submit" disabled', false)
        ->assertSee('本日利用可能なクレジットを使い切りました。明日以降に再度お試しください。');
});

test('the result page shows the exhausted member-credit notice without a plans link', function () {
    OpenAI::fake([
        CreateResponse::fake(['choices' => [['message' => ['content' => 'Last credit result.']]]]),
        CreateResponse::fake(['choices' => [['message' => ['content' => '{"category":"その他","summary":"テスト要約"}']]]]),
    ]);
    $user = User::factory()->create();
    $user->forceFill([
        'daily_credits' => 1,
        'daily_credits_refreshed_at' => now()->toDateString(),
        'purchased_credits' => 0,
    ])->save();

    $this->actingAs($user)
        ->post(route('tarot.draw'), ['question' => 'Last question?'])
        ->assertOk()
        ->assertSee('<p class="form-notice credit-notice">', false)
        ->assertSee('本日利用可能なクレジットを使い切りました。明日以降に再度お試しください。');
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

    $lock = Cache::lock('tarot-reading:'.$lockIdentity, 300);
    expect($lock->get())->toBeTrue();

    if ($user instanceof User) {
        $this->actingAs($user);
    } else {
        $this->withCookie('guest_trial_device', $deviceToken);
    }

    $this->post(route('tarot.draw'), ['question' => 'Another reading?'])
        ->assertRedirect(route('tarot'))
        ->assertSessionHas('reading_rate_limited', '別の占いを実行中です。完了後にもう一度お試しください。');

    $lock->release();
})->with([
    'free member' => [User::PLAN_FREE],
    'guest' => [null],
]);

test('free members see only recent readings within the fifty-reading cap', function () {
    $user = User::factory()->create();

    foreach (range(0, 54) as $index) {
        $createdAt = now()->subDays(54 - $index);
        $reading = $user->tarotReadings()->create([
            'question' => match ($index) {
                4 => 'Outside-cap marker',
                5 => 'Inside-cap marker',
                default => "Recent question {$index}",
            },
            'card' => 'The Star',
            'interpretation' => 'A reading.',
        ]);
        $reading->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();
    }

    $oldReading = $user->tarotReadings()->create([
        'question' => 'Older question',
        'card' => 'The Moon',
        'interpretation' => 'An old reading.',
    ]);
    $oldCreatedAt = now()->subMonths(4);
    $oldReading->forceFill(['created_at' => $oldCreatedAt, 'updated_at' => $oldCreatedAt])->save();

    expect($user->plan)->toBe(User::PLAN_FREE)
        ->and($user->tarotReadings()->where('created_at', '>=', now()->subMonths(3))->count())->toBe(55);
    $response = $this->actingAs($user)->get(route('readings.index', ['page' => 3]));
    $response->assertOk();
    expect($response->viewData('databaseReadings')->total())->toBe(50)
        ->and($response->viewData('databaseReadings')->perPage())->toBe(20)
        ->and($response->viewData('databaseReadings')->count())->toBe(10);
    $response->assertDontSee('有料プラン')
        ->assertSee('page=2');
    $readingsOnPage = $response->viewData('databaseReadings');
    expect($readingsOnPage->total())->toBe(50)
        ->and($readingsOnPage->count())->toBe(10)
        ->and($readingsOnPage->getCollection()->pluck('question')->all())
        ->toContain('Inside-cap marker')
        ->not->toContain('Outside-cap marker', 'Older question');
});

test('free members cannot open history older than three months or beyond the fifty-reading cap', function () {
    $user = User::factory()->create();
    $olderReading = $user->tarotReadings()->create([
        'question' => 'Older than three months',
        'card' => 'The Moon',
        'interpretation' => 'An old reading.',
    ]);
    $olderCreatedAt = now()->subMonths(4);
    $olderReading->forceFill(['created_at' => $olderCreatedAt, 'updated_at' => $olderCreatedAt])->save();

    $readings = [];
    foreach (range(1, 51) as $index) {
        $createdAt = now()->subDays(51 - $index);
        $reading = $user->tarotReadings()->create([
            'question' => "Newer question {$index}",
            'card' => 'The Star',
            'interpretation' => 'A recent reading.',
        ]);
        $reading->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();
        $readings[] = $reading;
    }

    expect($user->plan)->toBe(User::PLAN_FREE)
        ->and($user->tarotReadings()->where('created_at', '>', $readings[0]->created_at)->count())->toBe(50)
        ->and($readings[0]->created_at->toDateString())->toBe(now()->subDays(50)->toDateString());

    $this->actingAs($user)
        ->get(route('readings.show', $olderReading->id))
        ->assertNotFound();
    $this->get(route('readings.show', $readings[0]->id))->assertNotFound();
    $this->get(route('readings.show', $readings[1]->id))->assertOk();
});

test('category filtering does not reveal readings outside the free history cap', function () {
    $user = User::factory()->create();
    foreach (range(1, 51) as $daysAgo) {
        $reading = $user->tarotReadings()->create([
            'question' => "Recent question {$daysAgo}",
            'card' => 'The Star',
            'interpretation' => 'A reading.',
            'category' => $daysAgo === 51 ? '仕事' : '恋愛',
            'summary' => 'テスト要約です。',
        ]);
        $createdAt = now()->subDays($daysAgo);
        $reading->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();
    }

    $categoryResponse = $this->actingAs($user)
        ->get(route('readings.index'))
        ->assertOk();
    expect($categoryResponse->viewData('categories')->all())->toBe(['恋愛']);

    $response = $this->get(route('readings.index', ['category' => '仕事']));

    $response->assertOk()
        ->assertSee('「仕事」カテゴリの履歴はありません。')
        ->assertDontSee('Recent question 51');

    expect($response->viewData('databaseReadings')->total())->toBe(0);
});
