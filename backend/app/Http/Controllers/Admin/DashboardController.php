<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;

class DashboardController extends Controller
{
    public function index()
    {
        // Members only. Unclaimed holding spots imported from a partner company
        // are positions, not users — counting them here would report a
        // membership that does not exist, and the number an admin reads off
        // this dashboard is the one that ends up in board decks.
        $stats = [
            'total_users' => User::query()->activated()->count(),
            'active_sponsors' => User::query()->activated()->whereHas('sponsorships')->count(),
            'total_sponsorships' => \App\Models\Sponsorship::count(),
            'pending_sponsorships' => \App\Models\Sponsorship::where('status', 'pending')->count(),
            // Summed from the counters on partner_companies, never counted.
            // This is the page an admin lands on straight after logging in, and
            // counting holding positions directly means reading every row an
            // import created — fifteen seconds before the dashboard renders.
            'unclaimed_spots' => (int) \App\Models\PartnerCompany::sum('unclaimed_spots'),
        ];

        $recentUsers = User::query()->activated()->latest()->limit(10)->get();

        return view('admin.dashboard', compact('stats', 'recentUsers'));
    }
}
