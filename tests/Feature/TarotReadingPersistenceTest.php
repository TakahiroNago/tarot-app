<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Responses\Chat\CreateResponse;

uses(RefreshDatabase::class);

test('authenticated readings are stored with only the requested result fields', function () {
    OpenAI::fake([
        CreateResponse::fake([
            'choices' => [[
                'message' => [
                    'content' => 'A useful interpretation.',
                ],
            ]],
        ]),
    ]);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('tarot.draw'), ['question' => 'What should I focus on?'])
        ->assertOk()
        ->assertViewHas('question', 'What should I focus on?')
        ->assertViewHas('interpretation', 'A useful interpretation.');

    $reading = $user->tarotReadings()->sole();

    expect($reading->question)->toBe('What should I focus on?')
        ->and($reading->card)->toBeString()
        ->and($reading->interpretation)->toBe('A useful interpretation.');

    expect(Schema::getColumnListing('tarot_readings'))
        ->toEqualCanonicalizing(['id', 'user_id', 'question', 'card', 'interpretation', 'created_at', 'updated_at']);
});

test('guest readings stay in the same session and are not written to the database', function () {
    OpenAI::fake([
        CreateResponse::fake([
            'choices' => [['message' => ['content' => 'First interpretation.']]],
        ]),
        CreateResponse::fake([
            'choices' => [['message' => ['content' => 'Second interpretation.']]],
        ]),
    ]);

    $this->post(route('tarot.draw'), ['question' => 'First question?'])->assertOk();
    $this->post(route('tarot.draw'), ['question' => 'Second question?'])->assertOk();

    $readings = session('tarot_readings');

    expect($readings)->toHaveCount(2)
        ->and($readings[0])->toHaveKeys(['question', 'card', 'interpretation'])
        ->and($readings[0]['question'])->toBe('First question?')
        ->and($readings[1]['question'])->toBe('Second question?');
    expect(array_key_exists('cardNumber', $readings[0]))->toBeFalse()
        ->and(array_key_exists('cardSymbol', $readings[0]))->toBeFalse()
        ->and(array_key_exists('cardEnglishName', $readings[0]))->toBeFalse();

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
        ->assertDontSee('Another private question?');

    $this->get(route('readings.show', $reading->id))
        ->assertOk()
        ->assertSee('A private answer.')
        ->assertSee('The Star');

    $this->get(route('readings.show', $otherReading->id))->assertNotFound();
    $this->get(route('readings.show', 'session-0'))
        ->assertOk()
        ->assertSee('A session answer.');
});

test('users can save a session reading to their history', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->withSession([
        'tarot_readings' => [
            [
                'question' => 'First session question?',
                'card' => 'The Fool',
                'interpretation' => 'First session answer.',
            ],
            [
                'question' => 'Second session question?',
                'card' => 'The Star',
                'interpretation' => 'Second session answer.',
            ],
        ],
    ]);

    $this->post(route('readings.save', 'session-0'))
        ->assertRedirect(route('readings.index'));

    $this->assertDatabaseHas('tarot_readings', [
        'user_id' => $user->id,
        'question' => 'First session question?',
        'card' => 'The Fool',
        'interpretation' => 'First session answer.',
    ]);
    expect(session('tarot_readings'))
        ->toHaveCount(1)
        ->and(session('tarot_readings.1.question'))->toBe('Second session question?');

    $this->get(route('readings.index'))
        ->assertOk()
        ->assertSee('Second session question?')
        ->assertSee('First session question?')
        ->assertSee('保存');
});

test('guests cannot save session readings to a database account', function () {
    $this->withSession([
        'tarot_readings' => [[
            'question' => 'Guest session question?',
            'card' => 'The Sun',
            'interpretation' => 'Guest session answer.',
        ]],
    ])
        ->post(route('readings.save', 'session-0'))
        ->assertRedirect(route('login'));

    $this->assertDatabaseCount('tarot_readings', 0);
    expect(session('tarot_readings'))->toHaveCount(1);
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
        ->assertDontSee('保存')
        ->assertDontSee('Account-only question?');

    $this->get(route('readings.show', 'session-0'))
        ->assertOk()
        ->assertSee('Guest answer.');

    $this->get(route('readings.show', $reading->id))->assertRedirect(route('login'));
});
