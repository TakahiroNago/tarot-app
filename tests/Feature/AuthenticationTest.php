<?php

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as GoogleUser;

uses(RefreshDatabase::class);

test('a visitor can register and is assigned the user role', function () {
    Notification::fake();

    $this->post(route('register.store'), [
        'name' => 'New User',
        'email' => 'new-user@example.com',
        'password' => 'A-strong-password-2026',
        'password_confirmation' => 'A-strong-password-2026',
        'role' => User::ROLE_ADMIN,
        'is_active' => false,
    ])->assertRedirect(route('verification.notice'));

    $this->assertAuthenticated();
    $this->assertDatabaseHas('users', [
        'email' => 'new-user@example.com',
        'role' => User::ROLE_USER,
        'is_active' => true,
        'email_verified_at' => null,
    ]);
    Notification::assertSentTo(User::where('email', 'new-user@example.com')->sole(), VerifyEmail::class);
});

test('registration saves the current session readings to the new account', function () {
    $this->withSession([
        'tarot_readings' => [[
            'question' => 'Before registration?',
            'card' => 'The Fool',
            'interpretation' => 'A reading before registration.',
            'category' => '将来',
            'summary' => '登録前のリーディングの要約です。',
        ]],
    ])->post(route('register.store'), [
        'name' => 'New User',
        'email' => 'new-reader@example.com',
        'password' => 'A-strong-password-2026',
        'password_confirmation' => 'A-strong-password-2026',
    ])->assertRedirect(route('verification.notice'))
        ->assertSessionMissing('tarot_readings');

    $user = User::where('email', 'new-reader@example.com')->sole();
    $this->assertDatabaseHas('tarot_readings', [
        'user_id' => $user->id,
        'question' => 'Before registration?',
        'card' => 'The Fool',
        'interpretation' => 'A reading before registration.',
        'category' => '将来',
        'summary' => '登録前のリーディングの要約です。',
    ]);
});

test('the welcome page shows navigation for guests and signed-in admins', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertSee(route('login'))
        ->assertSee(route('register'))
        ->assertDontSee(route('readings.index'))
        ->assertDontSee(route('admin.users.index'));

    $this->withSession([
        'tarot_readings' => [[
            'question' => 'Guest question?',
            'card' => 'The Star',
            'interpretation' => 'A session answer.',
        ]],
    ])->get(route('home'))
        ->assertOk()
        ->assertSee(route('readings.index'))
        ->assertSee('占い履歴');

    $admin = User::factory()->admin()->create();
    $this->actingAs($admin)
        ->get(route('home'))
        ->assertOk()
        ->assertSee(route('admin.users.index'))
        ->assertSee(route('plans', ['account_menu' => 'open']))
        ->assertSee($admin->name)
        ->assertSee($admin->email)
        ->assertSee(route('readings.index'))
        ->assertSee(route('logout'))
        ->assertDontSee(route('register'));
});

test('the account icon opens the plan page with the account popover visible', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('plans', ['account_menu' => 'open']))
        ->assertOk()
        ->assertSee('class="account-menu is-open"', false)
        ->assertSee($user->name)
        ->assertSee($user->email)
        ->assertSee('占い履歴')
        ->assertSee('ログアウト')
        ->assertSee('料金・プラン');
});

test('the header shows the total credit balance on other authenticated pages', function () {
    $user = User::factory()->create();
    $user->forceFill([
        'daily_credits' => 2,
        'daily_credits_refreshed_at' => now()->toDateString(),
        'purchased_credits' => 7,
    ])->save();

    foreach ([route('home'), route('plans'), route('readings.index')] as $url) {
        $this->actingAs($user)
            ->get($url)
            ->assertOk()
            ->assertSee('残り <strong>9</strong> 回', false)
            ->assertDontSee('料金・プラン</a>', false);
    }
});

test('suspension ends an existing authenticated session on the next protected request', function () {
    $user = User::factory()->inactive()->create();

    $this->actingAs($user)
        ->post(route('logout'))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

test('a registered user can log in', function () {
    $user = User::factory()->create([
        'email' => 'reader@example.com',
        'password' => Hash::make('A-strong-password-2026'),
    ]);

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'A-strong-password-2026',
    ])->assertRedirect(route('home'));

    $this->assertAuthenticatedAs($user);
});

test('a guest who starts login from the plans page returns to the plans page', function () {
    $user = User::factory()->create([
        'email' => 'plans-reader@example.com',
        'password' => Hash::make('A-strong-password-2026'),
    ]);

    $this->get(route('plans'))
        ->assertOk()
        ->assertSee(route('login', ['redirect' => 'plans']));

    $this->get(route('login', ['redirect' => 'plans']))
        ->assertOk();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'A-strong-password-2026',
    ])->assertRedirect(route('plans'));

    $this->assertAuthenticatedAs($user);
});

