<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CommissionClawback;
use App\Models\CommissionLedger;
use App\Services\CommissionService;
use Illuminate\Http\Request;
use InvalidArgumentException;
use RuntimeException;

class CommissionClawbackController extends Controller
{
    public function __construct(private CommissionService $commissionService) {}

    public function index(Request $request)
    {
        $query = CommissionClawback::with(['earner', 'initiatedBy', 'originalLedger'])
            ->latest();

        if ($request->filled('earner_id')) {
            $query->where('earner_id', $request->earner_id);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $clawbacks = $query->paginate(30)->withQueryString();
        return view('admin.commissions.clawbacks.index', compact('clawbacks'));
    }

    public function show(CommissionClawback $commissionClawback)
    {
        $commissionClawback->load(['earner', 'initiatedBy', 'originalLedger.commissionPlan', 'debitLedger']);
        return view('admin.commissions.clawbacks.show', ['clawback' => $commissionClawback]);
    }

    /**
     * Initiate a clawback against a specific credit ledger entry.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'ledger_id'      => 'required|exists:commission_ledger,id',
            'reason'         => 'required|string',
            'override_window' => 'boolean',
            'override_notes'  => 'nullable|string|required_if:override_window,true',
        ]);

        $ledger = CommissionLedger::findOrFail($data['ledger_id']);

        try {
            $clawback = $this->commissionService->initiateClawback(
                ledger:         $ledger,
                reason:         $data['reason'],
                initiatedBy:    auth()->user(),
                overrideWindow: $request->boolean('override_window'),
                overrideNotes:  $data['override_notes'] ?? null
            );
        } catch (RuntimeException | InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.commission-clawbacks.show', $clawback)
            ->with('success', 'Clawback applied successfully.');
    }

    /**
     * Reverse a previously applied clawback.
     */
    public function reverse(CommissionClawback $commissionClawback)
    {
        try {
            $this->commissionService->reverseClawback($commissionClawback);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Clawback reversed. Original credit restored.');
    }
}
