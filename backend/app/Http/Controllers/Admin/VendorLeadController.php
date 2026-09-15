<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\VendorLead;
use App\Services\Vendor\AddressCheck;
use App\Services\Vendor\PromotionTracker;
use App\Services\Vendor\VendorReferralService;
use App\Support\Vendors;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Vendor referral oversight.
 *
 * Three jobs: see the pipeline, close the loop by hand when a vendor sends no
 * webhook, and produce the export that tells the vendor who actually placed
 * each order.
 */
class VendorLeadController extends Controller
{
    public function __construct(
        private readonly VendorReferralService $referrals,
        private readonly PromotionTracker $promotions,
    ) {}

    public function index(Request $request)
    {
        $query = VendorLead::with('member')->latest();

        if ($vendor = $request->query('vendor')) {
            $query->where('vendor', $vendor);
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($attribution = $request->query('attribution')) {
            $query->where('attribution', $attribution);
        }

        if ($request->query('address') === 'review') {
            $query->addressAwaitingReview();
        }

        if ($search = $request->query('q')) {
            $query->where(function ($q) use ($search) {
                $q->where('public_ref', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%");
            });
        }

        return view('admin.vendor.leads', [
            'leads'   => $query->paginate(30)->withQueryString(),
            'vendors' => Vendors::all(),
            'filters' => $request->only(['vendor', 'status', 'q', 'attribution', 'address']),
            'stats'   => [
                'awaiting'  => VendorLead::awaitingPurchase()->count(),
                'converted' => VendorLead::converted()->count(),
                // Converted with no commission raised: either the vendor sent no
                // amount, or the rate is unset. Both need a human.
                'unpaid'    => VendorLead::uncommissioned()->count(),
                // Matched a partner on address alone. Nobody is paid until an
                // admin decides who the order counts for.
                'review'    => VendorLead::needsAttributionReview()->count(),
                // The buyer shipped to an address FedEx did not confirm.
                'address_review' => VendorLead::addressAwaitingReview()->count(),
            ],
        ]);
    }

    /**
     * What the vendor owes us, and what has been collected.
     *
     * Under the direct-key arrangement we take nothing at the point of sale —
     * every dollar lands on PlasmaGuard's account and our share is invoiced
     * separately. Without this screen "what are we owed" is answerable only by
     * reading every order by hand, which is how a revenue share quietly stops
     * being collected.
     */
    public function reconciliation(Request $request)
    {
        $vendor = $request->query('vendor', 'plasmaguard');

        $base = fn () => VendorLead::forVendor($vendor)->converted();

        $owed     = (clone $base())->whereNull('invoiced_at')->where('our_share_amount', '>', 0);
        $invoiced = (clone $base())->whereNotNull('invoiced_at')->whereNull('settled_at');
        $settled  = (clone $base())->whereNotNull('settled_at');

        return view('admin.vendor.reconciliation', [
            'vendor'  => $vendor,
            'vendors' => Vendors::all(),
            'orders'  => $owed->with('member')->latest('converted_at')->paginate(50),
            'totals'  => [
                // Sum in the database rather than in PHP: these are money
                // columns and the list is paginated, so summing the page would
                // silently under-report the moment there is a second one.
                'owed'      => (int) (clone $owed)->sum('our_share_amount'),
                'owed_n'    => (clone $owed)->count(),
                'invoiced'  => (int) (clone $invoiced)->sum('our_share_amount'),
                'invoiced_n' => (clone $invoiced)->count(),
                'settled'   => (int) (clone $settled)->sum('our_share_amount'),
                'settled_n' => (clone $settled)->count(),
            ],
        ]);
    }

    /** Every order holding a place in the running promotion, with the detail to check it. */
    public function promotion(Request $request)
    {
        $key = (string) ($request->query('promotion') ?: $this->promotions->currentKey());

        return view('admin.vendor.promotion', [
            'standings' => $key !== '' && $this->promotions->find($key) !== null
                ? $this->promotions->standings($key)
                : null,
        ]);
    }

    /**
     * Mark a batch as invoiced.
     *
     * The reference is required because an invoice nobody can find is not an
     * invoice — when PlasmaGuard query a line six weeks later, the answer has to
     * be a document number rather than "it is in the system somewhere".
     */
    public function invoice(Request $request)
    {
        $data = $request->validate([
            'reference' => ['required', 'string', 'max:100'],
            'ids'       => ['required', 'array', 'min:1'],
            'ids.*'     => ['integer'],
        ]);

        // Scoped to genuinely uninvoiced rows, so a resubmitted form or a stale
        // tab cannot overwrite the reference on something already billed.
        $marked = VendorLead::whereIn('id', $data['ids'])
            ->converted()
            ->whereNull('invoiced_at')
            ->update(['invoiced_at' => now(), 'invoice_reference' => $data['reference']]);

        return back()->with('status', "Marked {$marked} order(s) as invoiced under {$data['reference']}.");
    }

    /** Record that the vendor actually paid an invoice. */
    public function settle(Request $request)
    {
        $data = $request->validate([
            'reference' => ['required', 'string', 'max:100'],
        ]);

        $settled = VendorLead::where('invoice_reference', $data['reference'])
            ->whereNotNull('invoiced_at')
            ->whereNull('settled_at')
            ->update(['settled_at' => now()]);

        return back()->with('status', "Settled {$settled} order(s) on invoice {$data['reference']}.");
    }

    /**
     * Record fulfilment reported by the vendor.
     *
     * They ship, so tracking arrives from them by whatever means they use. Until
     * there is an API for it, an admin types it in — and the customer and the
     * partner can both see it, which is the point.
     */
    public function fulfil(Request $request, VendorLead $vendorLead)
    {
        $data = $request->validate([
            'carrier'         => ['nullable', 'string', 'max:40'],
            'tracking_number' => ['required', 'string', 'max:100'],
        ]);

        $vendorLead->forceFill([
            'carrier'         => $data['carrier'] ?: $vendorLead->carrier,
            'tracking_number' => $data['tracking_number'],
            'shipped_at'      => $vendorLead->shipped_at ?? now(),
        ])->save();

        return back()->with('status', "Tracking recorded for {$vendorLead->public_ref}.");
    }

    public function show(VendorLead $vendorLead)
    {
        return view('admin.vendor.lead-show', [
            'lead'   => $vendorLead->load([
                'member', 'crmContact', 'confirmedBy',
                'buyer.sponsor', 'creditedMember', 'earner', 'attributionResolvedBy', 'addressReviewedBy',
            ]),
            'vendor' => Vendors::find($vendorLead->vendor),
        ]);
    }

    /**
     * Close the loop by hand.
     *
     * This is the designed fallback for a vendor who will not send us webhooks,
     * not a workaround. The amount is typed from their confirmation, and
     * `confirmed_via` records that a person asserted it rather than a signature
     * proving it — which is exactly what a disputed commission turns on later.
     */
    public function convert(Request $request, VendorLead $vendorLead)
    {
        $data = $request->validate([
            'amount'           => ['required', 'numeric', 'min:0.01'],
            'currency'         => ['required', 'string', 'size:3'],
            'vendor_order_ref' => ['nullable', 'string', 'max:191'],
        ]);

        $this->referrals->convert($vendorLead, [
            'amount_total'     => (int) round(((float) $data['amount']) * 100),
            'currency'         => strtoupper($data['currency']),
            'vendor_order_ref' => $data['vendor_order_ref'] ?? null,
        ], VendorLead::VIA_MANUAL, $request->user());

        return back()->with('status', $vendorLead->fresh()?->commission_ledger_id
            ? "Marked {$vendorLead->public_ref} as converted and raised the commission."
            : "Marked {$vendorLead->public_ref} as converted. No commission was raised; see Credit & commission.");
    }

    /**
     * Settle who an order counts for.
     *
     * For an order held on an address-only match, or to correct an automatic
     * decision before any commission has been raised.
     */
    public function attribution(Request $request, VendorLead $vendorLead)
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in([VendorLead::ATTRIBUTION_SELF, VendorLead::ATTRIBUTION_CUSTOMER])],
        ]);

        try {
            $lead = $this->referrals->resolveAttribution($vendorLead, $data['decision'], $request->user());
        } catch (RuntimeException $e) {
            return back()->withErrors($e->getMessage());
        }

        $message = $lead->isOwnPurchase()
            ? "{$lead->public_ref} recorded as {$lead->buyer?->name}'s own purchase."
            : "{$lead->public_ref} recorded as a customer sale for {$lead->member?->name}.";

        return back()->with('status', $message.($lead->commission_ledger_id ? ' Commission raised.' : ''));
    }

    /**
     * An admin checked a delivery address the buyer confirmed but FedEx did not.
     *
     * Recorded rather than silently cleared, so it is clear later who looked at
     * the address before the system shipped.
     */
    public function addressReviewed(Request $request, VendorLead $vendorLead, AddressCheck $addresses)
    {
        if (! $vendorLead->needsAddressReview()) {
            return back()->withErrors('This order has no delivery address waiting to be checked.');
        }

        $addresses->markReviewed($vendorLead, $request->user());

        return back()->with('status', "Delivery address for {$vendorLead->public_ref} marked as checked.");
    }

    public function lost(VendorLead $vendorLead)
    {
        // Only a lead that never converted can be written off. A converted sale
        // is unwound by a refund, which has a clawback attached to it.
        if ($vendorLead->isConverted()) {
            return back()->withErrors('A converted lead cannot be marked lost — record a refund instead.');
        }

        $vendorLead->forceFill(['status' => VendorLead::STATUS_LOST])->save();

        return back()->with('status', "{$vendorLead->public_ref} marked as lost.");
    }

    /**
     * The handoff artefact: who placed each order, and which partner sourced it.
     *
     * Streamed rather than built in memory — this is the file that gets pulled
     * for a whole quarter at a time, and the point of it is that it can be sent
     * to a vendor who has no API.
     */
    public function export(Request $request): StreamedResponse
    {
        $query = VendorLead::with('member')->latest();

        if ($vendor = $request->query('vendor')) {
            $query->where('vendor', $vendor);
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        $filename = 'vendor-orders-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'wb');

            fputcsv($out, [
                'reference', 'vendor', 'product', 'status', 'captured_at', 'handed_off_at', 'converted_at',
                'customer_first_name', 'customer_last_name', 'customer_email', 'customer_phone', 'company',
                'address_line1', 'address_line2', 'city', 'state', 'postal_code', 'country',
                'quantity', 'subtotal', 'shipping', 'handling', 'tax', 'order_total', 'currency',
                'owed_to_us', 'invoice_reference', 'invoiced_at', 'settled_at',
                'vendor_order_ref', 'stripe_receipt_url', 'carrier', 'tracking_number', 'confirmed_via',
                'partner_name', 'partner_email', 'partner_referral_code',
            ]);

            $query->chunk(500, function ($chunk) use ($out) {
                foreach ($chunk as $lead) {
                    fputcsv($out, [
                        $lead->public_ref,
                        $lead->vendor,
                        $lead->product_key,
                        $lead->status,
                        optional($lead->created_at)->toDateTimeString(),
                        optional($lead->handed_off_at)->toDateTimeString(),
                        optional($lead->converted_at)->toDateTimeString(),
                        $lead->first_name,
                        $lead->last_name,
                        $lead->email,
                        $lead->phone,
                        $lead->company,
                        $lead->address_line1,
                        $lead->address_line2,
                        $lead->city,
                        $lead->state,
                        $lead->postal_code,
                        $lead->country,
                        $lead->quantity,
                        $lead->subtotal_amount !== null ? number_format($lead->subtotal_amount / 100, 2, '.', '') : null,
                        $lead->shipping_amount !== null ? number_format($lead->shipping_amount / 100, 2, '.', '') : null,
                        $lead->handling_amount !== null ? number_format($lead->handling_amount / 100, 2, '.', '') : null,
                        $lead->tax_amount !== null ? number_format($lead->tax_amount / 100, 2, '.', '') : null,
                        $lead->amountDecimal(),
                        $lead->currency,
                        $lead->our_share_amount !== null ? number_format($lead->our_share_amount / 100, 2, '.', '') : null,
                        $lead->invoice_reference,
                        optional($lead->invoiced_at)->toDateTimeString(),
                        optional($lead->settled_at)->toDateTimeString(),
                        $lead->vendor_order_ref,
                        $lead->stripe_receipt_url,
                        $lead->carrier,
                        $lead->tracking_number,
                        $lead->confirmed_via,
                        // Snapshot first: the whole point of this column is that
                        // it still names the right partner after an account is
                        // closed or a code reissued.
                        $lead->member?->name,
                        $lead->member?->email,
                        $lead->referral_code,
                    ]);
                }
            });

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}
