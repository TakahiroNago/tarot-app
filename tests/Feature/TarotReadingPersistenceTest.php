<?php

use App\Models\User;
use App\Services\ReadingCreditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Resources\Chat;
use OpenAI\Responses\Chat\CreateResponse;

uses(RefreshDatabase::class);

test('authenticated readings are stored with their category and summary', function () {
    OpenAI::fake([
        CreateResponse::fake([
            'choices' => [[
                'message' => [
                    'content' => 'A useful interpretation.',
                ],
            ]],
        ]),
        CreateResponse::fake([
            'choices' => [[
                'message' => [
                    'content' => '{"category":"仕事","summary":"仕事に関する前向きな助言です。"}',
                ],
            ]],
        ]),
    ]);
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->post(route('tarot.draw'), ['question' => 'What should I focus on?'])
        ->assertOk()
        ->assertViewHas('question', 'What should I focus on?')
        ->assertViewHas('interpretation', 'A useful interpretation.')
        ->assertViewHas('category', '仕事')
        ->assertViewHas('summary', '仕事に関する前向きな助言です。')
        ->assertViewHas('shareUrl');

    $shareUrl = $response->viewData('shareUrl');
    $sharePath = parse_url($shareUrl, PHP_URL_PATH);
    expect($sharePath)->toMatch('/\/shared-readings\/[a-f0-9]{64}$/')
        ->and(explode('/', $sharePath))->not->toContain((string) $user->id);

    $this->get($shareUrl)
        ->assertOk()
        ->assertSee('A useful interpretation.')
        ->assertSee('What should I focus on?')
        ->assertDontSee($user->email)
        ->assertDontSee('account-profile-email');

    $this->get(route('readings.share', ['token' => str_repeat('0', 64)]))
        ->assertNotFound();

    $reading = $user->tarotReadings()->sole();

    expect($reading->question)->toBe('What should I focus on?')
        ->and($reading->card)->toBeString()
        ->and($reading->interpretation)->toBe('A useful interpretation.')
        ->and($reading->category)->toBe('仕事')
        ->and($reading->summary)->toBe('仕事に関する前向きな助言です。');

    expect(Schema::getColumnListing('tarot_readings'))
        ->toEqualCanonicalizing(['id', 'user_id', 'question', 'card', 'interpretation', 'category', 'summary', 'daily_reading_date', 'created_at', 'updated_at']);
});

test('premium readings use the three latest summaries from the question category', function () {
    $openAiFake = OpenAI::fake([
        CreateResponse::fake([
            'choices' => [['message' => ['content' => '仕事']]],
        ]),
        CreateResponse::fake([
            'choices' => [['message' => ['content' => 'A history-informed interpretation.']]],
        ]),
        CreateResponse::fake([
            'choices' => [['message' => ['content' => '{"summary":"今後に活かせる仕事の助言です。"}']]],
        ]),
    ]);
    $user = User::factory()->create();
    $user->forceFill(['plan' => User::PLAN_PREMIUM])->save();

    foreach ([
        ['summary' => 'Excluded oldest summary.', 'days_ago' => 4],
        ['summary' => 'Included third summary.', 'days_ago' => 3],
        ['summary' => 'Included second summary.', 'days_ago' => 2],
        ['summary' => 'Included latest summary.', 'days_ago' => 1],
    ] as $pastReading) {
        $createdAt = now()->subDays($pastReading['days_ago']);
        $reading = $user->tarotReadings()->create([
            'question' => 'A past work question?',
            'card' => 'The Star',
            'interpretation' => 'A past answer.',
            'category' => '仕事',
            'summary' => $pastReading['summary'],
        ]);
        $reading->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();
    }

    $user->tarotReadings()->create([
        'question' => 'A past love question?',
        'card' => 'The Lovers',
        'interpretation' => 'A love answer.',
        'category' => '恋愛',
        'summary' => 'Excluded different category summary.',
    ]);
    User::factory()->create()->tarotReadings()->create([
        'question' => 'Another user work question?',
        'card' => 'The Sun',
        'interpretation' => 'Another user answer.',
        'category' => '仕事',
        'summary' => 'Excluded another user summary.',
    ]);

    $this->actingAs($user)
        ->post(route('tarot.draw'), ['question' => 'How can I grow in my career?'])
        ->assertOk()
        ->assertViewHas('interpretation', 'A history-informed interpretation.')
        ->assertViewHas('category', '仕事')
        ->assertViewHas('summary', '今後に活かせる仕事の助言です。');

    $openAiFake->assertSent(Chat::class, function (string $method, array $parameters): bool {
        return $method === 'create'
            && $parameters['messages'][0]['content'] === '質問文を「仕事」「恋愛」「お金」「家族」「人間関係」「健康」「将来」「自分自身」「その他」のいずれか1つに分類してください。分類結果のカテゴリ名だけを返してください。'
            && $parameters['messages'][1]['content'] === 'How can I grow in my career?';
    });
    $openAiFake->assertSent(Chat::class, function (string $method, array $parameters): bool {
        $prompt = $parameters['messages'][1]['content'];

        if ($method !== 'create' || ! str_contains($parameters['messages'][0]['content'], '提示された過去')) {
            return false;
        }

        expect($prompt)
            ->toContain('Included latest summary.')
            ->toContain('Included second summary.')
            ->toContain('Included third summary.')
            ->not->toContain('Excluded oldest summary.')
            ->not->toContain('Excluded different category summary.')
            ->not->toContain('Excluded another user summary.')
            ->toContain(now()->subDay()->format('Y/m/d H:i'))
            ->toContain(now()->subDays(2)->format('Y/m/d H:i'))
            ->toContain(now()->subDays(3)->format('Y/m/d H:i'));

        return true;
    });
    $openAiFake->assertSent(Chat::class, 3);
});

