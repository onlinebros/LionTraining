<?php

namespace App\Services\ProductPartner;

use App\Models\ProductPartnerPayment;
use App\Models\User;
use App\Models\VendorLead;
use App\Support\ProductPartner;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * One number both sides can point at: what the vendor owes Quantum 3, and what
 * they have paid against it.
 *
 * The accounting, stated once so the screens do not each invent it:
 *
 *   earned      our revenue share on every confirmed sale, ever. This is the
 *               debt. It is `vendor_leads.our_share_amount`, written at the
 *               point of sale from the vendor's own numbers.
 *   invoiced    of that, what we have put on an invoice.
 *   uninvoiced  earned but not yet billed. Ours to do something about.
 *   settled     invoices we have marked as paid.
 *   credits     share on orders that were refunded AFTER being invoiced. Money
 *               we billed for a sale that came undone, so it comes off.
 *   paid        payments the vendor recorded and we confirmed.
 *   pending     payments they recorded that nobody has confirmed yet. NOT in
 *               the balance — a claim is not a payment.
 *
 *   outstanding = earned - credits - paid
 *
 * `settled` and `paid` measure the same money from two directions: settled is
 * what we ticked off invoice by invoice, paid is what they told us they sent
 * and we agreed. They should agree. When they do not, the difference is the
 * conversation this screen exists to have, so both are shown rather than one
 * being derived from the other.
 */
class PartnerStatement
{
    /**
     * @return array<string, mixed>
     */
    public function forVendor(User $user, string $vendor): array
    {
        $leads = fn () => ProductPartner::scopeLeads(VendorLead::query(), $user, $vendor);

        $earned     = (int) (clone $leads())->converted()->sum('our_share_amount');
        $invoiced   = (int) (clone $leads())->converted()->whereNotNull('invoiced_at')->sum('our_share_amount');
        $uninvoiced = (int) (clone $leads())->converted()->whereNull('invoiced_at')->sum('our_share_amount');
        $settled    = (int) (clone $leads())->converted()->whereNotNull('settled_at')->sum('our_share_amount');

        /*
         * A refund moves the lead's status off `converted`, so it drops out of
         * `earned` on its own. That is right for an order we never billed —
         * and wrong for one we did, where the money has to be handed back
         * rather than quietly forgotten. Those are counted here and taken off
         * the balance.
         */
        $credits = (int) (clone $leads())
            ->where('status', VendorLead::STATUS_REFUNDED)
            ->whereNotNull('invoiced_at')
            ->sum('our_share_amount');

        $payments = ProductPartnerPayment::forVendor($vendor);

        $paid    = (int) (clone $payments)->confirmed()->sum('amount');
        $pending = (int) (clone $payments)->pending()->sum('amount');

        return [
            'vendor'      => $vendor,
            'earned'      => $earned,
            'invoiced'    => $invoiced,
            'uninvoiced'  => $uninvoiced,
            'settled'     => $settled,
            'credits'     => $credits,
            'paid'        => $paid,
            'pending'     => $pending,
            'outstanding' => $earned - $credits - $paid,

            'orders'         => (clone $leads())->converted()->count(),
            'units'          => (int) (clone $leads())->converted()->sum('quantity'),
            'pending_count'  => (clone $payments)->pending()->count(),
        ];
    }

    /**
     * The invoices, built from the orders on them.
     *
     * There is no invoices table: an invoice here is a reference an admin typed
     * onto a batch of orders, and the document itself lives in whatever does
     * our accounting. Grouping the orders is therefore the only honest way to
     * show one — and it means a line can never disagree with its total.
     *
     * @return Collection<int, object>
     */
    public function invoices(User $user, string $vendor): Collection
    {
        return ProductPartner::scopeLeads(VendorLead::query(), $user, $vendor)
            ->whereNotNull('invoiced_at')
            ->whereNotNull('invoice_reference')
            ->groupBy('invoice_reference')
            ->select('invoice_reference')
            ->selectRaw('COUNT(*) as orders')
            ->selectRaw('SUM(quantity) as units')
            ->selectRaw('SUM(our_share_amount) as amount')
            ->selectRaw('MIN(invoiced_at) as invoiced_at')
            ->selectRaw('MAX(settled_at) as settled_at')
            // An invoice is settled when every order on it is. A partially
            // ticked invoice reads as unsettled, which is the safe direction.
            ->selectRaw('COUNT(*) FILTER (WHERE settled_at IS NULL) as unsettled')
            ->orderByDesc('invoiced_at')
            ->get();
    }

    /**
     * Sales earned but not yet on any invoice.
     *
     * Shown to the vendor on purpose. It is the part of the debt we have not
     * billed for yet, and hiding it would mean their balance jumps every time
     * we get round to raising an invoice.
     */
    public function uninvoicedOrders(User $user, string $vendor, int $perPage = 25)
    {
        return ProductPartner::scopeLeads(VendorLead::query(), $user, $vendor)
            ->uninvoiced()
            ->orderByDesc('converted_at')
            ->paginate($perPage, ['*'], 'uninvoiced');
    }

    /** @return Collection<int, ProductPartnerPayment> */
    public function payments(string $vendor, int $limit = 100): Collection
    {
        return ProductPartnerPayment::forVendor($vendor)
            ->with(['recordedBy', 'confirmedBy'])
            ->orderByDesc('paid_on')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /**
     * Confirm a payment, and tick off the invoice it names.
     *
     * The two happen together or not at all. A confirmed payment whose invoice
     * is still showing as owed is exactly the disagreement the screen is meant
     * to prevent, and it is what would happen if an admin confirmed the payment
     * and then got distracted before settling the orders.
     *
     * A payment naming no invoice reference just confirms — an on-account
     * payment is a real thing, and it still moves the balance.
     *
     * @return int  How many orders were settled by this.
     */
    public function confirmPayment(ProductPartnerPayment $payment, User $by, ?string $note = null): int
    {
        return DB::transaction(function () use ($payment, $by, $note) {
            $payment->confirm($by, $note);

            if (blank($payment->invoice_reference)) {
                return 0;
            }

            return VendorLead::forVendor($payment->vendor)
                ->where('invoice_reference', $payment->invoice_reference)
                ->whereNotNull('invoiced_at')
                ->whereNull('settled_at')
                ->update(['settled_at' => now()]);
        });
    }

    /**
     * Invoice references the vendor can pick from when recording a payment.
     *
     * Only the unsettled ones: offering an invoice we have already been paid
     * for invites a duplicate claim that somebody then has to disprove.
     *
     * @return array<int, string>
     */
    public function openInvoiceReferences(User $user, string $vendor): array
    {
        return ProductPartner::scopeLeads(VendorLead::query(), $user, $vendor)
            ->whereNotNull('invoiced_at')
            ->whereNull('settled_at')
            ->whereNotNull('invoice_reference')
            ->distinct()
            ->orderBy('invoice_reference')
            ->pluck('invoice_reference')
            ->all();
    }
}
