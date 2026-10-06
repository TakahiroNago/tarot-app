<?php

use App\Models\User;
use App\Services\StripeBillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

test('guests cannot access account settings', function () {
    $this->get(route('account.settings.edit'))
        ->assertRedirect(route('login'));

    $this->put(route('account.settings.update'))
        ->assertRedirect(route('login'));
});

test('users can update their account name and email', function () {
    $user = User::factory()->create([
        'email_verified_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('account.settings.edit'))
        ->assertOk()
        ->assertSee('アカウントを削除する')
        ->assertSee(route('account.settings.destroy'));

    $this->actingAs($user)
        ->put(route('account.settings.update'), [
            'name' => 'Updated Name',
            'email' => 'updated@example.com',
        ])
        ->assertRedirect(route('account.settings.edit'))
        ->assertSessionHas('status');

    expect($user->fresh()->name)->toBe('Updated Name')
        ->and($user->fresh()->email)->toBe('updated@example.com')
        ->and($user->fresh()->email_verified_at)->toBeNull();
});

test('users can permanently delete their account and associated records after confirming their password', function () {
    $user = User::factory()->create([
        'password' => Hash::make('A-strong-password-2026'),
    ]);
    $reading = $user->tarotReadings()->create([
        'question' => 'Will I succeed?',
        'card' => 'The Star',
        'interpretation' => 'A hopeful reading.',
    ]);
    DB::table('sessions')->insert([
        'id' => 'account-session',
        'user_id' => $user->id,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'test',
        'payload' => 'payload',
        'last_activity' => now()->timestamp,
    ]);
    DB::table('password_reset_tokens')->insert([
        'email' => $user->email,
        'token' => 'reset-token',
        'created_at' => now(),
    ]);

    $this->actingAs($user)
        ->delete(route('account.settings.destroy'), [
            'password' => 'A-strong-password-2026',
        ])
        ->assertRedirect(route('login'))
        ->assertSessionHas('status');

    $this->assertGuest();
    $this->assertDatabaseMissing('users', ['id' => $user->id]);
    $this->assertDatabaseMissing('tarot_readings', ['id' => $reading->id]);
    $this->assertDatabaseMissing('sessions', ['id' => 'account-session']);
    $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
});

test('an incorrect password prevents account deletion', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->delete(route('account.settings.destroy'), [
            'password' => 'incorrect-password',
        ])
        ->assertSessionHasErrors('password');

    $this->assertDatabaseHas('users', ['id' => $user->id]);
    $this->assertAuthenticatedAs($user);
});

test('the last active administrator cannot delete their account', function () {
    $administrator = User::factory()->admin()->create();

    $this->actingAs($administrator)
        ->delete(route('account.settings.destroy'), [
            'password' => 'password',
        ])
        ->assertSessionHasErrors('account');

    $this->assertDatabaseHas('users', ['id' => $administrator->id]);
});

test('deleting an account cancels its stripe billing before removing its data', function () {
    $user = User::factory()->create([
        'stripe_customer_id' => 'cus_account_deletion',
    ]);
    $billing = Mockery::mock(StripeBillingService::class);
    $billing->shouldReceive('cancelForAccountDeletion')->once()->with(Mockery::on(
        fn (User $account): bool => $account->is($user),
    ));
    $this->app->instance(StripeBillingService::class, $billing);

    $this->actingAs($user)
        ->delete(route('account.settings.destroy'), [
            'password' => 'password',
        ])
        ->assertRedirect(route('login'));

    $this->assertDatabaseMissing('users', ['id' => $user->id]);
});
