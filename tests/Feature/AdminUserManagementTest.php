<?php

use App\Http\Middleware\EnsureActiveUser;
use App\Http\Middleware\EnsureAdmin;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

test('guests must log in before opening user management', function () {
    $this->get(route('admin.users.index'))->assertRedirect(route('login'));
});

test('non-admin users cannot access user management', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('admin.users.index'))
        ->assertForbidden();
});

test('the controller rejects a non-admin even when the admin middleware is bypassed', function () {
    $user = User::factory()->create();

    $this->withoutMiddleware(EnsureAdmin::class)
        ->actingAs($user)
        ->get(route('admin.users.index'))
        ->assertForbidden();
});

test('admins can search users and open their details', function () {
    $admin = User::factory()->admin()->create();
    $match = User::factory()->create([
        'name' => 'Sakura Reader',
        'email' => 'sakura@example.com',
    ]);
    User::factory()->create([
        'name' => 'Other Reader',
        'email' => 'other@example.com',
    ]);

    $this->actingAs($admin)
        ->get(route('admin.users.index', ['search' => 'sakura']))
        ->assertOk()
        ->assertSee('sakura@example.com')
        ->assertDontSee('other@example.com');

    $this->get(route('admin.users.show', $match))->assertOk()->assertSee($match->email);
    $this->get(route('admin.users.edit', $match))
        ->assertOk()
        ->assertDontSee('type="password"');
});

test('admins can edit user details and role without changing the password', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create([
        'password' => Hash::make('Original-password-2026'),
    ]);

    $this->actingAs($admin)
        ->put(route('admin.users.update', $user), [
            'name' => 'Updated Reader',
            'email' => 'updated@example.com',
            'role' => User::ROLE_ADMIN,
        ])
        ->assertRedirect(route('admin.users.show', $user));

    $user->refresh();
    expect($user->name)->toBe('Updated Reader')
        ->and($user->role)->toBe(User::ROLE_ADMIN)
        ->and(Hash::check('Original-password-2026', $user->password))->toBeTrue();
});

test('admins can suspend another user and suspended users cannot log in', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create([
        'email' => 'suspended@example.com',
        'password' => Hash::make('A-strong-password-2026'),
    ]);

    $this->actingAs($admin)
        ->patch(route('admin.users.suspend', $user))
        ->assertRedirect();

    $this->assertDatabaseHas('users', [
        'id' => $user->id,
        'is_active' => false,
    ]);

    Auth::logout();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'A-strong-password-2026',
    ])->assertSessionHasErrors('email');
});

test('admins cannot suspend themselves', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->patch(route('admin.users.suspend', $admin))
        ->assertSessionHasErrors('user');

    $this->assertDatabaseHas('users', [
        'id' => $admin->id,
        'is_active' => true,
    ]);
});

test('the last active admin cannot be suspended', function () {
    $operator = User::factory()->admin()->create();
    $lastAdmin = User::factory()->admin()->create();
    $operator->forceFill(['is_active' => false])->save();

    $this->withoutMiddleware(EnsureActiveUser::class)
        ->actingAs($operator)
        ->patch(route('admin.users.suspend', $lastAdmin))
        ->assertSessionHasErrors('user');

    $this->assertDatabaseHas('users', [
        'id' => $lastAdmin->id,
        'is_active' => true,
    ]);
});

test('the last active admin cannot be demoted', function () {
    $operator = User::factory()->admin()->create();
    $lastAdmin = User::factory()->admin()->create();
    $operator->forceFill(['is_active' => false])->save();

    $this->withoutMiddleware(EnsureActiveUser::class)
        ->actingAs($operator)
        ->put(route('admin.users.update', $lastAdmin), [
            'name' => $lastAdmin->name,
            'email' => $lastAdmin->email,
            'role' => User::ROLE_USER,
        ])
        ->assertSessionHasErrors('role');

    $this->assertDatabaseHas('users', [
        'id' => $lastAdmin->id,
        'role' => User::ROLE_ADMIN,
        'is_active' => true,
    ]);
});

test('admins can send a password reset email without viewing the password', function () {
    Notification::fake();
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();

    $this->actingAs($admin)
        ->post(route('admin.users.password-reset', $user))
        ->assertRedirect();

    Notification::assertSentTo($user, ResetPassword::class);
});
