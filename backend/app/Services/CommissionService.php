<?php

namespace App\Services;

use App\Jobs\CheckCommissionBillingJob;
use App\Models\CommissionClawback;
use App\Models\CommissionLedger;
use App\Models\CommissionPayout;
use App\Models\CommissionPlan;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class CommissionService
{
    /**
     * Record a commission credit for an earner triggered by a sale or other source.
     */
    public function recordEarning(
        User $earner,
        CommissionPlan $plan,
        float $saleAmount,
        ?Model $source = null,
        ?string $notes = null,
        ?User $createdBy = null
    ): CommissionLedger {
        $amount = $this->calculateAmount($plan, $saleAmount);

        if ($amount <= 0) {
            throw new InvalidArgumentException("Calculated commission amount must be greater than zero.");
        }

        $clawbackUntil = $plan->clawback_window_days
            ? now()->addDays($plan->clawback_window_days)->toDateString()
            : null;

        return CommissionLedger::create([
            'earner_id'              => $earner->id,
            'commission_plan_id'     => $plan->id,
            'source_type'            => $source ? get_class($source) : null,
            'source_id'              => $source?->getKey(),
            'type'                   => 'credit',
            'amount'                 => $amount,
            'status'                 => 'pending',
            'clawback_eligible_until' => $clawbackUntil,
            'notes'                  => $notes,
            'created_by'             => $createdBy?->id,
        ]);
    }

    /**
     * Calculate commission amount from a plan config and a sale total.
     */
    public function calculateAmount(CommissionPlan $plan, float $saleAmount): float
    {
        $config = $plan->config;

        return match ($plan->type) {
            'flat'       => (float) ($config['amount'] ?? 0),
            'percentage' => round($saleAmount * (float) ($config['rate'] ?? 0), 4),
            'tiered'     => $this->calculateTiered($config['tiers'] ?? [], $saleAmount),
            default      => throw new InvalidArgumentException("Unknown commission plan type: {$plan->type}"),
        };
    }

    /**
     * Get an earner's current unpaid balance (credits minus debits, not yet in a paid payout).
     */
    public function getBalance(User $earner): float
    {
        $credits = CommissionLedger::where('earner_id', $earner->id)
            ->credits()
            ->unpaid()
            ->sum('amount');

        $debits = CommissionLedger::where('earner_id', $earner->id)
            ->debits()
            ->whereIn('status', ['pending', 'approved'])
            ->sum('amount');

        return max(0, (float) $credits - (float) $debits);
    }

    /**
     * Get lifetime total paid out to an earner.
     */
    public function getLifetimePaid(User $earner): float
    {
        return (float) CommissionPayout::where('earner_id', $earner->id)
            ->where('status', 'paid')
            ->sum('total_amount');
    }

    /**
     * Create a payout batch for an earner from all their unpaid, approved ledger credits.
     * Debits (clawbacks) are included to net off the total.
     */
    public function createPayout(
        User $earner,
        ?Carbon $periodStart = null,
        ?Carbon $periodEnd = null,
        ?string $notes = null,
        ?User $processedBy = null
    ): CommissionPayout {
        return DB::transaction(function () use ($earner, $periodStart, $periodEnd, $notes, $processedBy) {
            $query = CommissionLedger::where('earner_id', $earner->id)
                ->whereIn('status', ['pending', 'approved'])
                ->whereNull('payout_id');

            if ($periodStart) {
                $query->whereDate('created_at', '>=', $periodStart);
            }
            if ($periodEnd) {
                $query->whereDate('created_at', '<=', $periodEnd);
            }

            $entries = $query->get();

            if ($entries->isEmpty()) {
                throw new RuntimeException("No unpaid ledger entries found for this earner.");
            }

            $credits = $entries->where('type', 'credit')->sum('amount');
            $debits  = $entries->where('type', 'debit')->sum('amount');
            $total   = max(0, $credits - $debits);

            $payout = CommissionPayout::create([
                'earner_id'    => $earner->id,
                'period_start' => $periodStart?->toDateString(),
                'period_end'   => $periodEnd?->toDateString(),
                'total_amount' => $total,
                'status'       => 'pending',
                'notes'        => $notes,
                'processed_by' => $processedBy?->id,
            ]);

            $entries->each(function (CommissionLedger $entry) use ($payout) {
                $entry->update(['payout_id' => $payout->id]);
            });

            return $payout;
        });
    }

    /**
     * Approve a payout (admin review step before marking paid).
     */
    public function approvePayout(CommissionPayout $payout, User $approvedBy): void
    {
        if (! $payout->isPending()) {
            throw new RuntimeException("Only pending payouts can be approved.");
        }

        $payout->update([
            'status'       => 'approved',
            'processed_by' => $approvedBy->id,
        ]);
    }

    /**
     * Mark a payout as paid and update all included ledger entries.
     */
    public function markPayoutPaid(
        CommissionPayout $payout,
        string $paymentReference,
        ?string $paymentMethod = null,
        ?string $notes = null,
        ?User $processedBy = null
    ): void {
        if (! in_array($payout->status, ['pending', 'approved'])) {
            throw new RuntimeException("Only pending or approved payouts can be marked as paid.");
        }

        DB::transaction(function () use ($payout, $paymentReference, $paymentMethod, $notes, $processedBy) {
            $payout->update([
                'status'            => 'paid',
                'payment_reference' => $paymentReference,
                'payment_method'    => $paymentMethod,
                'paid_at'           => now(),
                'notes'             => $notes ?? $payout->notes,
                'processed_by'      => $processedBy?->id ?? $payout->processed_by,
            ]);

            $payout->ledgerEntries()->update(['status' => 'paid']);

            // A partner waiting on commissions may have just reached the
            // billing threshold. Only once this payout is committed.
            CheckCommissionBillingJob::dispatch($payout->earner_id)->afterCommit();
        });
    }

    /**
     * Cancel a payout and release its ledger entries back to unpaid.
     */
    public function cancelPayout(CommissionPayout $payout): void
    {
        if ($payout->isPaid()) {
            throw new RuntimeException("Cannot cancel a payout that has already been paid.");
        }

        DB::transaction(function () use ($payout) {
            $payout->ledgerEntries()->update(['payout_id' => null]);
            $payout->update(['status' => 'cancelled']);
        });
    }

    /**
     * Initiate a clawback on a commission credit ledger entry.
     *
     * If the clawback window has passed, $overrideWindow must be true and
     * $overrideNotes must explain the reason for the override.
     */
    public function initiateClawback(
        CommissionLedger $ledger,
        string $reason,
        User $initiatedBy,
        bool $overrideWindow = false,
        ?string $overrideNotes = null
    ): CommissionClawback {
        if ($ledger->type !== 'credit') {
            throw new InvalidArgumentException("Clawbacks can only be initiated against credit entries.");
        }

        if ($ledger->status === 'voided') {
            throw new RuntimeException("This ledger entry has already been voided.");
        }

        if ($ledger->clawback()->where('status', '!=', 'reversed')->exists()) {
            throw new RuntimeException("An active clawback already exists for this ledger entry.");
        }

        $withinWindow = $ledger->isClawbackEligible();

        if (! $withinWindow && ! $overrideWindow) {
            throw new RuntimeException(
                "This commission is outside the clawback window. Set override_window=true with override_notes to proceed."
            );
        }

        if ($overrideWindow && empty($overrideNotes)) {
            throw new InvalidArgumentException("override_notes is required when overriding the clawback window.");
        }

        return DB::transaction(function () use ($ledger, $reason, $initiatedBy, $overrideWindow, $overrideNotes) {
            // Create a debit entry to offset the credit
            $debit = CommissionLedger::create([
                'earner_id'          => $ledger->earner_id,
                'commission_plan_id' => $ledger->commission_plan_id,
                'payout_id'          => null,
                'source_type'        => $ledger->source_type,
                'source_id'          => $ledger->source_id,
                'type'               => 'debit',
                'amount'             => $ledger->amount,
                'status'             => 'pending',
                'notes'              => "Clawback: {$reason}",
                'created_by'         => $initiatedBy->id,
            ]);

            $clawback = CommissionClawback::create([
                'original_ledger_id' => $ledger->id,
                'debit_ledger_id'    => $debit->id,
                'earner_id'          => $ledger->earner_id,
                'amount'             => $ledger->amount,
                'reason'             => $reason,
                'status'             => 'applied',
                'initiated_by'       => $initiatedBy->id,
                'applied_at'         => now(),
                'override_window'    => $overrideWindow,
                'override_notes'     => $overrideNotes,
            ]);

            // Void the original credit if it hasn't been paid out yet
            if (! $ledger->isPaid()) {
                $ledger->update(['status' => 'voided']);
            }

            return $clawback;
        });
    }

    /**
     * Reverse a previously applied clawback (admin mistake correction).
     */
    public function reverseClawback(CommissionClawback $clawback): void
    {
        if (! $clawback->isApplied()) {
            throw new RuntimeException("Only applied clawbacks can be reversed.");
        }

        DB::transaction(function () use ($clawback) {
            // Void the debit entry
            $clawback->debitLedger?->update(['status' => 'voided']);

            // Restore the original credit to pending if it was voided
            $original = $clawback->originalLedger;
            if ($original && $original->status === 'voided') {
                $original->update(['status' => 'pending']);
            }

            $clawback->update(['status' => 'reversed']);
        });
    }

    // ── Private helpers ────────────────────────────────────────────────────────

    private function calculateTiered(array $tiers, float $saleAmount): float
    {
        foreach ($tiers as $tier) {
            $min = (float) ($tier['min'] ?? 0);
            $max = isset($tier['max']) ? (float) $tier['max'] : null;

            if ($saleAmount >= $min && ($max === null || $saleAmount <= $max)) {
                return round($saleAmount * (float) ($tier['rate'] ?? 0), 4);
            }
        }

        return 0.0;
    }
}
