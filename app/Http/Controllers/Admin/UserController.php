<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\AccountActivityNotification;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    public function index(Request $request): View
    {
        $this->administrator($request);

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
        ]);
        $search = trim($validated['search'] ?? '');

        $users = User::query()
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($query) use ($search): void {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->orderByDesc('created_at')
            ->paginate(15)
            ->withQueryString();

        return view('admin.users.index', compact('users', 'search'));
    }

    public function show(Request $request, User $user): View
    {
        $this->administrator($request);

        return view('admin.users.show', compact('user'));
    }

    public function edit(Request $request, User $user): View
    {
        $this->administrator($request);

        return view('admin.users.edit', compact('user'));
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $administrator = $this->administrator($request);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'role' => ['required', Rule::in([User::ROLE_USER, User::ROLE_ADMIN])],
        ]);

        $notificationEmail = null;
        $accountWasChanged = false;
        $ipAddress = $request->ip();

        DB::transaction(function () use (
            $administrator,
            $user,
            $validated,
            &$notificationEmail,
            &$accountWasChanged,
        ): void {
            $activeAdmins = User::query()
                ->where('role', User::ROLE_ADMIN)
                ->where('is_active', true)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $managedUser = User::query()->lockForUpdate()->findOrFail($user->id);

            if ($managedUser->is($administrator) && $validated['role'] !== $managedUser->role) {
                throw ValidationException::withMessages([
                    'role' => __('自分自身の権限は変更できません。'),
                ]);
            }

            if (
                $managedUser->is_active
                && $managedUser->isAdmin()
                && $validated['role'] !== User::ROLE_ADMIN
                && $activeAdmins->count() <= 1
            ) {
                throw ValidationException::withMessages([
                    'role' => __('最後の有効なAdminの権限は変更できません。'),
                ]);
            }

            $accountWasChanged = $managedUser->name !== $validated['name']
                || $managedUser->email !== $validated['email']
                || $managedUser->role !== $validated['role'];
            if ($accountWasChanged) {
                $notificationEmail = $managedUser->email;
            }

            if ($managedUser->email !== $validated['email']) {
                $managedUser->email_verified_at = null;
            }

            $managedUser->fill([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'role' => $validated['role'],
            ])->save();
        });

        if ($accountWasChanged && is_string($notificationEmail)) {
            Notification::route('mail', $notificationEmail)
                ->notify((new AccountActivityNotification('管理者によるアカウント情報の変更', $ipAddress))
                    ->locale($user->preferredLocale()));
        }

        return redirect()->route('admin.users.show', $user)->with('status', __('ユーザー情報を更新しました。'));
    }

    public function suspend(Request $request, User $user): RedirectResponse
    {
        $administrator = $this->administrator($request);

        if ($user->is($administrator)) {
            return back()->withErrors(['user' => __('自分自身を停止することはできません。')]);
        }

        $notificationEmail = null;
        $accountWasSuspended = false;
        $ipAddress = $request->ip();
        DB::transaction(function () use ($user, &$notificationEmail, &$accountWasSuspended): void {
            $activeAdmins = User::query()
                ->where('role', User::ROLE_ADMIN)
                ->where('is_active', true)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $managedUser = User::query()->lockForUpdate()->findOrFail($user->id);

            if (
                $managedUser->is_active
                && $managedUser->isAdmin()
                && $activeAdmins->count() <= 1
            ) {
                throw ValidationException::withMessages([
                    'user' => __('最後の有効なAdminは停止できません。'),
                ]);
            }

            $accountWasSuspended = $managedUser->is_active;
            if ($accountWasSuspended) {
                $notificationEmail = $managedUser->email;
            }
            $managedUser->forceFill(['is_active' => false])->save();
        });

        if ($accountWasSuspended && is_string($notificationEmail)) {
            Notification::route('mail', $notificationEmail)
                ->notify((new AccountActivityNotification('アカウントの利用停止', $ipAddress))
                    ->locale($user->preferredLocale()));
        }

        return back()->with('status', __('ユーザーを利用停止にしました。'));
    }

    public function sendPasswordReset(Request $request, User $user): RedirectResponse
    {
        $this->administrator($request);
        Password::sendResetLink(['email' => $user->email]);

        return back()->with('status', __('パスワード再設定の案内をメールで送信しました。'));
    }

    private function administrator(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->isAdmin(), 403);

        return $user;
    }
}
