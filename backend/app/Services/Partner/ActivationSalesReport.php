<?php

namespace App\Services\Partner;

use App\Models\PartnerCompany;
use App\Models\Subscription;
use App\Models\User;
use App\Models\VendorLead;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Who actually came through a partner's activation, and what they have sold.
 *
 * An import puts a million positions in the tree; this answers the only
 * question anybody asks about them afterwards, which is how many turned into
 * people and whether those people are selling anything. The spots board counts
 * positions — see HoldingSpotController — and deliberately says nothing about
 * money. This is the other half.
 *
 * Two things it is careful about.
 *
 * **It joins sales on `credited_member_id`, not `member_id`.** That column is
 * the system's own answer to "whose sale is this" (see PurchaseAttribution): a
 * partner's own purchase counts for them even though the commission is paid to
 * their sponsor. `member_id` is only whose link was used, which is a different
 * question and the wrong one here.
 *
 * **It follows a merge.** A founder who claimed their imported position and
 * then folded it into an existing account still came through the activation,
 * but every sale they make afterwards belongs to the surviving account. So
 * sales and membership are looked up against
 * `coalesce(merged_into_user_id, id)` and the row says where they went. Without
 * that, the people most likely to be selling are the ones reading as zero.
 */
class ActivationSalesReport
{
    /** Positions still standing as their own account. */
    public const STATE_ACTIVATED = 'activated';

    /** Claimed, then folded into an account the person already had. */
    public const STATE_MERGED = 'merged';

    public const STATE_ALL = 'all';

    public const SORT_REVENUE = 'revenue';
    public const SORT_ORDERS  = 'orders';
    public const SORT_RECENT  = 'recent';

    /**
     * The company this report opens on: the one with the most positions.
     *
     * iHub today, by a factor of twenty thousand. Deliberately not hard-coded
     * to the `ihub` slug — the report is about partner activations in general,
     * and the largest import is the one somebody opening this screen means.
     */
    public function defaultCompany(): ?PartnerCompany
    {
        return PartnerCompany::query()
            ->orderByDesc('total_spots')
            ->orderBy('name')
            ->first();
    }

    /**
     * One row per activated position, with its sales and membership alongside.
     *
     * @param  array{state?:string, sort?:string, q?:string, with_sales?:bool}  $filters
     */
    public function query(?int $companyId, array $filters = []): Builder
    {
        $state = $filters['state'] ?? self::STATE_ACTIVATED;
        $sort  = $filters['sort'] ?? self::SORT_REVENUE;

        // Who this row's money belongs to: itself, or the account it merged into.
        $earner = 'coalesce(users.merged_into_user_id, users.id)';

        $query = User::query()
            ->whereNotNull('users.partner_company_id')
            ->when($companyId, fn ($q) => $q->where('users.partner_company_id', $companyId))
            ->when($state === self::STATE_ACTIVATED, fn ($q) => $q->activated())
            ->when($state === self::STATE_MERGED, fn ($q) => $q->merged())
            // 'all' still excludes holding: a position nobody claimed is not a
            // person who came through the activation, which is what this counts.
            ->when($state === self::STATE_ALL, fn ($q) => $q->whereIn(
                'users.account_status',
                [User::ACCOUNT_ACTIVE, User::ACCOUNT_MERGED],
            ))
            ->when(($filters['q'] ?? '') !== '', function ($q) use ($filters) {
                $term = trim((string) $filters['q']);

                // The partner's own id first and exactly, the way an admin types
                // it off a support call, and an index lookup rather than a
                // substring scan.
                $q->where(fn ($w) => $w
                    ->where('users.external_user_id', $term)
                    ->orWhere('users.name', 'ilike', "%{$term}%")
                    ->orWhere('users.email', 'ilike', "%{$term}%"));
            })
            ->select([
                'users.id',
                'users.name',
                'users.email',
                'users.referral_code',
                'users.external_user_id',
                'users.account_status',
                'users.claimed_at',
                'users.merged_into_user_id',
                'users.billing_exempt',
                'users.partner_company_id',
            ])
            ->selectRaw('coalesce(sales.customer_orders, 0)  AS customer_orders')
            ->selectRaw('coalesce(sales.customer_revenue, 0) AS customer_revenue')
            ->selectRaw('coalesce(sales.own_orders, 0)       AS own_orders')
            ->selectRaw('coalesce(sales.own_revenue, 0)      AS own_revenue')
            ->leftJoinSub(
                $this->salesByMember(),
                'sales',
                fn ($join) => $join->on('sales.credited_member_id', '=', DB::raw($earner)),
            )
            ->with('partnerCompany:id,name,slug', 'mergedInto:id,name,email');

        $this->addMembership($query, $earner);

        return match ($sort) {
            self::SORT_ORDERS => $query
                ->orderByRaw('coalesce(sales.customer_orders, 0) DESC')
                ->orderByRaw('coalesce(sales.customer_revenue, 0) DESC')
                ->orderBy('users.id'),
            self::SORT_RECENT => $query
                ->orderByRaw('users.claimed_at DESC NULLS LAST')
                ->orderBy('users.id'),
            default => $query
                ->orderByRaw('coalesce(sales.customer_revenue, 0) DESC')
                ->orderByRaw('coalesce(sales.customer_orders, 0) DESC')
                ->orderByRaw('users.claimed_at DESC NULLS LAST')
                ->orderBy('users.id'),
        };
    }

