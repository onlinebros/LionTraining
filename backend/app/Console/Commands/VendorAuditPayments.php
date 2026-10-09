<?php

namespace App\Console\Commands;

use App\Services\Vendor\VendorPaymentAuditor;
use App\Support\Vendors;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Asks each vendor's Stripe what became of the orders we built on it.
 *
 * Scheduled hourly in routes/console.php. Read-only: it records Stripe's
 * answer in vendor_payment_audits and changes no order, so it is safe to run
 * by hand at any time.
 */
class VendorAuditPayments extends Command
{
    protected $signature = 'vendors:audit-payments
                            {vendor? : Registry slug; every vendor when omitted}
                            {--days=30 : How far back to look (Stripe keeps 30 days of events)}';

    protected $description = 'Record what the vendor\'s Stripe says happened to each order';

    public function handle(VendorPaymentAuditor $auditor): int
    {
        $slug = $this->argument('vendor');

        if ($slug !== null && Vendors::find($slug) === null) {
            $this->error("Unknown vendor '{$slug}'.");

            return self::FAILURE;
        }

        $failed = false;

        foreach ($slug !== null ? [$slug] : array_keys(Vendors::all()) as $vendor) {
            if ($reason = $auditor->skipReason($vendor)) {
                $this->line("{$vendor}: skipped, {$reason}.");

                continue;
            }

            try {
                $result = $auditor->audit($vendor, (int) $this->option('days'));
            } catch (Throwable $e) {
                $failed = true;

                Log::error('Vendor payment audit failed', ['vendor' => $vendor, 'error' => $e->getMessage()]);
                $this->error("{$vendor}: {$e->getMessage()}");

                continue;
            }

            $verdicts = collect($result['verdicts'])->map(fn ($n, $v) => "{$v} {$n}")->implode(', ');

            $this->line(sprintf('%s: %d order(s) checked%s', $vendor, $result['checked'], $verdicts ? " — {$verdicts}" : ''));

            if ($result['events_error']) {
                $this->warn("{$vendor}: attempt history unavailable: {$result['events_error']}");
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
