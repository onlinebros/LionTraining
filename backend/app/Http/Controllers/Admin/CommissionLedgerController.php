<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CommissionLedger;
use App\Models\CommissionPlan;
use App\Models\User;
use App\Services\CommissionService;
use Illuminate\Http\Request;

class CommissionLedgerController extends Controller
{
    public function __construct(private CommissionService $commissionService) {}

    public function index(Request $request)
    {
        $query = CommissionLedger::with(['earner', 'commissionPlan', 'payout'])
            ->latest();

        if ($request->filled('earner_id')) {
            $query->where('earner_id', $request->earner_id);
        }
        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->from);
        }
        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->to);
        }

        $entries = $query->paginate(50)->withQueryString();
        $users   = User::orderBy('name')->get(['id', 'name', 'email']);
        $plans   = CommissionPlan::orderBy('name')->get(['id', 'name']);

        return view('admin.commissions.ledger.index', compact('entries', 'users', 'plans'));
    }

    /**
     * Manually record a commission credit (admin entry, not from a sale trigger).
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'earner_id'          => 'required|exists:users,id',
            'commission_plan_id' => 'required|exists:commission_plans,id',
            'amount'             => 'required|numeric|min:0.01',
            'notes'              => 'nullable|string',
        ]);

        CommissionLedger::create([
            'earner_id'          => $data['earner_id'],
            'commission_plan_id' => $data['commission_plan_id'],
            'type'               => 'credit',
            'amount'             => $data['amount'],
            'status'             => 'pending',
            'notes'              => $data['notes'] ?? null,
            'created_by'         => auth()->id(),
        ]);

        return back()->with('success', 'Manual commission credit recorded.');
    }

    public function approve(CommissionLedger $ledger)
    {
        if ($ledger->status !== 'pending') {
            return back()->with('error', 'Only pending entries can be approved.');
        }

        $ledger->update(['status' => 'approved']);
        return back()->with('success', 'Ledger entry approved.');
    }

    public function void(CommissionLedger $ledger)
    {
        if ($ledger->isPaid()) {
            return back()->with('error', 'Cannot void a paid ledger entry.');
        }

        $ledger->update(['status' => 'voided']);
        return back()->with('success', 'Ledger entry voided.');
    }
}
