<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\SessionReadingPersister;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    public function create(Request $request): View
    {
        if ($request->query('redirect') === 'plans') {
            $request->session()->put('url.intended', route('plans'));
        }

        return view('auth.login');
    }

    public function store(Request $request, SessionReadingPersister $sessionReadingPersister): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt([...$credentials, 'is_active' => true], $request->boolean('remember'))) {
            return back()->withErrors([
                'email' => 'メールアドレスまたはパスワードを確認してください。',
            ])->onlyInput('email');
        }

        $request->session()->regenerate();
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        if (! $user->hasVerifiedEmail()) {
            return redirect()->route('verification.notice');
        }

        $sessionReadingPersister->persist($user, $request);

        return redirect()->intended(route('home'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }
}
