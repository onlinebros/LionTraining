<?php

namespace App\Services\ProductPartner;

use App\Models\User;
use App\Models\VendorLead;
use App\Support\ProductPartner;

/**
 * The numbers a vendor is shown about their own product line.
 *
 * Every query here goes through ProductPartner::scopeLeads(), so a vendor can
 * only ever be counting their own rows. Nothing in this class takes a vendor
 * slug without also taking the user it is being computed for — the pair is the
 * permission check, and splitting them is how one vendor ends up seeing
 * another's pipeline in a tile.
 */
class PartnerStatistics
{
    /**
     * @return array<string, mixed>
     */
    public function forVendor(User $user, string $vendor): array
    {
        $leads = fn () => ProductPartner::scopeLeads(VendorLead::query(), $user, $vendor);

        $converted = (clone $leads())->converted();

        // Units, not orders: a three-system order is three systems to the
        // people who have to build them, and it is what the revenue share is
        // computed per.
        $unitsSold = (int) (clone $converted)->sum('quantity');

        $prospectsTotal = (clone $leads())->count();
        $convertedCount = (clone $converted)->count();

        return [
            'vendor' => $vendor,

            // ── Pipeline ──────────────────────────────────────────────────
            'prospects' => [
                'total'      => $prospectsTotal,
                'open'       => (clone $leads())->whereIn('status', [
                    VendorLead::STATUS_NEW, VendorLead::STATUS_HANDED_OFF,
                ])->count(),
                'new'        => (clone $leads())->where('status', VendorLead::STATUS_NEW)->count(),
                'handed_off' => (clone $leads())->awaitingPurchase()->count(),
                'converted'  => $convertedCount,
                'lost'       => (clone $leads())->where('status', VendorLead::STATUS_LOST)->count(),
                'refunded'   => (clone $leads())->where('status', VendorLead::STATUS_REFUNDED)->count(),

                'last_30_days' => (clone $leads())->where('created_at', '>=', now()->subDays(30))->count(),
                'this_month'   => (clone $leads())->where('created_at', '>=', now()->startOfMonth())->count(),

                // Whole-number percent. Shown as context next to the counts,
                // never as a target: with a handful of orders it swings wildly.
                'conversion_rate' => $prospectsTotal > 0
                    ? (int) round(($convertedCount / $prospectsTotal) * 100)
                    : 0,
            ],

            // ── Sales ─────────────────────────────────────────────────────
            'sales' => [
                'orders'      => $convertedCount,
                'units'       => $unitsSold,
                'gross'       => (int) (clone $converted)->sum('amount_total'),
                'our_share'   => (int) (clone $converted)->sum('our_share_amount'),
                'units_30'    => (int) (clone $converted)->where('converted_at', '>=', now()->subDays(30))->sum('quantity'),
                'last_sale_at' => (clone $converted)->max('converted_at'),
            ],

            // ── The two counts the vendor asked for ───────────────────────
            'sales_force'    => $this->salesForce($user, $vendor),
            'approval_queue' => $this->approvalQueue($user, $vendor),
        ];
    }

    /**
     * How many of our partners are out selling this product.
     *
     * Two different numbers, because they answer two different questions and
     * the gap between them is the interesting part:
     *
     *   `active`   accounts holding this business line with the lights on. The
     *              size of the sales force on paper.
     *   `selling`  of those, how many have actually sourced a prospect. The
     *              size of it in practice.
     *
     * No names, ever. The vendor is being told how big the channel is, not who
     * is in it — that is our side of the relationship.
     */
    private function salesForce(User $user, string $vendor): array
    {
        $opportunities = $this->opportunitiesFor($vendor);

        if ($opportunities === []) {
            // A vendor with no business line pointed at it. Nothing to count,
            // and a zero here would read as "nobody is selling it".
            return ['active' => null, 'selling' => null, 'sourcing_30' => null];
        }

        $holders = User::query()
            ->where('is_active', true)
            ->whereHas('opportunityAssociations', fn ($q) => $q->whereIn('opportunity', $opportunities));

        // Distinct partners who have sourced at least one prospect for this
        // vendor. Counted off the leads, not the associations, so a partner
        // whose line was later changed still counts for the work they did.
        $sourcing = fn () => ProductPartner::scopeLeads(VendorLead::query(), $user, $vendor)
            ->whereNotNull('member_id');

        return [
            'active'      => (clone $holders)->count(),
            'selling'     => (int) $sourcing()->distinct()->count('member_id'),
            'sourcing_30' => (int) $sourcing()
                ->where('created_at', '>=', now()->subDays(30))
                ->distinct()->count('member_id'),
        ];
    }

    /**
     * Orders waiting on a decision at our end before the sale is final.
     *
     * The vendor sees this because it is the honest answer to "why is that
     * order not on my statement yet". Both states are ours to clear, not
     * theirs, so the screen says so rather than implying they should chase it.
     */
    private function approvalQueue(User $user, string $vendor): array
    {
        $leads = fn () => ProductPartner::scopeLeads(VendorLead::query(), $user, $vendor);

        $attribution = (clone $leads())->needsAttributionReview()->count();
        $address     = (clone $leads())->addressAwaitingReview()->count();

        return [
            'attribution' => $attribution,
            'address'     => $address,
            'total'       => $attribution + $address,
        ];
    }

    /**
     * The last few confirmed sales, for the glance on the dashboard.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, VendorLead>
     */
    public function recentSales(User $user, string $vendor, int $limit = 5)
    {
        return ProductPartner::scopeLeads(VendorLead::query()->with('member:id,name'), $user, $vendor)
            ->converted()
            ->orderByDesc('converted_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Prospects created per day, for the chart on the dashboard.
     *
     * Dense: every day in the window is present, including the zeroes, so the
     * shape is honest. A sparse series drawn as a line quietly closes the gaps
     * and turns a quiet fortnight into a gentle slope.
     *
     * @return array<int, array{date: string, prospects: int, sales: int}>
     */
    public function dailyActivity(User $user, string $vendor, int $days = 30): array
    {
        $from = now()->startOfDay()->subDays($days - 1);

        $created = ProductPartner::scopeLeads(VendorLead::query(), $user, $vendor)
            ->where('created_at', '>=', $from)
            ->get(['created_at'])
            ->countBy(fn ($lead) => $lead->created_at->toDateString());

        $sold = ProductPartner::scopeLeads(VendorLead::query(), $user, $vendor)
            ->converted()
            ->where('converted_at', '>=', $from)
            ->get(['converted_at'])
            ->countBy(fn ($lead) => $lead->converted_at->toDateString());

        $series = [];

        for ($day = $from->copy(); $day <= now()->startOfDay(); $day->addDay()) {
            $key = $day->toDateString();

            $series[] = [
                'date'      => $key,
                'label'     => $day->format('j M'),
                'prospects' => (int) ($created[$key] ?? 0),
                'sales'     => (int) ($sold[$key] ?? 0),
            ];
        }

        return $series;
    }

    /**
     * Which business lines sell this vendor's products.
     *
     * Read from config/opportunities.php rather than hard-coded, so a second
     * vendor with its own line needs no change here.
     *
     * @return array<int, string>
     */
    private function opportunitiesFor(string $vendor): array
    {
        return ProductPartner::opportunitiesForVendor($vendor);
    }
}
