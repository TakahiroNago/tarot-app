<?php

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as GoogleUser;

uses(RefreshDatabase::class);

test('a visitor can register and is assigned the user role', function () {
    $this->post(route('register.store'), [
        'name' => 'New User',
        'email' => 'new-user@example.com',
        'password' => 'A-strong-password-2026',
        'password_confirmation' => 'A-strong-password-2026',
        'role' => User::ROLE_ADMIN,
        'is_active' => false,
    ])->assertRedirect(route('home'));

    $this->assertAuthenticated();
    $this->assertDatabaseHas('users', [
        'email' => 'new-user@example.com',
        'role' => User::ROLE_USER,
        'is_active' => true,
    ]);
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
    ])->assertRedirect(route('home'))
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
