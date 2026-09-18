<?php

namespace App\Console\Commands;

use App\Services\Presentations\ConversionTracker;
use Illuminate\Console\Command;

/**
 * Links guests who signed up under the address they watched under.
 *
 * The fallback, not the main path — anyone who clicked through from a
 * presentation is already linked by token, whatever address they used. This
 * catches the person who wandered off and came back through some other door a
 * week later.
 */
class SweepPresentationConversions extends Command
{
    protected $signature = 'presentations:match-conversions';

    protected $description = 'Link presentation guests to accounts that share their email';

    public function handle(ConversionTracker $tracker): int
    {
        $linked = $tracker->sweepByEmail();

        $this->info("Linked {$linked} guest(s) to an account by email.");

        return self::SUCCESS;
    }
}
