<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;

class DashboardController extends Controller
{
    public function index()
    {
        $stats = [
            'total_users' => User::count(),
            'active_sponsors' => User::whereHas('sponsorships')->count(),
            'total_sponsorships' => \App\Models\Sponsorship::count(),
            'pending_sponsorships' => \App\Models\Sponsorship::where('status', 'pending')->count(),
        ];

        $recentUsers = User::latest()->limit(10)->get();

        return view('admin.dashboard', compact('stats', 'recentUsers'));
    }
}
