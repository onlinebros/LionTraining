<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CrmContact;
use App\Models\CrmFollowup;
use App\Models\User;
use App\Services\CrmService;

class CrmDashboardController extends Controller
{
    public function __construct(private CrmService $crm) {}

    public function index()
    {
        $stats = $this->crm->dashboardStats();

        $followupsToday = CrmFollowup::with(['contact', 'assignee'])
            ->dueToday()
            ->orderBy('due_at')
            ->limit(10)
            ->get();

        $overdueFollowups = CrmFollowup::with(['contact', 'assignee'])
            ->overdue()
            ->orderBy('due_at')
            ->limit(10)
            ->get();

        $recentContacts = CrmContact::with(['owner', 'tags'])
            ->latest()
            ->limit(8)
            ->get();

        // Contacts by status for chart
        $byStatus = CrmContact::selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        // Top affiliates by contact count
        $topAffiliates = CrmContact::selectRaw('owner_id, count(*) as contact_count')
            ->with('owner')
            ->groupBy('owner_id')
            ->orderByDesc('contact_count')
            ->limit(5)
            ->get();

        return view('admin.crm.dashboard', compact(
            'stats', 'followupsToday', 'overdueFollowups',
            'recentContacts', 'byStatus', 'topAffiliates'
        ));
    }
}
