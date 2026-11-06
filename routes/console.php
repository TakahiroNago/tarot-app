<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('ecommerce:expire-reservations')->everyMinute()->withoutOverlapping(10);
Schedule::command('ecommerce:audit-inventory')->dailyAt('03:00')->withoutOverlapping(60);
Schedule::command('ecommerce:prune-carts')->dailyAt('03:30')->withoutOverlapping(60);
