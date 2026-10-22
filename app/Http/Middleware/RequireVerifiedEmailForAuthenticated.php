<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireVerifiedEmailForAuthenticated
{
    /**
     * Redirect logged-in users with unverified email to the verification notice.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof MustVerifyEmail
            && ! $user->hasVerifiedEmail()
            && ! $request->routeIs('verification.*', 'logout', 'stripe.webhook', 'legal.*', 'contact.*')
        ) {
            return $request->expectsJson()
                ? abort(403, 'Your email address is not verified.')
                : redirect()->route('verification.notice');
        }

        if ($user instanceof User
            && $user->hasVerifiedEmail()
            && ! $user->security_notifications_configured
            && ! $request->routeIs(
                'account.security-notifications.setup',
                'account.security-notifications.setup.update',
                'logout',
                'stripe.webhook',
                'legal.*',
                'contact.*',
            )
        ) {
            return $request->expectsJson()
                ? abort(403, 'Security notification settings are not configured.')
                : redirect()->route('account.security-notifications.setup');
        }

        return $next($request);
    }
}
