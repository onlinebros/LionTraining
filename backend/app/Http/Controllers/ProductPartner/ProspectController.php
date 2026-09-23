<?php

namespace App\Http\Controllers\ProductPartner;

use App\Models\VendorLead;
use App\Services\ProductPartner\PartnerStatistics;
use App\Support\ProductPartner;
use App\Support\Vendors;
use Illuminate\Http\Request;

/**
 * The pipeline, in numbers only.
 *
 * Deliberately not a list. An open prospect is a customer one of our partners
 * found and has not closed yet, and handing the vendor that person's name,
 * email and address would let them close it themselves — or let a competitor's
 * sales team work a list our partners built. The vendor gets the shape of the
 * funnel, which is what tells them whether the channel is working; the moment
 * an order is paid for, the full record appears on the Sales screen, because
 * from that point they are the merchant of record and it is their customer too.
 *
 * Nothing on this page is per-prospect, and nothing on it names a partner
 * either. Every figure is a count, a total or a median over a bucket — a
 * product, a week, a status — and none of them is a row with the name taken
 * off.
 */
class ProspectController extends PortalController
{
    public function __construct(private readonly PartnerStatistics $stats) {}

    public function index(Request $request)
    {
        $user   = $this->subject($request);
        $vendor = $this->vendor($request);

        return view('product-partner.prospects.index', $this->shell($request, $vendor) + [
            'stats'    => $this->stats->forVendor($user, $vendor),
            'activity' => $this->stats->dailyActivity($user, $vendor, 30),
            'weekly'   => $this->weekly($user, $vendor),
            'byProduct' => $this->byProduct($user, $vendor),
            'timeToSale' => $this->timeToSale($user, $vendor),
        ]);
    }

    /**
     * Prospects created and sales closed, by week, for the last twelve.
     *
     * Weeks rather than days at this range: a daily series over a quarter is
     * mostly noise, and the question the page answers is "is this growing".
     *
     * @return array<int, array<string, mixed>>
     */
    private function weekly($user, string $vendor): array
    {
        $from = now()->startOfWeek()->subWeeks(11);

        $created = ProductPartner::scopeLeads(VendorLead::query(), $user, $vendor)
            ->where('created_at', '>=', $from)
            ->get(['created_at'])
            ->countBy(fn ($lead) => $lead->created_at->startOfWeek()->toDateString());

        $sold = ProductPartner::scopeLeads(VendorLead::query(), $user, $vendor)
            ->converted()
            ->where('converted_at', '>=', $from)
            ->get(['converted_at', 'quantity'])
            ->groupBy(fn ($lead) => $lead->converted_at->startOfWeek()->toDateString());

        $weeks = [];

        for ($week = $from->copy(); $week <= now()->startOfWeek(); $week->addWeek()) {
            $key = $week->toDateString();

            $weeks[] = [
                'week'      => $key,
                'label'     => $week->format('j M'),
                'prospects' => (int) ($created[$key] ?? 0),
                'sales'     => $sold->has($key) ? $sold[$key]->count() : 0,
                'units'     => $sold->has($key) ? (int) $sold[$key]->sum('quantity') : 0,
            ];
        }

        return $weeks;
    }

    /**
     * The funnel per product, for a vendor selling more than one.
     *
     * @return array<int, array<string, mixed>>
     */
    private function byProduct($user, string $vendor): array
    {
        $rows = ProductPartner::scopeLeads(VendorLead::query(), $user, $vendor)
            ->selectRaw('product_key, status, COUNT(*) as total, SUM(quantity) as units')
            ->groupBy('product_key', 'status')
            ->get();

        $products = [];

        foreach ($rows as $row) {
            $key = $row->product_key;

            $products[$key] ??= [
                'key'       => $key,
                'name'      => Vendors::product($vendor, $key)['name'] ?? $key,
                'total'     => 0,
                'open'      => 0,
                'converted' => 0,
                'units'     => 0,
            ];

            $products[$key]['total'] += (int) $row->total;

            if (in_array($row->status, [VendorLead::STATUS_NEW, VendorLead::STATUS_HANDED_OFF], true)) {
                $products[$key]['open'] += (int) $row->total;
            }

            if ($row->status === VendorLead::STATUS_CONVERTED) {
                $products[$key]['converted'] += (int) $row->total;
                $products[$key]['units']     += (int) $row->units;
            }
        }

        return array_values($products);
    }

    /**
     * Median days from a prospect being captured to it being paid for.
     *
     * Median rather than mean: one order that sat for four months while a
     * building manager got a budget approved would drag an average somewhere
     * nobody recognises.
     */
    private function timeToSale($user, string $vendor): ?float
    {
        $days = ProductPartner::scopeLeads(VendorLead::query(), $user, $vendor)
            ->converted()
            ->whereNotNull('converted_at')
            ->get(['created_at', 'converted_at'])
            ->map(fn ($lead) => $lead->created_at->diffInHours($lead->converted_at) / 24)
            ->sort()
            ->values();

        if ($days->isEmpty()) {
            return null;
        }

        $middle = intdiv($days->count(), 2);

        return $days->count() % 2 === 1
            ? round($days[$middle], 1)
            : round(($days[$middle - 1] + $days[$middle]) / 2, 1);
    }
}
