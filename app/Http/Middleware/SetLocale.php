<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->route('locale');
        abort_unless(in_array($locale, ['en', 'ja'], true), 404);

        App::setLocale($locale);
        URL::defaults(['locale' => $locale]);
        $request->session()->put('locale', $locale);

        $route = $request->route();

        if ($route instanceof Route) {
            $route->forgetParameter('locale');
        }

        $user = $request->user();

        if ($user instanceof User && $user->preferred_locale !== $locale) {
            $user->update(['preferred_locale' => $locale]);
        }

        return $next($request);
    }
}