    /**
     * The headline numbers, over the same set the table pages through.
     *
     * Counted from `users` rather than from PartnerCompany's cached counters,
     * because those count positions and this counts people — and because the
     * claimed set is thousands at most, so an aggregate over it is cheap even
     * when the company holds a million rows. The one number taken from the
     * counters is how many positions were imported in the first place, which is
     * the aggregate they exist to avoid.
     *
     * @return array{imported:int, unclaimed:int, activated:int, merged:int,
     *               selling:int, orders:int, revenue:int, own_orders:int,
     *               own_revenue:int, paying:int, unpriced_orders:int}
     */
    public function summary(?int $companyId): array
    {
        $positions = PartnerCompany::query()
            ->when($companyId, fn ($q) => $q->whereKey($companyId))
            ->selectRaw('coalesce(sum(total_spots), 0) AS total')
            ->selectRaw('coalesce(sum(unclaimed_spots), 0) AS unclaimed')
            ->first();

        $earner = 'coalesce(users.merged_into_user_id, users.id)';

        $people = User::query()
            ->whereNotNull('users.partner_company_id')
            ->when($companyId, fn ($q) => $q->where('users.partner_company_id', $companyId))
            ->whereIn('users.account_status', [User::ACCOUNT_ACTIVE, User::ACCOUNT_MERGED])
            ->leftJoinSub(
                $this->salesByMember(),
                'sales',
                fn ($join) => $join->on('sales.credited_member_id', '=', DB::raw($earner)),
            )
            ->selectRaw('count(*) FILTER (WHERE users.account_status = ?) AS activated', [User::ACCOUNT_ACTIVE])
            ->selectRaw('count(*) FILTER (WHERE users.account_status = ?) AS merged', [User::ACCOUNT_MERGED])
            ->selectRaw('count(*) FILTER (WHERE coalesce(sales.customer_orders, 0) > 0) AS selling')
            ->selectRaw('coalesce(sum(sales.customer_orders), 0)  AS orders')
            ->selectRaw('coalesce(sum(sales.customer_revenue), 0) AS revenue')
            ->selectRaw('coalesce(sum(sales.own_orders), 0)       AS own_orders')
            ->selectRaw('coalesce(sum(sales.own_revenue), 0)      AS own_revenue')
            ->selectRaw('coalesce(sum(sales.unpriced_orders), 0)  AS unpriced_orders')
            ->first();

        $total     = (int) ($positions->total ?? 0);
        $unclaimed = (int) ($positions->unclaimed ?? 0);

        return [
            'imported'        => $total,
            'unclaimed'       => $unclaimed,
            'activated'       => (int) ($people->activated ?? 0),
            'merged'          => (int) ($people->merged ?? 0),
            'selling'         => (int) ($people->selling ?? 0),
            'orders'          => (int) ($people->orders ?? 0),
            'revenue'         => (int) ($people->revenue ?? 0),
            'own_orders'      => (int) ($people->own_orders ?? 0),
            'own_revenue'     => (int) ($people->own_revenue ?? 0),
            'paying'          => $this->payingCount($companyId),
            'unpriced_orders' => (int) ($people->unpriced_orders ?? 0),
        ];
    }

