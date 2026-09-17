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
            'unclaimed_spots' => User::query()->holding()->count(),
        ];

        $recentUsers = User::query()->activated()->latest()->limit(10)->get();

        return view('admin.dashboard', compact('stats', 'recentUsers'));
    }
}
