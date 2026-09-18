<?php

namespace App\Services\Vendor;

use App\Models\CommissionLedger;
use App\Models\PromotionAward;
use App\Models\VendorLead;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The launch special's money (owner, 2026-09-17).
 *
 *   - Each of the first `cap` systems earns a place bonus for the partner the
 *     sale counts for, on top of the normal commission.
 *   - Each of the next `pool_units` systems adds `pool_per_unit` to a pool
 *     shared one share per place, credited as each sale is confirmed.
 *
 * reconcile() works out from the current standings what should have been
 * credited, compares it with what has been, and adds or voids ledger credits to
 * match. It is safe to run any number of times. Run after anything that can move
 * the standings: a confirmation, a refund, an attribution decision.
 *
 * A credit that should go but was already approved or paid is not reversed. Its
 * award is marked needs_clawback for an admin, who uses the clawback workflow.
 */
class PromotionBonuses
{
    public function __construct(private readonly PromotionTracker $tracker) {}

    /**
     * The special's terms, or null when the promotion has no bonus.
     *
     * @param  array<string,mixed>|null  $promotion
     * @return array{place_amount: float, pool_units: int, pool_per_unit: float, pool_total: float, per_share: float, lock_days: int}|null
     */
    public static function terms(?array $promotion): ?array
    {
        $bonus = $promotion['bonus'] ?? null;

        if (! is_array($bonus) || ! ($bonus['enabled'] ?? false)) {
            return null;
        }

        $cap       = max(1, (int) ($promotion['cap'] ?? 100));
        $poolUnits = max(0, (int) ($bonus['pool_units'] ?? 0));
        $perUnit   = max(0.0, (float) ($bonus['pool_per_unit'] ?? 0));

        return [
            'place_amount'  => max(0.0, (float) ($bonus['place_amount'] ?? 0)),
            'pool_units'    => $poolUnits,
            'pool_per_unit' => $perUnit,
            'pool_total'    => $poolUnits * $perUnit,
            'per_share'     => round($perUnit / $cap, 4),
            'lock_days'     => (int) ($bonus['lock_days'] ?? 60),
        ];
    }

    /** Reconcile every bonus promotion this order's product belongs to. */
    public function reconcileFor(VendorLead $lead): void
    {
        foreach ((array) config('promotions.promotions', []) as $key => $promotion) {
            if (($promotion['vendor'] ?? null) === $lead->vendor
                && ($promotion['product'] ?? null) === $lead->product_key
                && self::terms($promotion) !== null) {
                $this->reconcile((string) $key);
            }
        }
    }

    public function reconcile(string $key): void
    {
        $promotion = $this->tracker->find($key);
        $terms     = self::terms($promotion);

        if ($promotion === null || $terms === null) {
            return;
        }

        DB::transaction(function () use ($key, $promotion, $terms) {
            // One reconcile per promotion at a time; two webhooks landing
            // together must not both credit the same place.
            DB::select('SELECT pg_advisory_xact_lock(?)', [crc32("promotion-bonus:{$key}")]);

            $cap     = $this->tracker->cap($promotion);
            $desired = $this->desired($this->tracker->rankedUnits($key, $cap + $terms['pool_units']), $cap, $terms);

            $existing = PromotionAward::with('ledger')
                ->where('promotion_key', $key)
                ->active()
                ->lockForUpdate()
                ->get()
                ->keyBy(fn (PromotionAward $award) => $award->identity());

            foreach ($existing as $identity => $award) {
                $want = $desired[$identity] ?? null;

                if ($want === null
                    || $want['earner_id'] !== (int) $award->earner_id
                    || abs($want['amount'] - (float) $award->amount) > 0.00005) {
                    $this->retire($award);
                    $existing->forget($identity);

                    continue;
                }

                // Same money, same person: only the labels may have moved.
                $award->fill(['place' => $want['place'], 'units' => $want['units'], 'shares' => $want['shares']]);

                if ($award->isDirty()) {
                    $award->save();
                }
            }

            foreach ($desired as $identity => $want) {
                if (! $existing->has($identity)) {
                    $this->award($key, $promotion, $terms, $want);
                }
            }
        });
    }

