<?php

namespace App\Providers;

use App\Models\User;
use App\Services\ReadingCreditService;
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
        View::composer('partials.site-header', function (ViewInstance $view): void {
            $user = request()->user();

            if ($user instanceof User) {
                $view->with('creditSummary', app(ReadingCreditService::class)->balance($user));
            }
        });
    }
}
