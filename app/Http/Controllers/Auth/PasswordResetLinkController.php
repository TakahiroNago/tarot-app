<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\View\View;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

class PasswordResetLinkController extends Controller
{
    public function create(): View
    {
        return view('auth.forgot-password');
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        try {
            Password::sendResetLink($request->only('email'));
        } catch (TransportExceptionInterface $exception) {
            report($exception);

            return back()->withInput()->withErrors([
                'email' => 'メールを送信できませんでした。時間をおいて再度お試しください。',
            ]);
        }

        return back()->with('status', '登録されている場合、パスワード再設定メールを送信しました。');
    }
}
