<?php

use App\Models\User;
use App\Notifications\AccountActivityNotification;
use App\Notifications\AccountEmailChangeVerification;
use App\Services\StripeBillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Stripe\HttpClient\CurlClient;

uses(RefreshDatabase::class);

test('guests cannot access account settings', function () {
    $this->get(route('account.settings.edit'))
        ->assertRedirect(route('login'));

    $this->put(route('account.settings.update'))
        ->assertRedirect(route('login'));
});

test('users can update their account name independently', function () {
    Notification::fake();
    $user = User::factory()->create([
        'email_verified_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('account.settings.edit'))
        ->assertOk()
        ->assertSee('アカウントを削除する')
        ->assertSee(route('account.settings.destroy'))
        ->assertSee('名前を変更')
        ->assertSee('メールアドレスを変更')
        ->assertSee(route('account.settings.email.update'));

    $this->actingAs($user)
        ->put(route('account.settings.update'), [
            'name' => 'Updated Name',
        ])
        ->assertRedirect(route('account.settings.edit'))
        ->assertSessionHas('status');

    Notification::assertSentTo($user, AccountActivityNotification::class, function (
        AccountActivityNotification $notification,
    ): bool {
        return $notification->activity === 'お名前の変更';
    });

    $this->actingAs($user)
        ->put(route('account.settings.update'), [
            'name' => 'Updated Name',
        ])
        ->assertRedirect(route('account.settings.edit'));

    expect(Notification::sent($user, AccountActivityNotification::class))->toHaveCount(1);

    expect($user->fresh()->name)->toBe('Updated Name')
        ->and($user->fresh()->email)->toBe($user->email)
        ->and($user->fresh()->email_verified_at)->not->toBeNull();
});

test('email changes are not saved until the new address is confirmed', function () {
    Notification::fake();
    $user = User::factory()->create([
        'email_verified_at' => now(),
    ]);
    $newEmail = 'updated@example.com';

    $this->actingAs($user)
        ->put(route('account.settings.email.update'), [
            'email' => $newEmail,
        ])
        ->assertRedirect(route('account.settings.edit'))
        ->assertSessionHas('status');

    expect($user->fresh()->email)->toBe($user->email)
        ->and($user->fresh()->name)->toBe($user->name)
        ->and($user->fresh()->email_verified_at)->not->toBeNull();

    Notification::assertSentOnDemand(AccountEmailChangeVerification::class, function ($notification, array $channels, $notifiable) use ($newEmail): bool {
        parse_str((string) parse_url($notification->verificationUrl, PHP_URL_QUERY), $query);

        return $channels === ['mail']
            && $notifiable->routeNotificationFor('mail') === $newEmail
            && ($query['email'] ?? null) === $newEmail
            && URL::hasValidSignature(Request::create($notification->verificationUrl));
    });
});

test('users can confirm their new email address using the signed link', function () {
    Notification::fake();
    $user = User::factory()->create([
        'email_verified_at' => now(),
    ]);
    $newEmail = 'confirmed@example.com';
    $verificationUrl = URL::temporarySignedRoute(
        'account.email.verify',
        now()->addMinutes(60),
        ['user' => $user->id, 'email' => $newEmail],
    );

    $this->actingAs($user)
        ->get($verificationUrl)
        ->assertRedirect(route('account.settings.edit'))
        ->assertSessionHas('status', 'メールアドレスを変更しました。');

    expect($user->fresh()->email)->toBe($newEmail)
        ->and($user->fresh()->email_verified_at)->not->toBeNull();

    Notification::assertSentOnDemand(AccountActivityNotification::class, function (
        AccountActivityNotification $notification,
        array $channels,
        $notifiable,
    ) use ($user): bool {
        return $notification->activity === 'メールアドレスの変更'
            && $channels === ['mail']
            && $notifiable->routeNotificationFor('mail') === $user->email;
    });
});

test('email confirmation does not save an address that has since been registered', function () {
    $user = User::factory()->create([
        'email_verified_at' => now(),
    ]);
    $existingUser = User::factory()->create(['email' => 'taken@example.com']);
    $verificationUrl = URL::temporarySignedRoute(
        'account.email.verify',
        now()->addMinutes(60),
        ['user' => $user->id, 'email' => $existingUser->email],
    );

    $this->actingAs($user)
        ->get($verificationUrl)
        ->assertSessionHasErrors('email');

    expect($user->fresh()->email)->not->toBe($existingUser->email)
        ->and($user->fresh()->email_verified_at)->not->toBeNull();
});

test('users can manage Stripe billing from account settings', function () {
    $customer = User::factory()->create([
        'stripe_customer_id' => 'cus_settings',
    ]);

    $this->actingAs($customer)
        ->get(route('account.settings.edit'))
        ->assertOk()
        ->assertSee('現在のプラン：無料')
        ->assertSee('お支払い情報を管理')
        ->assertSee(route('billing.portal'));

    $subscriber = User::factory()->create([
        'plan' => User::PLAN_LITE,
        'stripe_customer_id' => 'cus_subscriber_settings',
        'stripe_subscription_id' => 'sub_settings',
    ]);

    $this->actingAs($subscriber)
        ->get(route('account.settings.edit'))
        ->assertOk()
        ->assertSee('現在のプラン：ライト')
        ->assertSee('契約・お支払いを管理')
        ->assertSee(route('billing.portal'));
});

test('account settings show the current free plan without billing actions for users without a Stripe customer', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('account.settings.edit'))
        ->assertOk()
        ->assertSee('現在のプラン：無料')
        ->assertDontSee('お支払い情報を管理')
        ->assertDontSee('契約・お支払いを管理');
});

