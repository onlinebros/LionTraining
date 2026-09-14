<?php

namespace App\Services\Stripe;

use App\Exceptions\BillingException;
use App\Models\CommissionPayout;
use App\Models\User;
use App\Services\CommissionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\ApiErrorException;

/**
 * Pays a commission payout into the partner's connected account.
 *
 * A Transfer moves money from the Q3 Stripe balance to the partner's connected
 * account the moment it is created; Stripe then pays it to their bank on its
 * own schedule. A transfer can be reversed only while the money is still on the
 * connected account's balance.
 */
class StripePayoutService
{
    public function __construct(
        private readonly StripeClientFactory $stripe,
        private readonly StripeConnectService $connect,
        private readonly CommissionService $commissions,
    ) {}

    /** Why this payout cannot be sent through Stripe right now, or null if it can. */
    public function blockingReason(CommissionPayout $payout): ?string
    {
        if (! $this->connect->enabled()) {
            return 'Stripe payouts are switched off.';
        }

        if ($payout->stripe_transfer_id) {
            return 'This payout has already been sent through Stripe.';
        }

        if (! in_array($payout->status, ['pending', 'approved'], true)) {
            return "A {$payout->status} payout cannot be sent.";
        }

        if ($this->amountInCents($payout) <= 0) {
            return 'The payout amount must be greater than zero.';
        }

        $earner = $payout->earner;

        if (! $earner) {
            return 'This payout has no earner.';
        }

        if (! $earner->hasConnectAccount()) {
            return "{$earner->name} has not set up their payout account yet.";
        }

        if (! $earner->connect_payouts_enabled) {
            $due = \App\Support\ConnectRequirements::summarise($earner->connectOutstandingRequirements());

            return "{$earner->name}'s payout account is not ready"
                .($due ? ': Stripe still needs '.implode(', ', array_slice($due, 0, 4)).'.' : '.');
        }

        return null;
    }

    /**
     * Send the transfer and mark the payout paid.
     *
     * @throws BillingException with a message safe to show an admin.
     */
    public function send(CommissionPayout $payout, ?User $processedBy = null): CommissionPayout
    {
        $payout->loadMissing('earner');

        // Re-check with Stripe first: an account can be restricted between the
        // page render and the click. A status refresh, so a duplicate-bank
        // suspicion must not abort paying money already earned.
        if ($payout->earner) {
            $this->connect->syncAccount($payout->earner, enforceIdentity: false);
            $payout->earner->refresh();
        }

        if ($reason = $this->blockingReason($payout)) {
            throw new BillingException($reason);
        }

        $destination = $payout->earner->stripe_connect_account_id;

        try {
            $transfer = $this->stripe->client()->transfers->create([
                'amount'         => $this->amountInCents($payout),
                'currency'       => config('stripe.subscription.currency', 'usd'),
                'destination'    => $destination,
                'description'    => config('app.name')." commission payout #{$payout->id}",
                'transfer_group' => "payout_{$payout->id}",
                'metadata'       => [
                    'payout_id'    => (string) $payout->id,
                    'earner_id'    => (string) $payout->earner_id,
                    'period_start' => (string) $payout->period_start?->toDateString(),
                    'period_end'   => (string) $payout->period_end?->toDateString(),
                    'app'          => 'q3',
                ],
            ], [
                // With the unique index on stripe_transfer_id: a retried click
                // gets the first transfer back instead of a second one.
                'idempotency_key' => 'commission_payout_'.$payout->id,
            ]);
        } catch (ApiErrorException $e) {
            $code = $e->getError()->code ?? null;

            $message = $code === 'balance_insufficient'
                ? 'The Q3 Stripe balance is too low to cover this payout. Wait for membership payments to settle and try again.'
                : 'Stripe refused the transfer: '.($e->getError()->message ?? $e->getMessage());

            $payout->update([
                'transfer_status'         => 'failed',
                'transfer_failure_reason' => $message,
            ]);

            Log::error('Commission transfer failed', ['payout_id' => $payout->id, 'code' => $code, 'error' => $e->getMessage()]);

            throw new BillingException($message);
        }

        DB::transaction(function () use ($payout, $transfer, $destination, $processedBy) {
            $payout->update([
                'stripe_transfer_id'         => $transfer->id,
                'stripe_destination_account' => $destination,
                'transfer_status'            => 'paid',
                'transfer_failure_reason'    => null,
                'transferred_at'             => now(),
            ]);

            $this->commissions->markPayoutPaid(
                payout: $payout,
                paymentReference: $transfer->id,
                paymentMethod: 'stripe_connect',
                processedBy: $processedBy,
            );
        });

        return $payout->fresh();
    }

    /**
     * Reverse a transfer that should not have been sent.
     *
     * Fails once Stripe has paid the money out to the partner's bank; after that
     * it has to be recovered outside Stripe.
     */
    public function reverse(CommissionPayout $payout, ?string $reason = null): CommissionPayout
    {
        if (! $payout->stripe_transfer_id) {
            throw new BillingException('This payout was never sent through Stripe.');
        }

        if ($payout->transfer_status === 'reversed') {
            throw new BillingException('This transfer has already been reversed.');
        }

        try {
            $this->stripe->client()->transfers->createReversal(
                $payout->stripe_transfer_id,
                ['metadata' => ['reason' => $reason ?? 'admin reversal']],
                ['idempotency_key' => 'commission_reversal_'.$payout->id],
            );
        } catch (ApiErrorException $e) {
            throw new BillingException('Stripe could not reverse this transfer: '.($e->getError()->message ?? $e->getMessage()));
        }

        $payout->update([
            'transfer_status'         => 'reversed',
            'transfer_failure_reason' => $reason,
        ]);

        return $payout->fresh();
    }

    /** total_amount is decimal(15,4); Stripe wants integer cents. Round, never truncate. */
    private function amountInCents(CommissionPayout $payout): int
    {
        return (int) round(((float) $payout->total_amount) * 100);
    }
}
