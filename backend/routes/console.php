<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Starts billing for partners who waited on commissions and have reached the
// threshold, and keeps everyone else's hold clear of Stripe's two-year limit.
Schedule::command('billing:commission-holds')->dailyAt('06:00')->withoutOverlapping();