test('an unverified user is sent to email verification after login', function () {
    $user = User::factory()->unverified()->create([
        'email' => 'unverified-reader@example.com',
        'password' => Hash::make('A-strong-password-2026'),
    ]);

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'A-strong-password-2026',
    ])->assertRedirect(route('verification.notice'));

    $this->assertAuthenticatedAs($user);
});

test('an unverified user can request a verification email and cannot access reading history', function () {
    Notification::fake();
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)
        ->get(route('verification.notice'))
        ->assertOk()
        ->assertSee('メールに記載された確認リンクを開いてください。');

    $this->actingAs($user)
        ->get(route('readings.index'))
        ->assertRedirect(route('verification.notice'));

    $this->actingAs($user)
        ->post(route('verification.send'))
        ->assertRedirect()
        ->assertSessionHas('status', 'verification-link-sent');

    Notification::assertSentTo($user, VerifyEmail::class);
});

test('a user can verify their email using the signed verification link', function () {
    $user = User::factory()->unverified()->create();
    $verificationUrl = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->id, 'hash' => sha1($user->email)],
    );

    $this->actingAs($user)
        ->get($verificationUrl)
        ->assertRedirect(route('home'))
        ->assertSessionHas('status', 'email-verified');

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});

test('a verification link for a different email address is rejected', function () {
    $user = User::factory()->unverified()->create();
    $verificationUrl = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->id, 'hash' => sha1('different@example.com')],
    );

    $this->actingAs($user)
        ->get($verificationUrl)
        ->assertForbidden();

    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

test('the email verification confirmation is marked to auto-dismiss on the welcome and tarot pages', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->withSession(['status' => 'email-verified'])
        ->get(route('home'))
        ->assertOk()
        ->assertSee('data-auto-dismiss', false)
        ->assertSee('メールアドレスを確認しました。');

    $this->actingAs($user)
        ->withSession(['status' => 'email-verified'])
        ->get(route('tarot'))
        ->assertOk()
        ->assertSee('data-auto-dismiss', false)
        ->assertSee('メールアドレスを確認しました。');
});

test('a guest can start Google login', function () {
    $provider = Mockery::mock(Provider::class);
    $provider->shouldReceive('redirect')->once()->andReturn(redirect()->away('https://accounts.google.com/o/oauth2/auth'));
    Socialite::shouldReceive('driver')->once()->with('google')->andReturn($provider);

    $this->get(route('login'))
        ->assertOk()
        ->assertSee(route('login.google'))
        ->assertSee('Googleでログイン');

    $this->get(route('login.google'))
        ->assertRedirect('https://accounts.google.com/o/oauth2/auth');
});

test('Google login creates and authenticates a verified Google account', function () {
    $provider = Mockery::mock(Provider::class);
    $provider->shouldReceive('user')->once()->andReturn(GoogleUser::fake([
        'id' => 'google-user-123',
        'name' => 'Google Reader',
        'email' => 'google-reader@example.com',
        'email_verified' => true,
    ]));
    Socialite::shouldReceive('driver')->once()->with('google')->andReturn($provider);

    $this->withSession([
        'tarot_readings' => [[
            'question' => 'Before Google login?',
            'card' => 'The Star',
            'interpretation' => 'A reading before Google login.',
        ]],
    ])->get(route('login.google.callback'))
        ->assertRedirect(route('home'))
        ->assertSessionMissing('tarot_readings');

    $user = User::where('email', 'google-reader@example.com')->sole();
    $this->assertAuthenticatedAs($user);
    $this->assertDatabaseHas('users', [
        'id' => $user->id,
        'google_id' => 'google-user-123',
        'role' => User::ROLE_USER,
        'is_active' => true,
    ]);
    expect($user->email_verified_at)->not->toBeNull();
    $this->assertDatabaseHas('tarot_readings', [
        'user_id' => $user->id,
        'question' => 'Before Google login?',
        'card' => 'The Star',
        'interpretation' => 'A reading before Google login.',
    ]);
});

test('Google login links a verified existing email to its account', function () {
    $user = User::factory()->create([
        'email' => 'existing-reader@example.com',
        'google_id' => null,
    ]);
    $provider = Mockery::mock(Provider::class);
    $provider->shouldReceive('user')->once()->andReturn(GoogleUser::fake([
        'id' => 'google-existing-123',
        'email' => $user->email,
        'email_verified' => true,
    ]));
    Socialite::shouldReceive('driver')->once()->with('google')->andReturn($provider);

    $this->get(route('login.google.callback'))
        ->assertRedirect(route('home'));

    $this->assertAuthenticatedAs($user);
    $this->assertDatabaseHas('users', [
        'id' => $user->id,
        'google_id' => 'google-existing-123',
    ]);
});

