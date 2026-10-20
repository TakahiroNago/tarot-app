<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Resources\Chat;
use OpenAI\Responses\Chat\CreateResponse;

uses(RefreshDatabase::class);

function fakeConsultationResponses(): mixed
{
    return OpenAI::fake([
        CreateResponse::fake(['choices' => [['message' => ['content' => 'A calm answer.']]]]),
        CreateResponse::fake(['choices' => [['message' => ['content' => '{"topic":"仕事","summary":"要約です。"}']]]]),
    ]);
}

test('a concern with personal information is stopped before calling OpenAI', function () {
    $openAiFake = OpenAI::fake();
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from(route('zen'))
        ->post(route('zen.respond'), ['concern' => '上司の連絡先は boss@example.com です'])
        ->assertRedirect(route('zen'))
        ->assertSessionHasErrors('concern')
        ->assertSessionHasInput('concern', '上司の連絡先は boss@example.com です')
        ->assertSessionHas('personal_info_detected');

    $openAiFake->assertNothingSent();
    expect($user->zenConsultations()->count())->toBe(0);

    $this->actingAs($user)
        ->withSession(['personal_info_detected' => true])
        ->get(route('zen'))
        ->assertSee('confirm_personal_info', false);
});

test('a confirmed concern with personal information is sent but hidden on the shared url', function () {
    $openAiFake = fakeConsultationResponses();
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->post(route('zen.respond'), [
            'concern' => '上司の連絡先は boss@example.com です',
            'confirm_personal_info' => '1',
        ])
        ->assertOk();

    $openAiFake->assertSent(Chat::class, fn (string $method, array $parameters): bool => $method === 'create'
        && str_contains($parameters['messages'][0]['content'], '個人情報'));

    $this->get($response->viewData('shareUrl'))
        ->assertOk()
        ->assertDontSee('boss@example.com')
        ->assertSee('プライバシー保護のため、相談文は表示されません。')
        ->assertSee('A calm answer.');
});

test('a concern without personal information remains visible on the shared url', function () {
    fakeConsultationResponses();
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->post(route('zen.respond'), ['concern' => '仕事に迷っています'])
        ->assertOk();

    $this->get($response->viewData('shareUrl'))
        ->assertOk()
        ->assertSee('仕事に迷っています');
});

test('an anonymized suggestion is offered and can be sent without being hidden', function () {
    $openAiFake = fakeConsultationResponses();
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('zen.respond'), ['concern' => '同僚の田中さんと上手くいきません'])
        ->assertSessionHas('anonymized_concern', '同僚の[氏名]と上手くいきません');

    $this->actingAs($user)
        ->withSession(['anonymized_concern' => '同僚の[氏名]と上手くいきません'])
        ->get(route('zen'))
        ->assertSee('この内容で相談する');

    $response = $this->actingAs($user)
        ->post(route('zen.respond'), ['concern' => '同僚の[氏名]と上手くいきません'])
        ->assertOk();

    $this->get($response->viewData('shareUrl'))->assertSee('同僚の[氏名]と上手くいきません');
});
