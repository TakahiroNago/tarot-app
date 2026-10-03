<?php

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

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
    ]);
});

test('the welcome page shows navigation for guests and signed-in admins', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertSee(route('login'))
        ->assertSee(route('register'))
        ->assertDontSee(route('admin.users.index'));

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('home'))
        ->assertOk()
        ->assertSee(route('admin.users.index'))
        ->assertSee(route('logout'))
        ->assertDontSee(route('register'));
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
