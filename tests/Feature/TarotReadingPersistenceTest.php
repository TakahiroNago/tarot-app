<?php

use App\Models\InventoryBalance;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\ReadingCreditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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

test('a saved reading and its public share reuse the same eligible product recommendation', function () {
    OpenAI::fake([
        CreateResponse::fake([
            'choices' => [['message' => ['content' => 'A useful interpretation.']]],
        ]),
        CreateResponse::fake([
            'choices' => [['message' => ['content' => '{"category":"仕事","summary":"仕事に関する前向きな助言です。"}']]],
        ]),
        CreateResponse::fake([
            'choices' => [['message' => ['content' => 'Another useful interpretation.']]],
        ]),
        CreateResponse::fake([
            'choices' => [['message' => ['content' => '{"category":"将来","summary":"将来に向けた助言です。"}']]],
        ]),
    ]);
    $user = User::factory()->create();
    $product = Product::factory()->create([
        'name' => 'Recommended Tarot Deck',
        'slug' => 'recommended-tarot-deck',
        'status' => 'published',
        'reading_recommendable' => true,
    ]);
    $variant = ProductVariant::factory()->for($product)->create();
    InventoryBalance::factory()->for($variant, 'variant')->create([
        'on_hand' => 1,
        'reserved' => 0,
    ]);

    $response = $this->actingAs($user)
        ->post(route('tarot.draw'), ['question' => 'What should I focus on?'])
        ->assertOk()
        ->assertViewHas('recommendedProduct', fn (?Product $recommendedProduct): bool => $recommendedProduct?->is($product) ?? false);
    $savedReading = $user->tarotReadings()->sole();
    $shareUrl = $response->viewData('shareUrl');

    expect($savedReading->productRecommendation()->value('product_id'))->toBe($product->id);

    $this->get(route('readings.show', $savedReading->id))
        ->assertOk()
        ->assertSee('Recommended Tarot Deck')
        ->assertSee(route('shop.products.show', 'recommended-tarot-deck'));
    $this->get($shareUrl)
        ->assertOk()
        ->assertSee('Recommended Tarot Deck')
        ->assertSee(route('shop.products.show', 'recommended-tarot-deck'));

    $secondResponse = $this->actingAs($user)
        ->post(route('tarot.draw'), ['question' => 'Another question?'])
        ->assertOk();
    expect($secondResponse->viewData('recommendedProduct')->id)->toBe($product->id);
});

test('guest reading recommendations remain stable in session history and the public share', function () {
    OpenAI::fake([
        CreateResponse::fake([
            'choices' => [['message' => ['content' => 'A guest interpretation.']]],
        ]),
        CreateResponse::fake([
            'choices' => [['message' => ['content' => '{"category":"その他","summary":"全体に関する助言です。"}']]],
        ]),
    ]);
    $product = Product::factory()->create([
        'name' => 'Guest Tarot Deck',
        'slug' => 'guest-tarot-deck',
        'status' => 'published',
        'reading_recommendable' => true,
    ]);
    $variant = ProductVariant::factory()->for($product)->create();
    InventoryBalance::factory()->for($variant, 'variant')->create([
        'on_hand' => 1,
        'reserved' => 0,
    ]);

    $response = $this->post(route('tarot.draw'), ['question' => 'A guest question?'])
        ->assertOk()
        ->assertViewHas('recommendedProduct', fn (?Product $recommendedProduct): bool => $recommendedProduct?->is($product) ?? false);
    $shareUrl = $response->viewData('shareUrl');
    expect(session('tarot_readings.0.recommended_product_id'))->toBe($product->id);

    $this->get(route('readings.show', 'session-0'))
        ->assertOk()
        ->assertSee('Guest Tarot Deck')
        ->assertSee(route('shop.products.show', 'guest-tarot-deck'));
    $this->get($shareUrl)
        ->assertOk()
        ->assertSee('Guest Tarot Deck')
        ->assertSee(route('shop.products.show', 'guest-tarot-deck'));
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

test('the daily reading changes at midnight in Japan', function () {
    $this->travelTo(Carbon::parse('2030-03-31 14:59:58', 'UTC'));
    $user = User::factory()->create();
    $user->forceFill(['email_verified_at' => now()])->save();
    OpenAI::fake([
        CreateResponse::fake([
            'choices' => [['message' => ['content' => '{"interpretation":"3月のメッセージです。","category":"その他","summary":"3月の要約です。"}']]],
        ]),
        CreateResponse::fake([
            'choices' => [['message' => ['content' => '{"interpretation":"4月のメッセージです。","category":"その他","summary":"4月の要約です。"}']]],
        ]),
    ]);

    $this->actingAs($user)->post(route('tarot.daily'))
        ->assertViewHas('interpretation', '3月のメッセージです。');

    $this->travelTo(Carbon::parse('2030-03-31 14:59:59', 'UTC'));
    $this->post(route('tarot.daily'))
        ->assertViewHas('interpretation', '3月のメッセージです。');

    $this->travelTo(Carbon::parse('2030-03-31 15:00:00', 'UTC'));
    $this->post(route('tarot.daily'))
        ->assertViewHas('interpretation', '4月のメッセージです。');
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

test('reading history can be filtered by category for database and session readings', function () {
    $user = User::factory()->create();
    $user->tarotReadings()->create([
        'question' => 'Work database question',
        'card' => 'The Star',
        'interpretation' => 'A work answer.',
        'category' => '仕事',
        'summary' => '仕事に関する要約です。',
    ]);
    $user->tarotReadings()->create([
        'question' => 'Love database question',
        'card' => 'The Lovers',
        'interpretation' => 'A love answer.',
        'category' => '恋愛',
        'summary' => '恋愛に関する要約です。',
    ]);

    $response = $this->actingAs($user)
        ->withSession([
            'tarot_readings' => [
                [
                    'question' => 'Work session question',
                    'card' => 'The Sun',
                    'interpretation' => 'A work session answer.',
                    'category' => '仕事',
                    'summary' => '仕事に関するセッション要約です。',
                ],
                [
                    'question' => 'Love session question',
                    'card' => 'The Moon',
                    'interpretation' => 'A love session answer.',
                    'category' => '恋愛',
                    'summary' => '恋愛に関するセッション要約です。',
                ],
            ],
        ])
        ->get(route('readings.index', ['category' => '恋愛']));

    $response->assertOk()
        ->assertSee('Love database question')
        ->assertSee('Love session question')
        ->assertDontSee('Work database question')
        ->assertDontSee('Work session question')
        ->assertSee('value="恋愛" selected', false);

    expect($response->viewData('selectedCategory'))->toBe('恋愛')
        ->and($response->viewData('databaseReadings')->total())->toBe(1)
        ->and($response->viewData('categories')->all())->toBe(['仕事', '恋愛'])
        ->and($response->viewData('sessionReadings')->pluck('question')->all())
        ->toBe(['Love session question']);
});
