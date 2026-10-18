<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Notifications\AccountEmailChangeVerification;
use App\Services\StripeBillingService;
use Illuminate\Auth\Events\Verified;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AccountSettingsController extends Controller
{
    public function edit(Request $request): View
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return view('account.settings', compact('user'));
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $user->fill($validated)->save();

        return to_route('account.settings.edit')->with('status', 'お名前を更新しました。');
    }

    public function requestEmailChange(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $validated = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
        ]);

        if ($user->email === $validated['email']) {
            return to_route('account.settings.edit')->with('status', 'このメールアドレスは現在登録されています。');
        }

        $verificationUrl = URL::temporarySignedRoute(
            'account.email.verify',
            now()->addMinutes(60),
            ['user' => $user->id, 'email' => $validated['email']],
        );

        Notification::route('mail', $validated['email'])
            ->notify(new AccountEmailChangeVerification($verificationUrl));

        return to_route('account.settings.edit')->with('status', '確認用メールを送信しました。メール内のリンクを開くと変更が完了します。');
    }

    public function confirmEmailChange(Request $request, User $user): RedirectResponse
    {
        $authenticatedUser = $request->user();
        abort_unless($authenticatedUser instanceof User && $authenticatedUser->is($user), 403);

        $validated = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
        ]);

        $user->forceFill([
            'email' => $validated['email'],
            'email_verified_at' => now(),
        ])->save();

        event(new Verified($user));

        return to_route('account.settings.edit')->with('status', 'メールアドレスを変更しました。');
    }

    public function destroy(Request $request, StripeBillingService $billingService): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();
        abort_unless($user instanceof User, 403);

        DB::transaction(function () use ($billingService, $user): void {
            $activeAdmins = User::query()
                ->where('role', User::ROLE_ADMIN)
                ->where('is_active', true)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $account = User::query()->lockForUpdate()->findOrFail($user->id);

            if ($account->is_active && $account->isAdmin() && $activeAdmins->count() <= 1) {
                throw ValidationException::withMessages([
                    'account' => '最後の有効なAdminアカウントは削除できません。',
                ]);
            }

            $billingService->cancelForAccountDeletion($account);

            DB::table(config('session.table'))
                ->where('user_id', $account->id)
                ->delete();
            DB::table('password_reset_tokens')
                ->where('email', $account->email)
                ->delete();

            $account->delete();
        });

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return to_route('login')->with('status', 'アカウントを削除しました。ご利用ありがとうございました。');
    }
}
