<?php

namespace App\Console\Commands;

use App\Services\Presentations\SeriesGenerator;
use Illuminate\Console\Command;

/**
 * Materialises upcoming showings from every repeating schedule.
 *
 * Runs daily. Each series keeps a rolling window of occurrences ahead of it, so
 * members always have a link to share for the next few weeks and a change to
 * the schedule shows up soon without rewriting history.
 */
class GeneratePresentationSeries extends Command
{
    protected $signature = 'presentations:generate';

    protected $description = 'Create upcoming showings from repeating presentation schedules';

    public function handle(SeriesGenerator $generator): int
    {
        $created = $generator->generateAll();

        $this->info("Created {$created} upcoming showing(s).");

        return self::SUCCESS;
    }
}