test('authenticated users can draw one daily reading per day without spending a credit', function () {
    $this->travelTo(now()->startOfDay());
    $user = User::factory()->create();
    $user->forceFill(['email_verified_at' => now()])->save();
    $creditService = app(ReadingCreditService::class);
    $creditService->balance($user);
    $user->forceFill(['daily_credits' => 0])->save();
    $initialBalance = $creditService->balance($user)['total'];
    $openAiFake = OpenAI::fake([
        CreateResponse::fake([
            'choices' => [['message' => ['content' => '{"interpretation":"今日のメッセージです。","category":"その他","summary":"穏やかな気持ちで過ごせる日です。"}']]],
        ]),
        CreateResponse::fake([
            'choices' => [['message' => ['content' => '{"interpretation":"明日のメッセージです。","category":"将来","summary":"明日への希望を持てる日です。"}']]],
        ]),
    ]);

    $this->get(route('home'))
        ->assertOk()
        ->assertDontSee('今日の1枚');

    $this->actingAs($user)
        ->get(route('home'))
        ->assertOk()
        ->assertSee('今日の1枚')
        ->assertSee(route('tarot.daily'), false);

    $this->post(route('tarot.daily'))
        ->assertOk()
        ->assertViewHas('question', '今日のメッセージ')
        ->assertViewHas('interpretation', '今日のメッセージです。')
        ->assertViewHas('creditsRemaining', $initialBalance);

    $this->post(route('tarot.daily'))
        ->assertOk()
        ->assertViewHas('question', '今日のメッセージ')
        ->assertViewHas('interpretation', '今日のメッセージです。');

    $this->get(route('readings.index'))
        ->assertOk()
        ->assertSee('今日のメッセージ')
        ->assertDontSee('今日のメッセージをお願いします');

    $openAiFake->assertSent(Chat::class, 1);
    $openAiFake->assertSent(Chat::class, function (string $method, array $parameters): bool {
        return $method === 'create'
            && str_starts_with(
                $parameters['messages'][1]['content'],
                "質問：今日のメッセージをお願いします\nカード：",
            );
    });
    $this->assertDatabaseHas('tarot_readings', [
        'user_id' => $user->id,
        'question' => '今日のメッセージ',
        'interpretation' => '今日のメッセージです。',
    ]);
    expect($user->tarotReadings()
        ->whereDate('daily_reading_date', now()->toDateString())
        ->sole()
        ->daily_reading_date->toDateString())->toBe(now()->toDateString());
    expect($user->refresh()->daily_credits)->toBe(0);

    $this->travelTo(now()->addDay()->startOfDay());
    $this->post(route('tarot.daily'))
        ->assertOk()
        ->assertViewHas('interpretation', '明日のメッセージです。');

    $openAiFake->assertSent(Chat::class, 2);
    $this->assertDatabaseCount('tarot_readings', 2);
    $this->assertDatabaseHas('tarot_readings', [
        'user_id' => $user->id,
        'question' => '今日のメッセージ',
        'interpretation' => '明日のメッセージです。',
    ]);
    expect($user->tarotReadings()
        ->whereDate('daily_reading_date', now()->toDateString())
        ->sole()
        ->daily_reading_date->toDateString())->toBe(now()->toDateString());
    expect($user->refresh()->daily_credits)->toBe(3);
});

