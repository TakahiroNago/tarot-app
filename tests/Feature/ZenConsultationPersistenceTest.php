<?php

use App\Models\User;
use App\Services\ConsultationCreditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Resources\Chat;
use OpenAI\Responses\Chat\CreateResponse;

uses(RefreshDatabase::class);

test('authenticated consultations are stored with their topic and summary', function () {
    $openAiFake = OpenAI::fake([
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
                    'content' => '{"topic":"仕事","summary":"仕事に関する前向きな助言です。"}',
                ],
            ]],
        ]),
    ]);
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->post(route('zen.respond'), ['concern' => 'What should I focus on?'])
        ->assertOk()
        ->assertViewHas('concern', 'What should I focus on?')
        ->assertViewHas('response', 'A useful interpretation.')
        ->assertViewHas('topic', '仕事')
        ->assertViewHas('summary', '仕事に関する前向きな助言です。')
        ->assertSee('禅師からのことば')
        ->assertDontSee('card-number')
        ->assertViewHas('shareUrl');

    $shareUrl = $response->viewData('shareUrl');
    $sharePath = parse_url($shareUrl, PHP_URL_PATH);
    expect($sharePath)->toMatch('/\/shared-consultations\/[a-f0-9]{64}$/')
        ->and(explode('/', $sharePath))->not->toContain((string) $user->id);

    $this->get($shareUrl)
        ->assertOk()
        ->assertSee('A useful interpretation.')
        ->assertSee('What should I focus on?')
        ->assertDontSee('card-number')
        ->assertDontSee($user->email)
        ->assertDontSee('account-profile-email');

    $this->get(route('consultations.share', ['token' => str_repeat('0', 64)]))
        ->assertNotFound();

    $reading = $user->zenConsultations()->sole();

    expect($reading->concern)->toBe('What should I focus on?')
        ->and($reading->response)->toBe('A useful interpretation.')
        ->and($reading->topic)->toBe('仕事')
        ->and($reading->summary)->toBe('仕事に関する前向きな助言です。');
    $openAiFake->assertSent(Chat::class, function (string $method, array $parameters): bool {
        return $method === 'create'
            && str_contains($parameters['messages'][0]['content'], '仏教禅師')
            && $parameters['messages'][1]['content'] === '相談：What should I focus on?';
    });

    expect(Schema::getColumnListing('zen_consultations'))
        ->toEqualCanonicalizing(['id', 'user_id', 'concern', 'response', 'topic', 'summary', 'daily_consultation_date', 'created_at', 'updated_at']);
});

