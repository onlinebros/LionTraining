<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use App\Services\Stripe\BillingService;
use App\Support\Prelaunch;
use Illuminate\Console\Command;
use Stripe\Exception\ApiErrorException;

/**
 * Rolls every parked pre-launch trial onto the real schedule.
 *
 * Partners who chose "Recover my genius now" during pre-launch were promised no
 * charge until the training program opens, but the date was unknown at the
 * time, so their subscriptions sit on a placeholder trial far in the future.
 * Once `QL_PRELAUNCH_ENDS_AT` is set, this moves each one to end on that date
 * (the first charge) and clears the flag. Partners waiting on commissions are
 * never flagged and are left alone.
 *
 * Run it on launch day BEFORE opening the closed sections, so nobody hits a
 * billing state mid-session. It is the one launch step that touches money, so
 * --dry-run first, always.
 */
class ApplyPrelaunchEndDate extends Command
{
    protected $signature = 'billing:apply-prelaunch-end
                            {--dry-run : Show what would change without calling the provider}
                            {--limit= : Process at most this many}';

    protected $description = 'Move parked pre-launch trials onto the real schedule';

    public function handle(BillingService $billing): int
    {
        $launchAt = Prelaunch::endsAt();

        if ($launchAt === null) {
            $this->error('No launch date set. Set QL_PRELAUNCH_ENDS_AT first — there is nothing to apply until then.');

            return self::FAILURE;
        }

        $target = $launchAt->copy();

        $this->line('');
        $this->info("Launch date : {$launchAt->toDayDateTimeString()}");
        $this->info("First charge: {$target->toDayDateTimeString()} (launch day)");
        $this->line('');

        $query = Subscription::query()
            ->where('is_prelaunch_trial', true)
            ->where('billing_trigger', Subscription::TRIGGER_LAUNCH)
            ->whereIn('status', [Subscription::STATUS_TRIALING, Subscription::STATUS_ACTIVE])
            ->with('user')
            ->orderBy('id');

        if ($limit = $this->option('limit')) {
            $query->limit((int) $limit);
        }

        $subscriptions = $query->get();

        if ($subscriptions->isEmpty()) {
            $this->info('No parked pre-launch trials found.');

            return self::SUCCESS;
        }

        $this->line("{$subscriptions->count()} parked trial(s) to move.");

        if ($this->option('dry-run')) {
            $this->table(
                ['ID', 'Partner', 'Current trial end', 'Status'],
                $subscriptions->map(fn (Subscription $s) => [
                    $s->id,
                    $s->user?->email ?? '—',
                    $s->trial_ends_at?->toDateString() ?? '—',
                    $s->status,
                ])->all(),
            );
            $this->comment('Dry run — nothing was changed.');

            return self::SUCCESS;
        }

        $moved = 0;
        $failed = 0;

        // One provider call per subscription, and each is a real charge-schedule
        // change. Failures are collected rather than aborting the run: a single
        // deleted subscription must not strand everyone after it in the list.
        foreach ($subscriptions as $subscription) {
            try {
                // Launch has already passed: charge now rather than two days late.
                $target->isPast()
                    ? $billing->endTrialNow($subscription)
                    : $billing->setTrialEnd($subscription, $target->copy(), stillPrelaunch: false);
                $moved++;
            } catch (ApiErrorException $e) {
                $failed++;
                $this->error("  Subscription {$subscription->id} ({$subscription->user?->email}): {$e->getMessage()}");
            }
        }

        $this->line('');
        $this->info("Moved {$moved} trial(s).");

        if ($failed > 0) {
            $this->error("{$failed} failed — listed above. Re-run to retry just those.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
