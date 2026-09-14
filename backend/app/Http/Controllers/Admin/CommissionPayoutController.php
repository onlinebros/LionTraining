<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\BillingException;
use App\Http\Controllers\Controller;
use App\Models\CommissionPayout;
use App\Models\User;
use App\Services\CommissionService;
use App\Services\Stripe\StripePayoutService;
use Illuminate\Http\Request;
use RuntimeException;

class CommissionPayoutController extends Controller
{
    public function __construct(
        private CommissionService $commissionService,
        private StripePayoutService $stripePayouts,
    ) {}

    public function index(Request $request)
    {
        $query = CommissionPayout::with(['earner', 'processedBy'])->latest();

        if ($request->filled('earner_id')) {
            $query->where('earner_id', $request->earner_id);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $payouts = $query->paginate(30)->withQueryString();
        $users   = User::orderBy('name')->get(['id', 'name', 'email']);

        return view('admin.commissions.payouts.index', compact('payouts', 'users'));
    }

    public function create()
    {
        $users = User::orderBy('name')->get(['id', 'name', 'email']);
        return view('admin.commissions.payouts.create', compact('users'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'earner_id'    => 'required|exists:users,id',
            'period_start' => 'nullable|date',
            'period_end'   => 'nullable|date|after_or_equal:period_start',
            'notes'        => 'nullable|string',
        ]);

        $earner = User::findOrFail($data['earner_id']);

        try {
            $payout = $this->commissionService->createPayout(
                earner:       $earner,
                periodStart:  isset($data['period_start']) ? \Carbon\Carbon::parse($data['period_start']) : null,
                periodEnd:    isset($data['period_end'])   ? \Carbon\Carbon::parse($data['period_end'])   : null,
                notes:        $data['notes'] ?? null,
                processedBy:  auth()->user()
            );
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.commission-payouts.show', $payout)
            ->with('success', 'Payout batch created.');
    }

    public function show(CommissionPayout $commissionPayout)
    {
        $commissionPayout->load(['earner', 'processedBy', 'ledgerEntries.commissionPlan']);

        return view('admin.commissions.payouts.show', [
            'payout'                => $commissionPayout,
            'transferBlockedReason' => $this->stripePayouts->blockingReason($commissionPayout),
        ]);
    }

    /**
     * Pay this payout through Stripe Connect.
     *
     * Moves real money, so the service re-checks the partner's account with
     * Stripe first and marks the payout paid only once the transfer exists.
     */
    public function sendTransfer(CommissionPayout $commissionPayout)
    {
        try {
            $payout = $this->stripePayouts->send($commissionPayout, auth()->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Paid {$payout->earner->name} through Stripe ({$payout->stripe_transfer_id}).");
    }

    public function reverseTransfer(Request $request, CommissionPayout $commissionPayout)
    {
        $data = $request->validate(['reason' => 'nullable|string|max:255']);

        try {
            $this->stripePayouts->reverse($commissionPayout, $data['reason'] ?? null);
        } catch (BillingException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Transfer reversed.');
    }

    public function approve(CommissionPayout $commissionPayout)
    {
        try {
            $this->commissionService->approvePayout($commissionPayout, auth()->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Payout approved.');
    }

    public function markPaid(Request $request, CommissionPayout $commissionPayout)
    {
        $data = $request->validate([
            'payment_reference' => 'required|string|max:255',
            'payment_method'    => 'nullable|string|max:100',
            'notes'             => 'nullable|string',
        ]);

        try {
            $this->commissionService->markPayoutPaid(
                payout:           $commissionPayout,
                paymentReference: $data['payment_reference'],
                paymentMethod:    $data['payment_method'] ?? null,
                notes:            $data['notes'] ?? null,
                processedBy:      auth()->user()
            );
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Payout marked as paid.');
    }

    public function cancel(CommissionPayout $commissionPayout)
    {
        try {
            $this->commissionService->cancelPayout($commissionPayout);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.commission-payouts.index')
            ->with('success', 'Payout cancelled and entries released.');
    }
}