    /**
     * Credited totals per earner.
     *
     * @return array<int, array{places: int, place: float, pool: float, total: float}>
     */
    public function totalsByEarner(string $key): array
    {
        $totals = [];

        $rows = PromotionAward::where('promotion_key', $key)
            ->active()
            ->selectRaw('earner_id, kind, COUNT(*) AS awards, SUM(amount) AS amount')
            ->groupBy('earner_id', 'kind')
            ->get();

        foreach ($rows as $row) {
            $earner = (int) $row->earner_id;
            $totals[$earner] ??= ['places' => 0, 'place' => 0.0, 'pool' => 0.0, 'total' => 0.0];

            if ($row->kind === PromotionAward::KIND_PLACE) {
                $totals[$earner]['places'] = (int) $row->getAttribute('awards');
                $totals[$earner]['place']  = (float) $row->amount;
            } else {
                $totals[$earner]['pool'] = (float) $row->amount;
            }

            $totals[$earner]['total'] = $totals[$earner]['place'] + $totals[$earner]['pool'];
        }

        return $totals;
    }

    /**
     * @return array{place_total: float, place_count: int, pool_total: float, needs_clawback: Collection<int, PromotionAward>}
     */
    public function overview(string $key): array
    {
        $active = PromotionAward::where('promotion_key', $key)->active();

        return [
            'place_total'    => (float) (clone $active)->where('kind', PromotionAward::KIND_PLACE)->sum('amount'),
            'place_count'    => (clone $active)->where('kind', PromotionAward::KIND_PLACE)->count(),
            'pool_total'     => (float) (clone $active)->where('kind', PromotionAward::KIND_POOL)->sum('amount'),
            'needs_clawback' => PromotionAward::with(['lead', 'earner', 'ledger'])
                ->where('promotion_key', $key)
                ->where('status', PromotionAward::STATUS_NEEDS_CLAWBACK)
                ->latest('voided_at')
                ->get(),
        ];
    }

    // ── Internals ─────────────────────────────────────────────────────────────

    /**
     * What should be credited, keyed by award identity.
     *
     * A place held by an order waiting for an attribution decision earns
     * nothing until it is decided, and its pool share is held with it. The
     * share price is fixed (pool_per_unit / cap), so holding one share never
     * changes anyone else's.
     *
     * @param  list<array{lead: VendorLead, unit: int}>  $slots
     * @param  array<string,mixed>  $terms
     * @return array<string, array{kind: string, lead: VendorLead, unit: int, earner_id: int, amount: float, place: int, units: int, shares: int}>
     */
    private function desired(array $slots, int $cap, array $terms): array
    {
        $desired = [];
        $shares  = [];

        foreach (array_slice($slots, 0, $cap) as $index => $slot) {
            $lead   = $slot['lead'];
            $earner = $lead->attribution === VendorLead::ATTRIBUTION_REVIEW ? null : $lead->credited_member_id;

            if ($earner === null) {
                continue;
            }

            $shares[$earner] = ($shares[$earner] ?? 0) + 1;

            if ($terms['place_amount'] > 0) {
                $desired[PromotionAward::identityFor(PromotionAward::KIND_PLACE, $lead->id, $slot['unit'], $earner)] = [
                    'kind'      => PromotionAward::KIND_PLACE,
                    'lead'      => $lead,
                    'unit'      => $slot['unit'],
                    'earner_id' => $earner,
                    'amount'    => round($terms['place_amount'], 4),
                    'place'     => $index + 1,
                    'units'     => 1,
                    'shares'    => 1,
                ];
            }
        }

        // The pool only exists once every place is filled.
        if (count($slots) <= $cap || $shares === [] || $terms['per_share'] <= 0) {
            return $desired;
        }

        $contributions = [];

        foreach (array_slice($slots, $cap) as $index => $slot) {
            $id = $slot['lead']->id;
            $contributions[$id] ??= ['lead' => $slot['lead'], 'units' => 0, 'first' => $cap + $index + 1];
            $contributions[$id]['units']++;
        }

        foreach ($contributions as $leadId => $contribution) {
            foreach ($shares as $earner => $held) {
                $desired[PromotionAward::identityFor(PromotionAward::KIND_POOL, $leadId, 0, $earner)] = [
                    'kind'      => PromotionAward::KIND_POOL,
                    'lead'      => $contribution['lead'],
                    'unit'      => 0,
                    'earner_id' => $earner,
                    'amount'    => round($contribution['units'] * $held * $terms['per_share'], 4),
                    'place'     => $contribution['first'],
                    'units'     => $contribution['units'],
                    'shares'    => $held,
                ];
            }
        }

        return $desired;
    }