    /**
     * Confirmed vendor orders, totalled per the member they count for.
     *
     * Converted only. A refunded order leaves `status = 'refunded'` and drops
     * out by itself, which is the behaviour a sales report wants — the money
     * went back.
     *
     * Own purchases are split out rather than dropped. They are that partner's
     * sale by the rule in PurchaseAttribution, but they are not them selling to
     * anybody, and a report that silently folded the two together would show a
     * partner who bought one unit as a partner who sold one.
     *
     * `amount_total` is the vendor's confirmed total in minor units and can be
     * null on an order an admin marked converted by hand, so those are counted
     * separately: revenue that is missing is better said out loud than summed
     * as zero.
     */
    private function salesByMember(): QueryBuilder
    {
        $notSelf = "attribution IS DISTINCT FROM '".VendorLead::ATTRIBUTION_SELF."'";
        $isSelf  = "attribution = '".VendorLead::ATTRIBUTION_SELF."'";

        return DB::table('vendor_leads')
            ->select('credited_member_id')
            ->selectRaw("count(*) FILTER (WHERE {$notSelf}) AS customer_orders")
            ->selectRaw("coalesce(sum(amount_total) FILTER (WHERE {$notSelf}), 0) AS customer_revenue")
            ->selectRaw("count(*) FILTER (WHERE {$isSelf}) AS own_orders")
            ->selectRaw("coalesce(sum(amount_total) FILTER (WHERE {$isSelf}), 0) AS own_revenue")
            ->selectRaw('count(*) FILTER (WHERE amount_total IS NULL) AS unpriced_orders')
            ->where('status', VendorLead::STATUS_CONVERTED)
            ->whereNull('deleted_at')
            ->whereNotNull('credited_member_id')
            ->groupBy('credited_member_id');
    }

    /**
     * The membership state, read from the subscription that entitles them.
     *
     * Through the model's own `entitling()` scope, grace window and all, so
     * this screen cannot drift from what the gate actually lets people into.
     *
     * The trigger comes back with it because "has a subscription" is not the
     * same answer as "is paying" here: a partner who chose to wait for
     * commissions sits at `trialing` with no card charged, and is entitled
     * without having bought anything. See the enrollment options in
     * memory-bank/decisions.md.
     */
    private function addMembership(Builder $query, string $earner): void
    {
        foreach (['status', 'billing_trigger'] as $column) {
            $query->selectSub(
                Subscription::query()
                    ->entitling()
                    ->whereRaw("subscriptions.user_id = {$earner}")
                    ->orderByDesc('current_period_end')
                    ->limit(1)
                    ->select($column),
                'membership_'.$column,
            );
        }
    }

    /** How many of them are on a subscription that is actually billing. */
    private function payingCount(?int $companyId): int
    {
        return User::query()
            ->whereNotNull('users.partner_company_id')
            ->when($companyId, fn ($q) => $q->where('users.partner_company_id', $companyId))
            ->whereIn('users.account_status', [User::ACCOUNT_ACTIVE, User::ACCOUNT_MERGED])
            ->whereExists(fn ($q) => $q
                ->from('subscriptions')
                ->whereRaw('subscriptions.user_id = coalesce(users.merged_into_user_id, users.id)')
                ->where('subscriptions.status', Subscription::STATUS_ACTIVE))
            ->count();
    }

    /**
     * How a row's membership reads on the screen and in the export.
     *
     * @return array{label:string, tone:string}
     */
    public function membershipLabel(User $row): array
    {
        $status  = $row->membership_status;
        $trigger = $row->membership_billing_trigger;

        if ($status === Subscription::STATUS_ACTIVE) {
            return ['label' => 'Paying', 'tone' => 'success'];
        }

        if ($status === Subscription::STATUS_TRIALING && $trigger === Subscription::TRIGGER_COMMISSION) {
            return ['label' => 'Waiting on commissions', 'tone' => 'info'];
        }

        if ($status === Subscription::STATUS_TRIALING) {
            return ['label' => 'Trialing', 'tone' => 'info'];
        }

        if ($status !== null) {
            return ['label' => ucfirst(str_replace('_', ' ', (string) $status)), 'tone' => 'warning'];
        }

        // Checked after the subscription, not before: an exempt account that
        // also holds a real one should read as the subscription it has.
        if ($row->billing_exempt) {
            return ['label' => 'Comped', 'tone' => 'secondary'];
        }

        return ['label' => 'None', 'tone' => 'muted'];
    }
}