test('account settings show the current paid plan and scheduled cancellation date', function () {
    $periodEnd = now()->addDays(12);
    $user = User::factory()->create([
        'plan' => User::PLAN_PREMIUM,
        'stripe_customer_id' => 'cus_cancel_scheduled',
        'stripe_subscription_id' => 'sub_cancel_scheduled',
        'stripe_cancel_at_period_end' => true,
        'stripe_current_period_end' => $periodEnd,
    ]);

    $this->actingAs($user)
        ->get(route('account.settings.edit'))
        ->assertOk()
        ->assertSee('現在のプラン：プレミアム')
        ->assertSee($periodEnd->format('Y年n月j日').'に終了予定')
        ->assertSee('契約・お支払いを管理');
});

test('account settings offer immediate upgrades and end-of-period downgrades', function () {
    $liteMember = User::factory()->create([
        'plan' => User::PLAN_LITE,
        'stripe_customer_id' => 'cus_upgrade',
        'stripe_subscription_id' => 'sub_upgrade',
    ]);

    $this->actingAs($liteMember)
        ->get(route('account.settings.edit'))
        ->assertOk()
        ->assertSee('プレミアムへ変更')
        ->assertSee('日割り計算で今回の請求に充当されます。');

    $premiumMember = User::factory()->create([
        'plan' => User::PLAN_PREMIUM,
        'stripe_customer_id' => 'cus_downgrade',
        'stripe_subscription_id' => 'sub_downgrade',
    ]);

    $this->actingAs($premiumMember)
        ->get(route('account.settings.edit'))
        ->assertOk()
        ->assertSee('ライトへ変更')
        ->assertSee('現在のプレミアム期間終了後に適用されます。');
});

test('account settings show and allow cancellation of a scheduled downgrade', function () {
    $user = User::factory()->create([
        'plan' => User::PLAN_PREMIUM,
        'stripe_customer_id' => 'cus_scheduled',
        'stripe_subscription_id' => 'sub_scheduled',
        'stripe_scheduled_plan' => User::PLAN_LITE,
        'stripe_subscription_schedule_id' => 'sched_scheduled',
        'stripe_current_period_end' => now()->addDays(8),
    ]);

    $this->actingAs($user)
        ->get(route('account.settings.edit'))
        ->assertOk()
        ->assertSee('ライトプランへ切り替わる予定です。')
        ->assertSee('予約を取り消す')
        ->assertSee(route('billing.plan.cancel'));
});

test('users are told Stripe billing changes may take time to appear after returning', function () {
    $user = User::factory()->create([
        'stripe_customer_id' => 'cus_returned',
    ]);

    $this->actingAs($user)
        ->get(route('account.settings.edit', ['billing' => 'returned']))
        ->assertOk()
        ->assertSee('Stripeの管理画面から戻りました。')
        ->assertSee('契約内容の変更はWebhookで反映されるため');
});

test('billing portal returns users to account settings', function () {
    config(['services.stripe.secret' => 'sk_test']);
    $httpClient = new class implements ClientInterface
    {
        public array $requests = [];

        public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
        {
            $this->requests[] = compact('method', 'absUrl', 'params');

            return [
                json_encode([
                    'id' => 'bps_test',
                    'object' => 'billing_portal.session',
                    'url' => 'https://billing.stripe.com/session/test',
                ], JSON_THROW_ON_ERROR),
                200,
                ['Content-Type' => 'application/json'],
            ];
        }
    };
    ApiRequestor::setHttpClient($httpClient);

    try {
        $user = User::factory()->create([
            'stripe_customer_id' => 'cus_return_destination',
        ]);

        $this->actingAs($user)
            ->post(route('billing.portal'))
            ->assertRedirect('https://billing.stripe.com/session/test');

        expect($httpClient->requests)->toHaveCount(1)
            ->and($httpClient->requests[0]['params']['return_url'])
            ->toBe(route('account.settings.edit', ['billing' => 'returned']));
    } finally {
        ApiRequestor::setHttpClient(new CurlClient);
    }
});

test('users can permanently delete their account and associated records after confirming their password', function () {
    Notification::fake();
    $user = User::factory()->create([
        'password' => Hash::make('A-strong-password-2026'),
    ]);
    $reading = $user->zenConsultations()->create([
        'concern' => 'Will I succeed?',
        'response' => 'A hopeful reading.',
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
    $this->assertDatabaseMissing('zen_consultations', ['id' => $reading->id]);
    $this->assertDatabaseMissing('sessions', ['id' => 'account-session']);
    $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
    Notification::assertSentOnDemand(AccountActivityNotification::class, function (
        AccountActivityNotification $notification,
        array $channels,
        $notifiable,
    ) use ($user): bool {
        return $notification->activity === 'アカウントの削除'
            && $channels === ['mail']
            && $notifiable->routeNotificationFor('mail') === $user->email;
    });
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
