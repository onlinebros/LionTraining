<?php

namespace App\Services\Stripe;

use App\Models\CommissionPayout;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Starts billing for "wait for my commissions" partners once their paid
 * commissions add up to the threshold.
 *
 * Runs from a queued job after every payout is marked paid, and again from the
 * daily `billing:commission-holds` sweep in case a job was lost.
 */
class CommissionBillingTrigger
{
    public function __construct(private readonly BillingService $billing) {}

    /**
     * Commission paid to the partner so far, in dollars.
     *
     * A Stripe transfer that was reversed stays marked paid on the payout, but
     * the partner never kept the money, so it does not count.
     */
    public function paidTotal(User $user): float
    {
        return (float) CommissionPayout::query()
            ->where('earner_id', $user->id)
            ->where('status', 'paid')
            ->where(fn ($q) => $q->whereNull('transfer_status')->orWhere('transfer_status', '!=', 'reversed'))
            ->sum('total_amount');
    }

    public function threshold(): float
    {
        return (float) config('stripe.subscription.commission_threshold', 200);
    }

    /** @return bool whether billing was started */
    public function check(User $user): bool
    {
        $subscription = $user->subscriptions()->commissionHolds()->latest('id')->first();

        if ($subscription === null || $this->paidTotal($user) < $this->threshold()) {
            return false;
        }

        $this->billing->startBillingOnLaunchSchedule($subscription);

        $subscription->forceFill(['billing_trigger_met_at' => now()])->save();

        Log::info('Commission threshold reached; membership billing started', [
            'user_id'         => $user->id,
            'subscription_id' => $subscription->id,
        ]);

        return true;
    }

    /**
     * Push a hold that is getting close to its end back out.
     *
     * @return bool whether it was moved
     */
    public function renew(Subscription $subscription): bool
    {
        $renewWithin = (int) config('stripe.subscription.commission_hold_renew_days', 90);

        if (! $subscription->isCommissionHold()
            || $subscription->trial_ends_at === null
            || $subscription->trial_ends_at->isAfter(now()->addDays($renewWithin))) {
            return false;
        }

        $this->billing->setTrialEnd($subscription, $this->billing->commissionHoldEnd());

        return true;
    }
}