test('premium consultations use the three latest summaries from the concern topic', function () {
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
        $reading = $user->zenConsultations()->create([
            'concern' => 'A past work question?',
            'response' => 'A past answer.',
            'topic' => '仕事',
            'summary' => $pastReading['summary'],
        ]);
        $reading->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();
    }

    $user->zenConsultations()->create([
        'concern' => 'A past love question?',
        'response' => 'A love answer.',
        'topic' => '恋愛',
        'summary' => 'Excluded different category summary.',
    ]);
    User::factory()->create()->zenConsultations()->create([
        'concern' => 'Another user work question?',
        'response' => 'Another user answer.',
        'topic' => '仕事',
        'summary' => 'Excluded another user summary.',
    ]);

    $this->actingAs($user)
        ->post(route('zen.respond'), ['concern' => 'How can I grow in my career?'])
        ->assertOk()
        ->assertViewHas('response', 'A history-informed interpretation.')
        ->assertViewHas('topic', '仕事')
        ->assertViewHas('summary', '今後に活かせる仕事の助言です。');

    $openAiFake->assertSent(Chat::class, function (string $method, array $parameters): bool {
        return $method === 'create'
            && $parameters['messages'][0]['content'] === '相談内容を「仕事」「恋愛」「お金」「家族」「人間関係」「健康」「将来」「自分自身」「その他」のいずれか1つに分類してください。分類結果のテーマ名だけを返してください。'
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

test('authenticated users can receive one daily Zen message per day without spending a credit', function () {
    $this->travelTo(now()->startOfDay());
    $user = User::factory()->create();
    $user->forceFill(['email_verified_at' => now()])->save();
    $creditService = app(ConsultationCreditService::class);
    $creditService->balance($user);
    $user->forceFill(['daily_credits' => 0])->save();
    $initialBalance = $creditService->balance($user)['total'];
    $openAiFake = OpenAI::fake([
        CreateResponse::fake([
            'choices' => [['message' => ['content' => '{"response":"今日のメッセージです。","topic":"その他","summary":"穏やかな気持ちで過ごせる日です。"}']]],
        ]),
        CreateResponse::fake([
            'choices' => [['message' => ['content' => '{"response":"明日のメッセージです。","topic":"将来","summary":"明日への希望を持てる日です。"}']]],
        ]),
    ]);

    $this->get(route('home'))
        ->assertOk()
        ->assertDontSee('今日のメッセージ');

    $this->actingAs($user)
        ->get(route('home'))
        ->assertOk()
        ->assertSee('今日のメッセージ')
        ->assertSee(route('zen.daily'), false);

    $this->post(route('zen.daily'))
        ->assertOk()
        ->assertViewHas('concern', '今日のメッセージ')
        ->assertViewHas('response', '今日のメッセージです。')
        ->assertViewHas('creditsRemaining', $initialBalance);

    $this->post(route('zen.daily'))
        ->assertOk()
        ->assertViewHas('concern', '今日のメッセージ')
        ->assertViewHas('response', '今日のメッセージです。');

    $this->get(route('consultations.index'))
        ->assertOk()
        ->assertSee('今日のメッセージ')
        ->assertDontSee('今日のメッセージをお願いします');

    $openAiFake->assertSent(Chat::class, 1);
    $openAiFake->assertSent(Chat::class, function (string $method, array $parameters): bool {
        return $method === 'create'
            && str_starts_with(
                $parameters['messages'][1]['content'],
                '相談：今日のあなたに向けた禅のメッセージをお願いします',
            );
    });
    $this->assertDatabaseHas('zen_consultations', [
        'user_id' => $user->id,
        'concern' => '今日のメッセージ',
        'response' => '今日のメッセージです。',
    ]);
    expect($user->zenConsultations()
        ->whereDate('daily_consultation_date', now()->toDateString())
        ->sole()
        ->daily_consultation_date->toDateString())->toBe(now()->toDateString());
    expect($user->refresh()->daily_credits)->toBe(0);

    $this->travelTo(now()->addDay()->startOfDay());
    $this->post(route('zen.daily'))
        ->assertOk()
        ->assertViewHas('response', '明日のメッセージです。');

    $openAiFake->assertSent(Chat::class, 2);
    $this->assertDatabaseCount('zen_consultations', 2);
    $this->assertDatabaseHas('zen_consultations', [
        'user_id' => $user->id,
        'concern' => '今日のメッセージ',
        'response' => '明日のメッセージです。',
    ]);
    expect($user->zenConsultations()
        ->whereDate('daily_consultation_date', now()->toDateString())
        ->sole()
        ->daily_consultation_date->toDateString())->toBe(now()->toDateString());
    expect($user->refresh()->daily_credits)->toBe(3);
});

test('guest consultations stay in the same session and are not written to the database', function () {
    OpenAI::fake([
        CreateResponse::fake([
            'choices' => [['message' => ['content' => 'First interpretation.']]],
        ]),
        CreateResponse::fake([
            'choices' => [['message' => ['content' => '{"topic":"恋愛","summary":"恋愛に関する助言です。"}']]],
        ]),
        CreateResponse::fake([
            'choices' => [['message' => ['content' => 'Second interpretation.']]],
        ]),
        CreateResponse::fake([
            'choices' => [['message' => ['content' => '{"topic":"その他","summary":"全体に関する助言です。"}']]],
        ]),
    ]);

    $firstResponse = $this->post(route('zen.respond'), ['concern' => 'First question?'])
        ->assertOk()
        ->assertSee('Share Link')
        ->assertDontSee('Xでシェア')
        ->assertDontSee('LINEでシェア')
        ->assertDontSee('Facebookでシェア')
        ->assertDontSee('twitter.com')
        ->assertDontSee('lineit/share')
        ->assertDontSee('facebook.com/sharer')
        ->assertDontSee('data-copy-consultation-url');
    $this->post(route('zen.respond'), ['concern' => 'Second question?'])->assertOk();

    $shareUrl = $firstResponse->viewData('shareUrl');
    expect(parse_url($shareUrl, PHP_URL_PATH))->toMatch('/\/shared-consultations\/[a-f0-9]{64}$/');

    $this->get($shareUrl)
        ->assertOk()
        ->assertSee('First question?')
        ->assertSee('First interpretation.')
        ->assertDontSee('card-number')
        ->assertDontSee('account-profile-email');

    $this->get(route('consultations.show', 'session-0'))
        ->assertOk()
        ->assertSee('禅師からのことば')
        ->assertSee('First interpretation.')
        ->assertDontSee('card-number');

    $consultations = session('zen_consultations');

    expect($consultations)->toHaveCount(2)
        ->and($consultations[0])->toHaveKeys(['concern', 'response', 'topic', 'summary'])
        ->and($consultations[0])->not->toHaveKey('card')
        ->and($consultations[0]['concern'])->toBe('First question?')
        ->and($consultations[0]['topic'])->toBe('恋愛')
        ->and($consultations[0]['summary'])->toBe('恋愛に関する助言です。')
        ->and($consultations[1]['concern'])->toBe('Second question?');

    $this->assertDatabaseCount('zen_consultations', 0);
});

test('consultation metadata outside the allowed topic and summary limits is rejected', function () {
    $this->withoutExceptionHandling();

    OpenAI::fake([
        CreateResponse::fake([
            'choices' => [['message' => ['content' => 'An interpretation.']]],
        ]),
        CreateResponse::fake([
            'choices' => [['message' => ['content' => '{"topic":"旅行","summary":"有効な要約"}']]],
        ]),
    ]);

    expect(fn () => $this->post(route('zen.respond'), ['concern' => 'A question?']))
        ->toThrow(UnexpectedValueException::class);

    $this->assertDatabaseCount('zen_consultations', 0);

    OpenAI::fake([
        CreateResponse::fake([
            'choices' => [['message' => ['content' => 'Another interpretation.']]],
        ]),
        CreateResponse::fake([
            'choices' => [['message' => ['content' => json_encode([
                'topic' => 'その他',
                'summary' => str_repeat('あ', 151),
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]]],
        ]),
    ]);

    expect(fn () => $this->post(route('zen.respond'), ['concern' => 'Another question?']))
        ->toThrow(UnexpectedValueException::class);

    $this->assertDatabaseCount('zen_consultations', 0);
});

test('users can view only their own database and session consultations', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $reading = $user->zenConsultations()->create([
        'concern' => 'My private question?',
        'response' => 'A private answer.',
    ]);
    $otherReading = $otherUser->zenConsultations()->create([
        'concern' => 'Another private question?',
        'response' => 'Another private answer.',
    ]);

    $this->actingAs($user)->withSession([
        'zen_consultations' => [[
            'concern' => 'Session question?',
            'response' => 'A session answer.',
        ]],
    ]);

    $this->get(route('consultations.index'))
        ->assertOk()
        ->assertSee('My private question?')
        ->assertSee('Session question?')
        ->assertSee('data-local-time')
        ->assertDontSee('Another private question?');

    $this->get(route('consultations.show', $reading->id))
        ->assertOk()
        ->assertSee('A private answer.');

    $this->get(route('consultations.show', $otherReading->id))->assertNotFound();
    $this->get(route('consultations.show', 'session-0'))
        ->assertOk()
        ->assertSee('A session answer.');
});

test('guests can view session readings but cannot open database reading ids', function () {
    $user = User::factory()->create();
    $reading = $user->zenConsultations()->create([
        'concern' => 'Account-only question?',
        'response' => 'Account-only answer.',
    ]);

    $this->withSession([
        'zen_consultations' => [[
            'concern' => 'Guest question?',
            'response' => 'Guest answer.',
        ]],
    ])
        ->get(route('consultations.index'))
        ->assertOk()
        ->assertSee('Guest question?')
        ->assertDontSee('Account-only question?');

    $this->get(route('consultations.show', 'session-0'))
        ->assertOk()
        ->assertSee('Guest answer.');

    $this->get(route('consultations.show', $reading->id))->assertRedirect(route('login'));
});

test('consultation history can be filtered by topic for database and session consultations', function () {
    $user = User::factory()->create();
    $user->zenConsultations()->create([
        'concern' => 'Work database question',
        'response' => 'A work answer.',
        'topic' => '仕事',
        'summary' => '仕事に関する要約です。',
    ]);
    $user->zenConsultations()->create([
        'concern' => 'Love database question',
        'response' => 'A love answer.',
        'topic' => '恋愛',
        'summary' => '恋愛に関する要約です。',
    ]);

    $response = $this->actingAs($user)
        ->withSession([
            'zen_consultations' => [
                [
                    'concern' => 'Work session question',
                    'response' => 'A work session answer.',
                    'topic' => '仕事',
                    'summary' => '仕事に関するセッション要約です。',
                ],
                [
                    'concern' => 'Love session question',
                    'response' => 'A love session answer.',
                    'topic' => '恋愛',
                    'summary' => '恋愛に関するセッション要約です。',
                ],
            ],
        ])
        ->get(route('consultations.index', ['topic' => '恋愛']));

    $response->assertOk()
        ->assertSee('Love database question')
        ->assertSee('Love session question')
        ->assertDontSee('Work database question')
        ->assertDontSee('Work session question')
        ->assertSee('value="恋愛" selected', false);

    expect($response->viewData('selectedTopic'))->toBe('恋愛')
        ->and($response->viewData('databaseConsultations')->total())->toBe(1)
        ->and($response->viewData('topics')->all())->toBe(['仕事', '恋愛'])
        ->and($response->viewData('sessionConsultations')->pluck('concern')->all())
        ->toBe(['Love session question']);
});
