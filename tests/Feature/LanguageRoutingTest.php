<?php

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

test('the root URL selects Japanese for a Japanese browser', function () {
    $this->withHeader('Accept-Language', 'ja-JP,ja;q=0.9,en;q=0.8')
        ->get('/')
        ->assertRedirect(route('home', ['locale' => 'ja']));
});

test('the root URL selects English for other browser languages', function () {
    $this->withHeader('Accept-Language', 'fr-FR,fr;q=0.9')
        ->get('/')
        ->assertRedirect(route('home', ['locale' => 'en']));
});

test('a guest language selection is reused when the root URL is visited', function () {
    $this->get('/ja')->assertOk();

    $this->withHeader('Accept-Language', 'en-US')
        ->get('/')
        ->assertRedirect(route('home', ['locale' => 'ja']));
});

test('the root URL uses an authenticated user preferred locale', function () {
    $user = User::factory()->create(['preferred_locale' => 'en']);

    $this->actingAs($user)
        ->withHeader('Accept-Language', 'ja-JP')
        ->get('/')
        ->assertRedirect(route('home', ['locale' => 'en']));
});

test('login saves the locale selected on the login page', function () {
    Notification::fake();
    $user = User::factory()->create([
        'email' => 'locale-reader@example.com',
        'password' => 'A-strong-password-2026',
        'preferred_locale' => 'ja',
    ]);

    $this->post(route('login.store', ['locale' => 'en']), [
        'email' => $user->email,
        'password' => 'A-strong-password-2026',
    ])->assertRedirect(route('home', ['locale' => 'en']));

    expect($user->fresh()->preferred_locale)->toBe('en');
});

test('authentication emails link back to the users preferred locale', function () {
    $user = User::factory()->create(['preferred_locale' => 'ja']);

    $passwordResetUrl = (new ResetPassword('reset-token'))->toMail($user)->actionUrl;
    $verificationUrl = (new VerifyEmail)->toMail($user)->actionUrl;

    expect(parse_url($passwordResetUrl, PHP_URL_PATH))
        ->toBe('/ja/reset-password/reset-token')
        ->and(parse_url($verificationUrl, PHP_URL_PATH))
        ->toMatch('/^\\/ja\\/email\\/verify\\//');
});

test('localized pages set the app locale and persist it for signed in users', function () {
    $user = User::factory()->create(['preferred_locale' => 'ja']);

    $this->actingAs($user)
        ->get('/en/plans')
        ->assertOk()
        ->assertSee('<html lang="en">', false)
        ->assertSee('Plans & pricing')
        ->assertSee('¥580')
        ->assertSee('month')
        ->assertSee('class="credit-balance-unit"> consultation</span>', false)
        ->assertDontSee('料金・プラン');

    expect($user->fresh()->preferred_locale)->toBe('en');
});

test('English legal pages render reference translations', function () {
    $this->get(route('legal.terms', ['locale' => 'en']))
        ->assertOk()
        ->assertSee('Article 1 (Application)')
        ->assertSee('The Japanese version is authoritative');

    $this->get(route('legal.privacy', ['locale' => 'en']))
        ->assertOk()
        ->assertSee('Information We Collect');

    $this->get(route('legal.refund-credit-policy', ['locale' => 'en']))
        ->assertOk()
        ->assertSee('Canceling a Paid Plan');

    $this->get(route('legal.commercial-transactions', ['locale' => 'en']))
        ->assertOk()
        ->assertSee('Prices and Additional Charges');
});

test('the language links point to the localized home pages', function () {
    $this->get('/ja')
        ->assertOk()
        ->assertSee('href="'.route('home', ['locale' => 'en']).'" hreflang="en"', false)
        ->assertSee('>English</a>', false)
        ->assertDontSee('href="'.route('home', ['locale' => 'ja']).'" hreflang="ja"', false)
        ->assertDontSee('>日本語</a>', false);

    $this->get('/en')
        ->assertOk()
        ->assertSee('href="'.route('home', ['locale' => 'ja']).'" hreflang="ja"', false)
        ->assertSee('>日本語</a>', false)
        ->assertDontSee('href="'.route('home', ['locale' => 'en']).'" hreflang="en"', false)
        ->assertDontSee('>English</a>', false);
});

test('unsupported locale prefixes are not found', function () {
    $this->get('/fr')->assertNotFound();
});
