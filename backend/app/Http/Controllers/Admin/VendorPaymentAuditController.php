<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\VendorLead;
use App\Models\VendorPaymentAudit;
use App\Services\Vendor\VendorPaymentAuditor;
use App\Support\Vendors;
use Illuminate\Http\Request;
use Throwable;

/**
 * What the vendor's Stripe says happened to our orders, and what our key into
 * their account can see. Read-only on both sides; see VendorPaymentAuditor.
 */
class VendorPaymentAuditController extends Controller
{
    public function __construct(private readonly VendorPaymentAuditor $auditor) {}

    public function index(Request $request)
    {
        $vendor = $this->vendor($request);

        $query = VendorPaymentAudit::with('lead.member')
            ->where('vendor', $vendor)
            ->latest('intent_created_at');

        $verdict = $request->query('verdict');

        if ($verdict === 'attention') {
            $query->whereIn('verdict', VendorPaymentAudit::NEEDS_ATTENTION);
        } elseif ($verdict && isset(VendorPaymentAudit::VERDICTS[$verdict])) {
            $query->where('verdict', $verdict);
        }

        $access = null;
        $accessError = null;

        if ($this->auditor->skipReason($vendor) === null) {
            try {
                $access = $this->auditor->access($vendor);
            } catch (Throwable $e) {
                $accessError = $e->getMessage();
            }
        }

        return view('admin.vendor.payment-audit', [
            'vendor'      => $vendor,
            'vendors'     => Vendors::all(),
            'mode'        => Vendors::find($vendor)['stripe']['mode'] ?? 'test',
            'skipReason'  => $this->auditor->skipReason($vendor),
            'audits'      => $query->paginate(50)->withQueryString(),
            'verdict'     => $verdict,
            'counts'      => VendorPaymentAudit::where('vendor', $vendor)
                ->selectRaw('verdict, count(*) as n')->groupBy('verdict')->pluck('n', 'verdict'),
            'lastChecked' => VendorPaymentAudit::where('vendor', $vendor)->max('checked_at'),
            'access'      => $access,
            'accessError' => $accessError,
            'health'      => $this->auditor->health($vendor),
        ]);
    }

    /** Re-read every order now, and re-test the key's access. */
    public function refresh(Request $request)
    {
        $vendor = $this->vendor($request);

        if ($reason = $this->auditor->skipReason($vendor)) {
            return back()->withErrors(['audit' => "Cannot audit: {$reason}."]);
        }

        try {
            $result = $this->auditor->audit($vendor);
            $this->auditor->access($vendor, fresh: true);
        } catch (Throwable $e) {
            return back()->withErrors(['audit' => 'Stripe refused the audit: '.$e->getMessage()]);
        }

        $message = "Checked {$result['checked']} order(s) against ".Vendors::name($vendor).'\'s Stripe.';

        if ($result['events_error']) {
            $message .= ' Attempt history was unavailable: '.$result['events_error'];
        }

        return back()->with('status', $message);
    }

    /** Re-read one order, from its page. */
    public function refreshLead(VendorLead $vendorLead)
    {
        if (blank($vendorLead->provider_payment_intent_id)) {
            return back()->withErrors(['audit' => 'This order never reached payment, so Stripe has nothing on it.']);
        }

        if ($reason = $this->auditor->skipReason($vendorLead->vendor)) {
            return back()->withErrors(['audit' => "Cannot audit: {$reason}."]);
        }

        $audit = $this->auditor->auditLead($vendorLead);

        return back()->with('status', 'Stripe says: '.$audit->label().'.');
    }

    private function vendor(Request $request): string
    {
        $vendor = (string) $request->input('vendor', array_key_first(Vendors::all()) ?? '');

        abort_if(Vendors::find($vendor) === null, 404);

        return $vendor;
    }
}
