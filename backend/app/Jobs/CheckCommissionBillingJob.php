<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\Stripe\CommissionBillingTrigger;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * After a payout is marked paid: start the partner's billing if they were
 * waiting on commissions and have now reached the threshold.
 *
 * Queued so a Stripe outage can neither fail the payout nor be lost; the daily
 * `billing:commission-holds` sweep catches anything this misses.
 */
class CheckCommissionBillingJob implements ShouldQueue
{
    use Queueable;

    /** @var array<int,int> */
    public array $backoff = [10, 60, 300];

    public int $tries = 4;

    public function __construct(public int $userId) {}

    public function handle(CommissionBillingTrigger $trigger): void
    {
        $user = User::find($this->userId);

        if ($user !== null) {
            $trigger->check($user);
        }
    }
}