    /**
     * @param  array<string,mixed>  $promotion
     * @param  array<string,mixed>  $terms
     * @param  array{kind: string, lead: VendorLead, unit: int, earner_id: int, amount: float, place: int, units: int, shares: int}  $want
     */
    private function award(string $key, array $promotion, array $terms, array $want): void
    {
        if ($want['amount'] <= 0) {
            return;
        }

        $lead = $want['lead'];
        $name = (string) ($promotion['name'] ?? $key);

        $notes = $want['kind'] === PromotionAward::KIND_PLACE
            ? sprintf('%s — launch bonus, place #%d (%s, system %d)', $name, $want['place'], $lead->public_ref, $want['unit'])
            : sprintf('%s — bonus pool: %d %s × %d %s from %s (sale #%d)',
                $name,
                $want['shares'], $want['shares'] === 1 ? 'share' : 'shares',
                $want['units'], $want['units'] === 1 ? 'system' : 'systems',
                $lead->public_ref, $want['place']);

        $ledger = CommissionLedger::create([
            'earner_id'   => $want['earner_id'],
            'source_type' => VendorLead::class,
            'source_id'   => $lead->id,
            'type'        => 'credit',
            'amount'      => $want['amount'],
            'status'      => 'pending',
            'clawback_eligible_until' => $terms['lock_days'] > 0
                ? Carbon::parse($lead->converted_at ?? now())->addDays($terms['lock_days'])->toDateString()
                : null,
            'notes' => $notes,
        ]);

        PromotionAward::create([
            'promotion_key'        => $key,
            'kind'                 => $want['kind'],
            'vendor_lead_id'       => $lead->id,
            'unit_index'           => $want['unit'],
            'earner_id'            => $want['earner_id'],
            'place'                => $want['place'],
            'units'                => $want['units'],
            'shares'               => $want['shares'],
            'amount'               => $want['amount'],
            'commission_ledger_id' => $ledger->id,
            'status'               => PromotionAward::STATUS_ACTIVE,
        ]);
    }

    /** Undo an award that the standings no longer support. */
    private function retire(PromotionAward $award): void
    {
        $ledger = $award->ledger;

        if ($ledger !== null && ! in_array($ledger->status, ['pending', 'voided'], true)) {
            // Approved or paid: reversing it is a clawback, which is an admin's call.
            $award->forceFill(['status' => PromotionAward::STATUS_NEEDS_CLAWBACK, 'voided_at' => now()])->save();

            Log::warning('Launch-special credit needs a clawback', [
                'award'  => $award->id,
                'ledger' => $ledger->id,
                'earner' => $award->earner_id,
                'amount' => (string) $award->amount,
            ]);

            return;
        }

        if ($ledger !== null && $ledger->status === 'pending') {
            $ledger->forceFill([
                'status' => 'voided',
                'notes'  => trim((string) $ledger->notes."\nVoided: the launch-special standings changed."),
            ])->save();
        }

        $award->forceFill(['status' => PromotionAward::STATUS_VOIDED, 'voided_at' => now()])->save();
    }
}
