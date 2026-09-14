<?php

namespace App\Http\Controllers;

use App\Models\VendorLead;
use App\Support\Vendors;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A partner's own vendor-product pipeline: their share links, and what each
 * lead they sourced has done since.
 */
class MemberVendorLeadController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $leads = VendorLead::where('member_id', $user->id)
            ->latest()
            ->paginate(20);

        $counts = VendorLead::where('member_id', $user->id)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        // Earned is taken from the leads' own confirmed totals rather than from
        // the commission ledger, so this screen still reads correctly before a
        // payout run has approved anything.
        $earned = VendorLead::where('member_id', $user->id)
            ->converted()
            ->get()
            ->sum(fn (VendorLead $lead) => ($lead->amount_total / 100) * Vendors::commissionRate((string) $lead->vendor));

        return view('member.vendor.leads', [
            'user'    => $user,
            'leads'   => $leads,
            'counts'  => $counts,
            'earned'  => $earned,
            'vendors' => collect(Vendors::all())->filter(fn ($v, $slug) => Vendors::enabled($slug)),
        ]);
    }

    public function show(Request $request, VendorLead $lead)
    {
        // Scoped by ownership, not just by id — a partner must not be able to
        // read another partner's customer by guessing a primary key.
        if ($lead->member_id !== $request->user()->id) {
            throw new NotFoundHttpException();
        }

        return view('member.vendor.lead-show', [
            'lead'   => $lead->load('crmContact'),
            'vendor' => Vendors::find($lead->vendor),
        ]);
    }
}
