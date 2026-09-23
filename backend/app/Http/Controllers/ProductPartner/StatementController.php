<?php

namespace App\Http\Controllers\ProductPartner;

use App\Models\ProductPartnerPayment;
use App\Services\ProductPartner\PartnerStatement;
use App\Support\ProductPartner;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The centralised account between the vendor and Quantum 3.
 *
 * One page, both directions: every sale that earned us a share, which of them
 * are on an invoice, and every payment the vendor says they have made against
 * it. There is no second copy of this in a spreadsheet, which is the point.
 *
 * The vendor may add a payment and nothing else. They cannot mark their own
 * debt settled — a balance the debtor can move is a balance only one side
 * believes — but they are never stuck waiting for us to notice a wire either.
 * Their claim goes on the record immediately, shown as pending until an admin
 * confirms it.
 */
class StatementController extends PortalController
{
    public function __construct(private readonly PartnerStatement $statement) {}

    public function index(Request $request)
    {
        $user   = $this->subject($request);
        $vendor = $this->vendor($request);

        return view('product-partner.statement.index', $this->shell($request, $vendor) + [
            'totals'     => $this->statement->forVendor($user, $vendor),
            'invoices'   => $this->statement->invoices($user, $vendor),
            'uninvoiced' => $this->statement->uninvoicedOrders($user, $vendor),
            'payments'   => $this->statement->payments($vendor),
            'openInvoices' => $this->statement->openInvoiceReferences($user, $vendor),
        ]);
    }

    /**
     * Record a payment the vendor says they have made.
     *
     * Money is taken as decimal dollars from the form and stored in minor
     * units, because everything else in this schema is minor units and one
     * column holding dollars is how a $3,000 payment becomes $30.
     */
    public function storePayment(Request $request)
    {
        $vendor = $this->vendor($request);

        /*
         * "View as" is for looking, not for doing. An admin filing a payment
         * while wearing the vendor's face produces a row that reads as the
         * vendor's claim and is not one, on the single screen whose whole
         * purpose is that both companies trust the same numbers.
         *
         * Admins settle invoices on the admin side, under their own name.
         */
        if (ProductPartner::isViewingAsAnother($request->user())) {
            return back()->withErrors([
                'error' => 'You are viewing this portal as '.$this->subject($request)->name
                    .'. Stop viewing as them to act as yourself, or settle the invoice from the admin side.',
            ]);
        }

        $data = $request->validate([
            // Two decimal places, and a ceiling that is high enough for a
            // quarter's settlement but low enough that a slipped decimal point
            // is refused rather than recorded.
            'amount'            => ['required', 'numeric', 'min:0.01', 'max:10000000', 'decimal:0,2'],
            'paid_on'           => ['required', 'date', 'before_or_equal:today'],
            'method'            => ['nullable', Rule::in(['wire', 'ach', 'check', 'card', 'other'])],
            'reference'         => ['nullable', 'string', 'max:100'],
            'invoice_reference' => ['nullable', 'string', 'max:100'],
            'note'              => ['nullable', 'string', 'max:2000'],
        ]);

        /*
         * The invoice reference is checked against the ones actually open for
         * this vendor rather than taken as typed. A payment filed against an
         * invoice that does not exist reconciles to nothing and surfaces weeks
         * later as an argument.
         */
        if (filled($data['invoice_reference'] ?? null)) {
            $open = $this->statement->openInvoiceReferences($this->subject($request), $vendor);

            if (! in_array($data['invoice_reference'], $open, true)) {
                return back()->withInput()->withErrors([
                    'invoice_reference' => 'That invoice is not open on this account. Leave it blank to record the payment on account.',
                ]);
            }
        }

        ProductPartnerPayment::create([
            'vendor'            => $vendor,
            'amount'            => (int) round(((float) $data['amount']) * 100),
            'currency'          => 'USD',
            'paid_on'           => $data['paid_on'],
            'method'            => $data['method'] ?? null,
            'reference'         => $data['reference'] ?? null,
            'invoice_reference' => $data['invoice_reference'] ?? null,
            'note'              => $data['note'] ?? null,
            'recorded_by_user_id' => $request->user()->id,
        ]);

        return back()->with('status', 'Payment recorded. It will show as pending until Quantum 3 confirms it.');
    }
}
