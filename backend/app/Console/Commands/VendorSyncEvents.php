<?php

namespace App\Console\Commands;

use App\Services\Vendor\VendorEventSync;
use App\Support\Vendors;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Recovers vendor payment events whose webhook never arrived.
 *
 * Scheduled every fifteen minutes in routes/console.php. Safe to run by hand at
 * any time: an event already in the ledger is skipped, so running it twice, or
 * alongside a webhook delivering the same event, confirms an order once.
 */
class VendorSyncEvents extends Command
{
    protected $signature = 'vendors:sync-events
                            {vendor? : Registry slug; every vendor when omitted}
                            {--dry-run : List what would be recovered without recording anything}';

    protected $description = 'Recover vendor payment events whose webhook never arrived';

    public function handle(VendorEventSync $sync): int
    {
        $slug   = $this->argument('vendor');
        $dryRun = (bool) $this->option('dry-run');

        if ($slug !== null && Vendors::find($slug) === null) {
            $this->error("Unknown vendor '{$slug}'.");

            return self::FAILURE;
        }

        $failed = false;

        foreach ($slug !== null ? [$slug] : array_keys(Vendors::all()) as $vendor) {
            if ($reason = $sync->skipReason($vendor)) {
                $this->line("{$vendor}: skipped, {$reason}.");

                continue;
            }

            try {
                $result = $sync->sync($vendor, $dryRun);
            } catch (Throwable $e) {
                $failed = true;

                Log::error('Vendor event sync failed', ['vendor' => $vendor, 'error' => $e->getMessage()]);
                $this->error("{$vendor}: {$e->getMessage()}");

                continue;
            }

            $this->line(sprintf(
                '%s: %d events from %s to %s, %s %d%s',
                $vendor,
                $result['seen'],
                $result['from']->toIso8601String(),
                $result['to']->toIso8601String(),
                $dryRun ? 'would recover' : 'recovered',
                count($result['recovered']),
                $result['recovered'] !== [] ? ' ('.implode(', ', $result['recovered']).')' : '',
            ));
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
