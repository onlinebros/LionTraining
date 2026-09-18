<?php

namespace App\Console\Commands;

use App\Models\Presentation;
use Illuminate\Console\Command;

/**
 * Starts presentations at their scheduled time and ends them when the video
 * runs out.
 *
 * Runs every minute. The important detail is in `Presentation::start()`: the
 * showing's `started_at` is set to the time it was *scheduled* for, not to the
 * moment this command happened to fire. Every viewer's position is derived from
 * `started_at`, so using "now" would shift the entire audience by however late
 * the timer ran and push the ending out to match.
 */
class RunScheduledPresentations extends Command
{
    protected $signature = 'presentations:run';

    protected $description = 'Start presentations that are due and end ones whose video has finished';

    public function handle(): int
    {
        $started = 0;

        foreach (Presentation::where('status', Presentation::STATUS_SCHEDULED)
            ->where('scheduled_at', '<=', now())
            ->get() as $presentation
        ) {
            $presentation->start();
            $started++;
            $this->line("Started \"{$presentation->title}\".");
        }

        // A showing nobody ended: close it once the video has run out, so the
        // replay rules apply and the room stops claiming to be in progress.
        $ended = 0;

        foreach (Presentation::where('status', Presentation::STATUS_LIVE)->get() as $presentation) {
            if (! $presentation->isOverrun()) {
                continue;
            }

            $presentation->end();
            $ended++;
            $this->line("Ended \"{$presentation->title}\".");
        }

        $this->info("Started {$started}, ended {$ended}.");

        return self::SUCCESS;
    }
}
