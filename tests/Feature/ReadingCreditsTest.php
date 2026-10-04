<?php

use App\Models\User;
use App\Services\GuestTrialCreditService;
use App\Services\ReadingCreditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Responses\Chat\CreateResponse;

uses(RefreshDatabase::class);

test('the plans page shows the planned credit pack and subscription prices', function () {
    $response = $this->get(route('plans'));

    $response->assertOk()
        ->assertSee('10回')
        ->assertSee('100回')
        ->assertSee('月額280円')
        ->assertSee('月額1,980円')
        ->assertSee('Stripe');
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
                ->assertSee('無料体験を使い切りました。会員登録すると毎日3回占えます。 会員登録はこちら');
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
        ->assertSee('無料体験を使い切りました。会員登録すると毎日3回占えます。 会員登録はこちら');
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
        ->assertSee('残り <strong>3</strong> 回', false)
        ->assertSee(route('plans'))
        ->assertDontSee('今日の残り')
        ->assertDontSee('購入クレジット');
    expect($user->fresh()->daily_credits)->toBe(3);
});

test('only premium members do not see the plans link on the tarot page', function () {
    $plansLink = '<a class="text-link" href="'.route('plans').'">料金・プランを見る</a>';
    $freeMember = User::factory()->create();

    $this->actingAs($freeMember)
        ->get(route('tarot'))
        ->assertOk()
        ->assertSee($plansLink, false);

    $premiumMember = User::factory()->create();
    $premiumMember->forceFill(['plan' => User::PLAN_PREMIUM])->save();

    $this->actingAs($premiumMember)
        ->get(route('tarot'))
        ->assertOk()
        ->assertDontSee($plansLink, false);
});

test('legacy users with a null plan are treated as free members', function () {
    $dailyCreditLimit = new ReflectionMethod(ReadingCreditService::class, 'dailyCreditLimit');

    expect($dailyCreditLimit->invoke(app(ReadingCreditService::class), null))->toBe(3);
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

test('members with no credits see a full-width plans link and cannot draw a card', function () {
    $user = User::factory()->create();
    $user->forceFill([
        'daily_credits' => 0,
        'daily_credits_refreshed_at' => now()->toDateString(),
        'purchased_credits' => 0,
    ])->save();

    $this->actingAs($user)
        ->get(route('tarot'))
        ->assertOk()
        ->assertSee('href="'.route('plans').'"', false)
        ->assertSee('class="primary-action" type="submit" disabled', false)
        ->assertSee('本日利用可能なクレジットを使い切りました。クレジット購入または有料プランをご検討ください。 クレジット・プランを見る');
});

test('the result page links the entire exhausted member-credit notice to plans', function () {
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
        ->assertSee('<a class="form-notice credit-notice credit-notice-link" href="'.route('plans').'">', false)
        ->assertSee('本日利用可能なクレジットを使い切りました。クレジット購入または有料プランをご検討ください。 プランを見る');
});

test('paid plans accrue daily credits up to their cap while keeping purchased credits uncapped', function () {
    $user = User::factory()->create();
    $user->forceFill([
        'plan' => User::PLAN_LITE,
        'daily_credits' => 148,
        'daily_credits_refreshed_at' => now()->subDays(2)->toDateString(),
        'purchased_credits' => 105,
    ])->save();

    $this->actingAs($user)->get(route('tarot'))
        ->assertOk()
        ->assertSee('残り <strong>255</strong> 回', false)
        ->assertDontSee('今日の残り')
        ->assertDontSee('購入クレジット');

    expect($user->fresh()->daily_credits)->toBe(150)
        ->and($user->fresh()->purchased_credits)->toBe(105);
});

test('free members see only recent readings within the fifty-reading cap and receive an upgrade prompt', function () {
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
    $response = $this->actingAs($user)->get(route('readings.index', ['page' => 4]));
    $response->assertOk();
    expect($response->viewData('historyUpgradePrompt'))->toBeTrue()
        ->and($response->viewData('databaseReadings')->total())->toBe(50)
        ->and($response->viewData('databaseReadings')->count())->toBe(5);
    $response->assertSee('有料プランを見る');
    $readingsOnPage = $response->viewData('databaseReadings');
    expect($readingsOnPage->total())->toBe(50)
        ->and($readingsOnPage->count())->toBe(5)
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

test('premium members can open older history without a display cap', function () {
    $user = User::factory()->create();
    $user->forceFill(['plan' => User::PLAN_PREMIUM])->save();
    $oldReading = $user->tarotReadings()->create([
        'question' => 'Premium history',
        'card' => 'The World',
        'interpretation' => 'A preserved reading.',
    ]);
    $oldCreatedAt = now()->subYears(2);
    $oldReading->forceFill(['created_at' => $oldCreatedAt, 'updated_at' => $oldCreatedAt])->save();

    $this->actingAs($user)
        ->get(route('readings.show', $oldReading->id))
        ->assertOk()
        ->assertSee('A preserved reading.');
});
