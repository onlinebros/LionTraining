<?php

namespace App\Http\Controllers\ProductPartner;

use App\Models\VendorLead;
use App\Support\ProductPartner;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Confirmed orders, in full.
 *
 * The privacy line in this portal runs at the point of payment, and this is the
 * side of it where the vendor sees everything. They are the merchant of record:
 * they took the card, they owe the tax, they are shipping the goods to this
 * address, and their order desk is already emailed all of it. Withholding it
 * here would be theatre.
 *
 * A refunded order stays visible for the same reason — it was their sale, it is
 * on their books, and it moves the balance on the statement.
 */
class SalesController extends PortalController
{
    /** Orders whose money has actually moved; everything else is a prospect. */
    private const SOLD = [VendorLead::STATUS_CONVERTED, VendorLead::STATUS_REFUNDED];

    public function index(Request $request)
    {
        $vendor = $this->vendor($request);

        $orders = $this->sold($request, $vendor);

        if ($search = trim((string) $request->query('q'))) {
            $orders->where(function ($q) use ($search) {
                $like = '%'.$search.'%';
                $q->where('public_ref', 'ilike', $like)
                    ->orWhere('first_name', 'ilike', $like)
                    ->orWhere('last_name', 'ilike', $like)
                    ->orWhere('email', 'ilike', $like)
                    ->orWhere('company', 'ilike', $like)
                    ->orWhere('vendor_order_ref', 'ilike', $like);
            });
        }

        if (($status = $request->query('status')) && in_array($status, self::SOLD, true)) {
            $orders->where('status', $status);
        }

        // Unshipped first when they are filtering for it: the vendor's own
        // working list is "what do I still have to send".
        if ($request->query('unshipped') === '1') {
            $orders->whereNull('shipped_at');
        }

        return view('product-partner.sales.index', $this->shell($request, $vendor) + [
            'orders'  => $orders->with('member:id,name,referral_code')
                ->orderByDesc('converted_at')
                ->paginate(25)
                ->withQueryString(),
            'filters' => [
                'q'         => $search,
                'status'    => $status,
                'unshipped' => $request->query('unshipped') === '1',
            ],
            'unshippedCount' => $this->sold($request, $vendor)
                ->where('status', VendorLead::STATUS_CONVERTED)
                ->whereNull('shipped_at')
                ->count(),
        ]);
    }

    public function show(Request $request, VendorLead $vendorLead)
    {
        /*
         * Two checks, not one. The grant check stops another vendor's order
         * being opened by id; the status check stops an open prospect being
         * opened by id, which is the rule the list screens implement by
         * filtering and which a bare route model binding would walk straight
         * past.
         */
        if (! ProductPartner::covers($this->subject($request), $vendorLead->vendor, $vendorLead->product_key)) {
            throw new NotFoundHttpException();
        }

        if (! in_array($vendorLead->status, self::SOLD, true)) {
            throw new NotFoundHttpException();
        }

        $vendor = $vendorLead->vendor;

        return view('product-partner.sales.show', $this->shell($request, $vendor) + [
            // Name only. The partner's own contact details are our
            // relationship, not the vendor's, until there is a channel built
            // for it — see the "Sourced by" card.
            'order' => $vendorLead->load('member:id,name'),
        ]);
    }

    /**
     * The order book as a CSV.
     *
     * Streamed rather than built in memory: this is the file somebody exports
     * at the end of a quarter, and the row count is the one number nobody
     * thinks about until it is large.
     */
    public function export(Request $request): StreamedResponse
    {
        $vendor = $this->vendor($request);

        $query = $this->sold($request, $vendor)->with('member:id,name,referral_code');

        $filename = 'q3-'.$vendor.'-orders-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');

            fputcsv($out, [
                'Reference', 'Vendor order ref', 'Status', 'Confirmed', 'Product', 'Quantity',
                'Customer', 'Company', 'Email', 'Phone',
                'Address 1', 'Address 2', 'City', 'State', 'Postal code', 'Country',
                'Currency', 'Subtotal', 'Shipping', 'Handling', 'Tax', 'Order total',
                'Owed to Quantum 3', 'Invoice', 'Invoiced', 'Settled',
                'Carrier', 'Tracking', 'Shipped',
                'Referred by', 'Referral code',
            ]);

            $money = fn ($minor) => $minor === null ? '' : number_format(((int) $minor) / 100, 2, '.', '');

            $query->orderBy('converted_at')->chunk(200, function ($orders) use ($out, $money) {
                foreach ($orders as $order) {
                    fputcsv($out, [
                        $order->public_ref,
                        $order->vendor_order_ref,
                        $order->status,
                        optional($order->converted_at)->toDateTimeString(),
                        $order->productName(),
                        $order->quantity,
                        $order->fullName(),
                        $order->company,
                        $order->email,
                        $order->phone,
                        $order->address_line1,
                        $order->address_line2,
                        $order->city,
                        $order->state,
                        $order->postal_code,
                        $order->country,
                        $order->currency,
                        $money($order->subtotal_amount),
                        $money($order->shipping_amount),
                        $money($order->handling_amount),
                        $money($order->tax_amount),
                        $money($order->amount_total),
                        $money($order->our_share_amount),
                        $order->invoice_reference,
                        optional($order->invoiced_at)->toDateString(),
                        optional($order->settled_at)->toDateString(),
                        $order->carrier,
                        $order->tracking_number,
                        optional($order->shipped_at)->toDateString(),
                        $order->member?->name,
                        $order->referral_code,
                    ]);
                }
            });

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /** Every order for this vendor whose money has moved, scoped to the grants. */
    private function sold(Request $request, string $vendor)
    {
        return ProductPartner::scopeLeads(VendorLead::query(), $this->subject($request), $vendor)
            ->whereIn('status', self::SOLD);
    }
}
