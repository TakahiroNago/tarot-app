<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Answers as if the shop's selling pages did not exist while it is closed. It runs before
 * authentication and route model binding, so a closed shop neither reveals them nor queries its
 * tables. Pages that show a customer's existing order are deliberately not behind this.
 */
class EnsureEcommerceEnabled
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(config('ecommerce.enabled'), 404);

        return $next($request);
    }
}