test('guest readings stay in the same session and are not written to the database', function () {
    OpenAI::fake([
        CreateResponse::fake([
            'choices' => [['message' => ['content' => 'First interpretation.']]],
        ]),
        CreateResponse::fake([
            'choices' => [['message' => ['content' => '{"category":"恋愛","summary":"恋愛に関する助言です。"}']]],
        ]),
        CreateResponse::fake([
            'choices' => [['message' => ['content' => 'Second interpretation.']]],
        ]),
        CreateResponse::fake([
            'choices' => [['message' => ['content' => '{"category":"その他","summary":"全体に関する助言です。"}']]],
        ]),
    ]);

    $firstResponse = $this->post(route('tarot.draw'), ['question' => 'First question?'])->assertOk();
    $this->post(route('tarot.draw'), ['question' => 'Second question?'])->assertOk();

    $shareUrl = $firstResponse->viewData('shareUrl');
    expect(parse_url($shareUrl, PHP_URL_PATH))->toMatch('/\/shared-readings\/[a-f0-9]{64}$/');

    $this->get($shareUrl)
        ->assertOk()
        ->assertSee('First question?')
        ->assertSee('First interpretation.')
        ->assertDontSee('account-profile-email');

    $readings = session('tarot_readings');

    expect($readings)->toHaveCount(2)
        ->and($readings[0])->toHaveKeys(['question', 'card', 'interpretation', 'category', 'summary'])
        ->and($readings[0]['question'])->toBe('First question?')
        ->and($readings[0]['category'])->toBe('恋愛')
        ->and($readings[0]['summary'])->toBe('恋愛に関する助言です。')
        ->and($readings[1]['question'])->toBe('Second question?');
    expect(array_key_exists('cardNumber', $readings[0]))->toBeFalse()
        ->and(array_key_exists('cardSymbol', $readings[0]))->toBeFalse()
        ->and(array_key_exists('cardEnglishName', $readings[0]))->toBeFalse();

    $this->assertDatabaseCount('tarot_readings', 0);
});

test('reading metadata outside the allowed category and summary limits is rejected', function () {
    $this->withoutExceptionHandling();

    OpenAI::fake([
        CreateResponse::fake([
            'choices' => [['message' => ['content' => 'An interpretation.']]],
        ]),
        CreateResponse::fake([
            'choices' => [['message' => ['content' => '{"category":"旅行","summary":"有効な要約"}']]],
        ]),
    ]);

    expect(fn () => $this->post(route('tarot.draw'), ['question' => 'A question?']))
        ->toThrow(UnexpectedValueException::class);

    $this->assertDatabaseCount('tarot_readings', 0);

    OpenAI::fake([
        CreateResponse::fake([
            'choices' => [['message' => ['content' => 'Another interpretation.']]],
        ]),
        CreateResponse::fake([
            'choices' => [['message' => ['content' => json_encode([
                'category' => 'その他',
                'summary' => str_repeat('あ', 151),
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]]],
        ]),
    ]);

    expect(fn () => $this->post(route('tarot.draw'), ['question' => 'Another question?']))
        ->toThrow(UnexpectedValueException::class);

    $this->assertDatabaseCount('tarot_readings', 0);
});

test('users can view only their own database readings and session readings', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $reading = $user->tarotReadings()->create([
        'question' => 'My private question?',
        'card' => 'The Star',
        'interpretation' => 'A private answer.',
    ]);
    $otherReading = $otherUser->tarotReadings()->create([
        'question' => 'Another private question?',
        'card' => 'The Tower',
        'interpretation' => 'Another private answer.',
    ]);

    $this->actingAs($user)->withSession([
        'tarot_readings' => [[
            'question' => 'Session question?',
            'card' => 'The Fool',
            'interpretation' => 'A session answer.',
        ]],
    ]);

    $this->get(route('readings.index'))
        ->assertOk()
        ->assertSee('My private question?')
        ->assertSee('Session question?')
        ->assertSee('data-local-time')
        ->assertDontSee('Another private question?');

    $this->get(route('readings.show', $reading->id))
        ->assertOk()
        ->assertSee('A private answer.')
        ->assertSee('The Star')
        ->assertSee('XVII')
        ->assertSee('✷')
        ->assertSee('THE STAR');

    $this->get(route('readings.show', $otherReading->id))->assertNotFound();
    $this->get(route('readings.show', 'session-0'))
        ->assertOk()
        ->assertSee('A session answer.')
        ->assertSee('0')
        ->assertSee('THE FOOL');
});

test('guests can view session readings but cannot open database reading ids', function () {
    $user = User::factory()->create();
    $reading = $user->tarotReadings()->create([
        'question' => 'Account-only question?',
        'card' => 'The Moon',
        'interpretation' => 'Account-only answer.',
    ]);

    $this->withSession([
        'tarot_readings' => [[
            'question' => 'Guest question?',
            'card' => 'The Sun',
            'interpretation' => 'Guest answer.',
        ]],
    ])
        ->get(route('readings.index'))
        ->assertOk()
        ->assertSee('Guest question?')
        ->assertDontSee('Account-only question?');

    $this->get(route('readings.show', 'session-0'))
        ->assertOk()
        ->assertSee('Guest answer.')
        ->assertSee('XIX')
        ->assertSee('THE SUN');

    $this->get(route('readings.show', $reading->id))->assertRedirect(route('login'));
});