test('Google login rejects an unverified email', function () {
    $provider = Mockery::mock(Provider::class);
    $provider->shouldReceive('user')->once()->andReturn(GoogleUser::fake([
        'id' => 'google-unverified-123',
        'email' => 'unverified@example.com',
        'email_verified' => false,
    ]));
    Socialite::shouldReceive('driver')->once()->with('google')->andReturn($provider);

    $this->get(route('login.google.callback'))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('email');

    $this->assertGuest();
    $this->assertDatabaseMissing('users', ['email' => 'unverified@example.com']);
});

test('Google login does not authenticate a suspended account', function () {
    $user = User::factory()->inactive()->create([
        'email' => 'suspended-reader@example.com',
        'google_id' => null,
    ]);
    $provider = Mockery::mock(Provider::class);
    $provider->shouldReceive('user')->once()->andReturn(GoogleUser::fake([
        'id' => 'google-suspended-123',
        'email' => $user->email,
        'email_verified' => true,
    ]));
    Socialite::shouldReceive('driver')->once()->with('google')->andReturn($provider);

    $this->get(route('login.google.callback'))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('email');

    $this->assertGuest();
    $this->assertDatabaseHas('users', [
        'id' => $user->id,
        'google_id' => null,
    ]);
});

test('Google login does not replace a different linked Google account', function () {
    $user = User::factory()->create([
        'email' => 'linked-reader@example.com',
        'google_id' => 'google-original-123',
    ]);
    $provider = Mockery::mock(Provider::class);
    $provider->shouldReceive('user')->once()->andReturn(GoogleUser::fake([
        'id' => 'google-other-123',
        'email' => $user->email,
        'email_verified' => true,
    ]));
    Socialite::shouldReceive('driver')->once()->with('google')->andReturn($provider);

    $this->get(route('login.google.callback'))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('email');

    $this->assertGuest();
    $this->assertDatabaseHas('users', [
        'id' => $user->id,
        'google_id' => 'google-original-123',
    ]);
});

test('login saves the current session readings to the account', function () {
    $user = User::factory()->create([
        'email' => 'reader@example.com',
        'password' => Hash::make('A-strong-password-2026'),
    ]);

    $this->withSession([
        'tarot_readings' => [[
            'question' => 'Before login?',
            'card' => 'The Star',
            'interpretation' => 'A reading before login.',
        ]],
    ])->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'A-strong-password-2026',
    ])->assertRedirect(route('home'))
        ->assertSessionMissing('tarot_readings');

    $this->assertDatabaseHas('tarot_readings', [
        'user_id' => $user->id,
        'question' => 'Before login?',
        'card' => 'The Star',
        'interpretation' => 'A reading before login.',
    ]);
});

test('a user can reset their password from the email notification', function () {
    Notification::fake();
    $user = User::factory()->create();

    $this->post(route('password.email'), [
        'email' => $user->email,
    ])->assertRedirect();

    Notification::assertSentTo($user, ResetPassword::class);
    $notification = Notification::sent($user, ResetPassword::class)->first();

    $this->post(route('password.update'), [
        'token' => $notification->token,
        'email' => $user->email,
        'password' => 'A-new-strong-password-2026',
        'password_confirmation' => 'A-new-strong-password-2026',
    ])->assertRedirect(route('login'));

    expect(Hash::check('A-new-strong-password-2026', $user->fresh()->password))->toBeTrue();
});

test('password reset and email verification notifications are written in Japanese', function () {
    App::setLocale('ja');
    $user = User::factory()->create();
    $passwordResetMail = (new ResetPassword('reset-token'))->toMail($user);
    $verificationMail = (new VerifyEmail)->toMail($user);

    expect($user->preferredLocale())->toBe('ja')
        ->and($passwordResetMail->subject)->toBe(Lang::get('Reset your password'))
        ->and($passwordResetMail->introLines)->toContain(
            Lang::get('You are receiving this email because we received a password reset request for your account.'),
        )
        ->and($passwordResetMail->outroLines)->toContain(
            Lang::get('This password reset link will expire in :count minutes.', ['count' => 60]),
            Lang::get('If you did not request a password reset, no further action is required.'),
        )
        ->and($passwordResetMail->actionText)->toBe(Lang::get('Reset Password'))
        ->and($verificationMail->subject)->toBe(Lang::get('Verify your email address'))
        ->and($verificationMail->introLines)->toContain(
            Lang::get('Please click the button below to verify your email address.'),
        )
        ->and($verificationMail->outroLines)->toContain(
            Lang::get('If you did not create an account, no further action is required.'),
        )
        ->and($verificationMail->actionText)->toBe(Lang::get('Verify Email Address'))
        ->and(config('app.name'))->toBe("\u{6708}\u{8A60} TAROT JOURNAL")
        ->and((string) $passwordResetMail->render())->toContain(
            Lang::get('Hello!'),
            Lang::get('Regards,'),
            config('app.name'),
        )
        ->and((string) $verificationMail->render())->toContain(
            Lang::get('Hello!'),
            Lang::get('Regards,'),
            config('app.name'),
        );
});
