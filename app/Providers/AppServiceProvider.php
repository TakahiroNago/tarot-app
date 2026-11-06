<?php

namespace App\Providers;

use App\Models\User;
use App\Services\ReadingCreditService;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\View as ViewInstance;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Links in password reset and verification mail are built during a web request, and must
        // not depend on what that request claims the site is called.
        if ($this->app->isProduction()) {
            URL::forceRootUrl(config('app.url'));
            URL::forceScheme('https');
        }

        View::composer('partials.site-header', function (ViewInstance $view): void {
            $user = request()->user();

            if ($user instanceof User) {
                $view->with('creditSummary', app(ReadingCreditService::class)->balance($user));
            }
        });
    }
}
