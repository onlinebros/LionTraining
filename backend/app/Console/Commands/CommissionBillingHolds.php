<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use App\Services\Stripe\CommissionBillingTrigger;
use Illuminate\Console\Command;
use Stripe\Exception\ApiErrorException;

/**
 * Daily upkeep for "wait for my commissions" partners.
 *
 * Starts billing for anyone whose paid commissions have reached the threshold
 * (in case the job after their payout was lost), and pushes every other hold
 * back out before Stripe's two-year trial limit would end it and charge a
 * partner who was promised they would not be charged.
 */
class CommissionBillingHolds extends Command
{
    protected $signature = 'billing:commission-holds
                            {--dry-run : Show what would change without calling the provider}';

    protected $description = 'Start billing for held partners who reached the commission threshold, and renew the rest';

    public function handle(CommissionBillingTrigger $trigger): int
    {
        $holds = Subscription::query()->commissionHolds()->with('user')->orderBy('id')->get();

        $started = 0;
        $renewed = 0;
        $failed  = 0;

        foreach ($holds as $subscription) {
            $user = $subscription->user;

            if ($user === null) {
                continue;
            }

            $paid = $trigger->paidTotal($user);

            if ($this->option('dry-run')) {
                $this->line(sprintf(
                    '%s  paid $%.2f  hold ends %s%s',
                    $user->email,
                    $paid,
                    $subscription->trial_ends_at?->toDateString() ?? '—',
                    $paid >= $trigger->threshold() ? '  → would start billing' : '',
                ));

                continue;
            }

            try {
                if ($trigger->check($user)) {
                    $started++;
                } elseif ($trigger->renew($subscription)) {
                    $renewed++;
                }
            } catch (ApiErrorException $e) {
                $failed++;
                $this->error("  Subscription {$subscription->id} ({$user->email}): {$e->getMessage()}");
            }
        }

        if ($this->option('dry-run')) {
            $this->comment("{$holds->count()} hold(s). Dry run — nothing was changed.");

            return self::SUCCESS;
        }

        $this->info("{$holds->count()} hold(s): billing started for {$started}, renewed {$renewed}.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
