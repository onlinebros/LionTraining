<?php

namespace App\Http\Controllers\ProductPartner;

use App\Services\ProductPartner\PartnerStatement;
use App\Services\ProductPartner\PartnerStatistics;
use App\Support\Vendors;
use Illuminate\Http\Request;

/**
 * What the vendor sees when they log in: how their product is selling, how big
 * the channel behind it is, and where the money stands.
 */
class DashboardController extends PortalController
{
    public function __construct(
        private readonly PartnerStatistics $stats,
        private readonly PartnerStatement $statement,
    ) {}

    public function index(Request $request)
    {
        $user   = $this->subject($request);
        $vendor = $this->vendor($request);

        return view('product-partner.dashboard', $this->shell($request, $vendor) + [
            'stats'     => $this->stats->forVendor($user, $vendor),
            'activity'  => $this->stats->dailyActivity($user, $vendor, 30),
            'account'   => $this->statement->forVendor($user, $vendor),
            'products'  => (array) (Vendors::find($vendor)['products'] ?? []),

            /*
             * The most recent confirmed sales, as a strip. Full detail lives on
             * the Sales screen; this is the "has anything happened today"
             * glance that stops the vendor opening a ticket to ask.
             */
            'recent' => $this->stats->recentSales($user, $vendor, 5),
        ]);
    }
}
