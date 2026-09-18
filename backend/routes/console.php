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

// Screen recordings abandoned mid-capture leave part-files on the droplet's
// small disk. Sweep them nightly before they can fill it.
Schedule::command('recordings:prune')->dailyAt('03:30');

// Presentations start on their own at the scheduled minute, and close
// themselves when the video runs out. Every-minute: a showing that starts a
// minute late would put the whole audience a minute off.
Schedule::command('presentations:run')->everyMinute()->withoutOverlapping();

// Repeating schedules keep a rolling window of showings ahead of them.
Schedule::command('presentations:generate')->dailyAt('04:00')->withoutOverlapping();

// Guests who signed up with the address they watched under. The fallback only —
// anyone who clicked through is already linked by token, whatever address they
// used.
Schedule::command('presentations:match-conversions')->hourly()->withoutOverlapping();
