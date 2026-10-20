<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\AccountActivityNotification;
use App\Services\SessionConsultationPersister;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

class GoogleAuthController extends Controller
{
    public function redirect(): RedirectResponse
    {
        return Socialite::driver('google')->redirect();
    }

    public function callback(Request $request, SessionConsultationPersister $sessionConsultationPersister): RedirectResponse
    {
        $googleUser = Socialite::driver('google')->user();
        $email = $googleUser->getEmail();
        $googleId = $googleUser->getId();
        $googleProfile = $googleUser->getRaw();

        if (
            ! is_string($googleId)
            || $googleId === ''
            || ! is_string($email)
            || ! filter_var($email, FILTER_VALIDATE_EMAIL)
            || ($googleProfile['email_verified'] ?? false) !== true
        ) {
            return redirect()->route('login')->withErrors([
                'email' => 'Googleアカウントのメールアドレスを確認できませんでした。',
            ]);
        }

        $user = User::query()->where('google_id', $googleId)->first()
            ?? User::query()->where('email', $email)->first();

        if ($user?->is_active === false) {
            return redirect()->route('login')->withErrors([
                'email' => 'このアカウントは現在ご利用いただけません。',
            ]);
        }

        if ($user !== null && $user->google_id !== null && $user->google_id !== $googleId) {
            return redirect()->route('login')->withErrors([
                'email' => 'このメールアドレスは別のGoogleアカウントに連携されています。',
            ]);
        }

        if ($user === null) {
            $user = User::create([
                'name' => $googleUser->getName() ?: Str::before($email, '@'),
                'email' => $email,
                'password' => Str::random(64),
                'role' => User::ROLE_USER,
                'is_active' => true,
                'google_id' => $googleId,
            ]);
            $user->forceFill(['email_verified_at' => now()])->save();

            event(new Registered($user));
        } elseif ($user->google_id === null) {
            $user->google_id = $googleId;
            $user->save();
            $user->notify(new AccountActivityNotification('Googleアカウントの連携', $request->ip()));
        }

        Auth::login($user);
        $request->session()->regenerate();
        $user->notify(new AccountActivityNotification('Googleログイン', $request->ip()));
        $sessionConsultationPersister->persist($user, $request);

        return redirect()->intended(route('home'));
    }
}
