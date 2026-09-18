<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Recovers vendor payment events whose webhook never arrived. Only runs while
// the scheduler does: `php artisan schedule:run` every minute.
Schedule::command('vendors:sync-events')->everyFifteenMinutes()->withoutOverlapping();

// Starts billing for partners who waited on commissions and have reached the
// threshold, and keeps everyone else's hold clear of Stripe's two-year limit.
Schedule::command('billing:commission-holds')->dailyAt('06:00')->withoutOverlapping();
