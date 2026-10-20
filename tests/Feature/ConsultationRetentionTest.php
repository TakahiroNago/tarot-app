<?php

use App\Models\User;
use App\Models\ZenConsultation;
use App\Services\PublicConsultationShareService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Responses\Chat\CreateResponse;

uses(RefreshDatabase::class);

function savedConsultation(User $user, array $attributes = []): ZenConsultation
{
    return $user->zenConsultations()->create([
        'concern' => '仕事に迷っています',
        'response' => 'ゆっくり考えましょう。',
        ...$attributes,
    ]);
}

test('the prune command deletes expired consultations containing personal information and revokes their share links', function () {
    config(['zen.consultation_retention_days' => 30]);
    $shareService = app(PublicConsultationShareService::class);
    $user = User::factory()->create();
    $token = $shareService->create(['concern' => 'old', 'response' => 'old answer'], true);
    $old = savedConsultation($user, ['share_token_hash' => $shareService->tokenHash($token), 'contains_personal_info' => true]);
    $old->forceFill(['created_at' => now()->subDays(31)])->saveQuietly();
    $oldWithoutPersonalInfo = savedConsultation($user, ['contains_personal_info' => false]);
    $oldWithoutPersonalInfo->forceFill(['created_at' => now()->subYears(3)])->saveQuietly();
    $recent = savedConsultation($user, ['contains_personal_info' => true]);

    Artisan::call('zen:prune-consultations');

    expect(ZenConsultation::find($old->id))->toBeNull()
        ->and(ZenConsultation::find($oldWithoutPersonalInfo->id))->not->toBeNull()
        ->and(ZenConsultation::find($recent->id))->not->toBeNull()
        ->and($shareService->find($token))->toBeNull();
});

test('the prune command does nothing when retention is disabled', function () {
    config(['zen.consultation_retention_days' => 0]);
    $old = savedConsultation(User::factory()->create(), ['contains_personal_info' => true]);
    $old->forceFill(['created_at' => now()->subYears(5)])->saveQuietly();

    Artisan::call('zen:prune-consultations');

    expect(ZenConsultation::find($old->id))->not->toBeNull();
});

test('a user can delete a consultation and its share link', function () {
    $user = User::factory()->create();
    $consultation = savedConsultation($user);

    $page = $this->actingAs($user)->get(route('consultations.show', $consultation->id))->assertOk();
    $shareUrl = $page->viewData('shareUrl');
    $this->get($shareUrl)->assertOk();

    $this->actingAs($user)
        ->delete(route('consultations.destroy', $consultation->id))
        ->assertRedirect(route('consultations.index'));

    expect(ZenConsultation::find($consultation->id))->toBeNull();
    $this->get($shareUrl)->assertNotFound();
});

test('a user cannot delete another users consultation', function () {
    $other = savedConsultation(User::factory()->create());

    $this->actingAs(User::factory()->create())
        ->delete(route('consultations.destroy', $other->id))
        ->assertNotFound();

    expect(ZenConsultation::find($other->id))->not->toBeNull();
});

test('a user can delete all of their consultations only', function () {
    $user = User::factory()->create();
    savedConsultation($user);
    savedConsultation($user);
    $other = savedConsultation(User::factory()->create());

    $this->actingAs($user)
        ->delete(route('consultations.destroy-all'))
        ->assertRedirect(route('consultations.index'));

    expect($user->zenConsultations()->count())->toBe(0)
        ->and(ZenConsultation::find($other->id))->not->toBeNull();
});

test('a guest can delete a session consultation', function () {
    $this->withSession(['zen_consultations' => [['concern' => 'a', 'response' => 'b']]])
        ->delete(route('consultations.destroy', 'session-0'))
        ->assertRedirect(route('consultations.index'))
        ->assertSessionMissing('zen_consultations.0');
});

test('re-opening a saved consultation hides a concern containing personal information on its share page', function () {
    $user = User::factory()->create();
    $consultation = savedConsultation($user, ['concern' => '連絡先は boss@example.com']);

    $page = $this->actingAs($user)->get(route('consultations.show', $consultation->id))->assertOk();

    $this->get($page->viewData('shareUrl'))
        ->assertOk()
        ->assertDontSee('boss@example.com');
});

test('the shared page is not indexable and exposes no open graph metadata', function () {
    $token = app(PublicConsultationShareService::class)->create(['concern' => '秘密の相談', 'response' => '回答']);

    $this->get(route('consultations.share', ['token' => $token]))
        ->assertOk()
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')
        ->assertSee('<meta name="robots" content="noindex, nofollow, noarchive">', false)
        ->assertDontSee('og:', false)
        ->assertDontSee('twitter:', false);
});

test('privacy policy describes retention and personal information handling', function () {
    $this->get(route('legal.privacy'))->assertOk()->assertSee('365日')->assertSee('個人情報を入力しないでください');
});

test('consultations are flagged by whether personal information was detected', function () {
    OpenAI::fake([
        CreateResponse::fake(['choices' => [['message' => ['content' => 'answer']]]]),
        CreateResponse::fake(['choices' => [['message' => ['content' => '{"topic":"仕事","summary":"要約"}']]]]),
        CreateResponse::fake(['choices' => [['message' => ['content' => 'answer']]]]),
        CreateResponse::fake(['choices' => [['message' => ['content' => '{"topic":"仕事","summary":"要約"}']]]]),
    ]);
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    $this->actingAs($user)->post(route('zen.respond'), ['concern' => '仕事に迷っています'])->assertOk();
    $this->actingAs($otherUser)->post(route('zen.respond'), ['concern' => '連絡先は boss@example.com', 'confirm_personal_info' => '1'])->assertOk();

    expect($user->zenConsultations()->sole()->contains_personal_info)->toBeFalse()
        ->and($otherUser->zenConsultations()->sole()->contains_personal_info)->toBeTrue();
});
