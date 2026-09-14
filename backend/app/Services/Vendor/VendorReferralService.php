<?php

namespace App\Services\Vendor;

use App\Models\CommissionLedger;
use App\Models\CrmContact;
use App\Models\User;
use App\Models\VendorLead;
use App\Support\Vendors;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Capture, handoff, and reconciliation for sales made on someone else's checkout.
 *
 * The ordering here is the design. A lead is persisted with its reference BEFORE
 * the customer is redirected, so the partner's claim on the sale exists whether
 * or not the vendor ever tells us anything. Everything after the redirect only
 * ever *upgrades* a record that already exists.
 */
class VendorReferralService
{
    public function __construct(private readonly PurchaseAttribution $attribution) {}

    /**
     * Record an interested customer and the partner who found them.
     *
     * Also files them in the partner's CRM, because a lead that converts three
     * weeks later needs to be somewhere the partner can work it in the meantime.
     *
     * A partner ordering for themselves from the back office passes themselves as
     * `buyer` in the context. That order is not filed in their CRM: they are not
     * their own prospect.
     *
     * @param  array<string,mixed>  $data     Validated customer fields.
     * @param  array<string,mixed>  $context  ip / user_agent / utm / source / buyer.
     */
    public function capture(
        string $vendor,
        string $productKey,
        User $member,
        array $data,
        array $context = [],
    ): VendorLead {
        if (Vendors::product($vendor, $productKey) === null) {
            throw new RuntimeException("Unknown vendor product: {$vendor}/{$productKey}");
        }

        $buyer    = $context['buyer'] ?? null;
        $ownOrder = $buyer instanceof User;

        if ($ownOrder && $buyer->id !== $member->id) {
            throw new RuntimeException('A back-office order can only be placed by the partner it is recorded against.');
        }

        return DB::transaction(function () use ($vendor, $productKey, $member, $data, $context, $ownOrder) {
            $contact = $ownOrder ? null : $this->syncCrmContact($member, $data, $vendor, $productKey);

            $lead = VendorLead::create([
                'public_ref'     => $this->generateReference(),
                'vendor'         => $vendor,
                'product_key'    => $productKey,
                'member_id'      => $member->id,
                // Snapshot, not a join — see the migration comment.
                'referral_code'  => $member->referral_code,
                'crm_contact_id' => $contact?->id,

                'first_name'    => $data['first_name'],
                'last_name'     => $data['last_name']    ?? null,
                'email'         => $data['email'],
                'phone'         => $data['phone']        ?? null,
                'company'       => $data['company']      ?? null,
                'address_line1' => $data['address_line1'] ?? null,
                'address_line2' => $data['address_line2'] ?? null,
                'city'          => $data['city']         ?? null,
                'state'         => $data['state']        ?? null,
                'postal_code'   => $data['postal_code']  ?? null,
                'country'       => $data['country']      ?? null,
                'quantity'      => (int) ($data['quantity'] ?? 1),
                'notes'         => $data['notes']        ?? null,
                'qualifiers'    => $data['qualifiers']   ?? null,

                'status'     => VendorLead::STATUS_NEW,
                'source'     => $ownOrder ? VendorLead::SOURCE_BACK_OFFICE : ($context['source'] ?? 'member_page'),
                'ip'         => $context['ip']         ?? null,
                'user_agent' => $context['user_agent'] ?? null,
                'utm'        => $context['utm']        ?? null,
            ]);

            // Worked out now so the partner's screens are right from the start,
            // and again at conversion, once the shipping address is known.
            $this->attribution->apply($lead)->save();

            return $lead;
        });
    }

    /**
     * The vendor checkout URL with our attribution attached.
     *
     * Parameter *names* come from the registry rather than being hard-coded:
     * `client_reference_id` and `prefilled_email` are Stripe Payment Link's
     * spelling, and PlasmaGuard have floated replacing that page with a custom
     * checkout. When they do, this method does not change.
     *
     * Existing query strings on the configured URL are preserved — a vendor who
     * hands us a link that already carries parameters is not unusual.
     */
    public function checkoutUrl(VendorLead $lead): string
    {
        $config = Vendors::find($lead->vendor);
        $base   = (string) ($config['checkout']['url'] ?? '');

        if ($base === '') {
            throw new RuntimeException("No checkout URL configured for vendor: {$lead->vendor}");
        }

        $map = (array) ($config['checkout']['params'] ?? []);

        $values = [
            'reference' => $lead->public_ref,
            'email'     => $lead->email,
        ];

        $parts = parse_url($base);
        parse_str($parts['query'] ?? '', $query);

        foreach ($values as $key => $value) {
            $param = $map[$key] ?? null;

            // A param mapped to null is how "this vendor cannot accept this
            // field" is expressed. Sending it anyway is how you get a checkout
            // that 400s on an unrecognised parameter.
            if (is_string($param) && $param !== '' && $value !== null && $value !== '') {
                $query[$param] = $value;
            }
        }

        $rebuilt = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '')
            .($parts['port'] ?? null ? ':'.$parts['port'] : '')
            .($parts['path'] ?? '');

        return $rebuilt.($query === [] ? '' : '?'.http_build_query($query));
    }

    /** Mark the moment we lost sight of the customer. */
    public function markHandedOff(VendorLead $lead, string $url): VendorLead
    {
        $lead->forceFill([
            'status'        => VendorLead::STATUS_HANDED_OFF,
            'handed_off_at' => now(),
            'checkout_url'  => $url,
        ])->save();

        return $lead;
    }

    // ── Reconciliation ────────────────────────────────────────────────────────

    /**
     * Find the lead a vendor confirmation belongs to.
     *
     * Tried in descending order of certainty. A payload that matches nothing
     * returns null and is left for a human — never guessed at. Paying the wrong
     * partner is worse than paying no one, because it has to be clawed back from
     * someone who did nothing wrong.
     *
     * @param  array<string,mixed>  $payload  The vendor's checkout/session object.
     */
    public function resolve(string $vendor, array $payload): ?VendorLead
    {
        // 1. Our own reference, echoed back. Authoritative.
        $reference = $payload['client_reference_id'] ?? null;

        if (is_string($reference) && $reference !== '') {
            $lead = VendorLead::forVendor($vendor)->where('public_ref', $reference)->first();

            if ($lead !== null) {
                return $lead;
            }

            // A reference that looks like ours but matches nothing is a real
            // signal — a deleted lead, or a wrong environment — not noise.
            Log::warning('Vendor confirmation carried an unknown reference', [
                'vendor'    => $vendor,
                'reference' => $reference,
            ]);
        }

        // 2. A session we have already seen. Makes redelivery safe even when the
        //    reference was missing the first time.
        $sessionId = $payload['id'] ?? null;

        if (is_string($sessionId) && $sessionId !== '') {
            $lead = VendorLead::forVendor($vendor)->where('provider_session_id', $sessionId)->first();

            if ($lead !== null) {
                return $lead;
            }
        }

        // 3. Email, bounded by status and by a window. Unbounded, this eventually
        //    attributes a stranger's purchase to whoever once typed that address.
        $email = $payload['customer_details']['email']
            ?? $payload['customer_email']
            ?? null;

        if (is_string($email) && $email !== '') {
            return VendorLead::forVendor($vendor)
                ->awaitingPurchase()
                ->whereRaw('LOWER(email) = ?', [Str::lower($email)])
                ->where('handed_off_at', '>=', now()->subDays(Vendors::matchWindowDays($vendor)))
                ->latest('handed_off_at')
                ->first();
        }

        return null;
    }

    /**
     * Record a confirmed sale and raise the commission.
     *
     * Idempotent by construction: a lead already converted returns unchanged, so
     * a redelivered webhook cannot pay twice. The row is locked because the
     * commission decision reads and writes the same record.
     *
     * Money fields come from the VENDOR's payload only. What we captured at the
     * form is an estimate — quantity, shipping and tax are all decided on their
     * page after we lose sight of the customer.
     *
     * @param  array<string,mixed>  $confirmation
     */
    public function convert(VendorLead $lead, array $confirmation, string $via = VendorLead::VIA_WEBHOOK, ?User $actor = null): VendorLead
    {
        return DB::transaction(function () use ($lead, $confirmation, $via, $actor) {
            $lead = VendorLead::lockForUpdate()->find($lead->id);

            if ($lead === null || $lead->isConverted()) {
                return $lead;
            }

            $lead->forceFill([
                'status'                     => VendorLead::STATUS_CONVERTED,
                'converted_at'               => $confirmation['converted_at'] ?? now(),
                'vendor_order_ref'           => $confirmation['vendor_order_ref'] ?? null,
                'provider_session_id'        => $confirmation['session_id'] ?? $lead->provider_session_id,
                'provider_payment_intent_id' => $confirmation['payment_intent_id'] ?? null,
                'amount_total'               => $confirmation['amount_total'] ?? null,
                'currency'                   => $confirmation['currency'] ?? null,
                'confirmed_via'              => $via,
                'confirmed_by'               => $actor?->id,
            ]);

            // Decided at the moment of sale, from everything the order now
            // carries. This is what stops a partner being paid on their own
            // purchase, whichever share link they used.
            $this->attribution->apply($lead)->save();

            $this->syncContactStatus($lead, 'purchased');
            $this->raiseCommission($lead);

            return $lead;
        });
    }

    /**
     * An admin settles who an order counts for.
     *
     * The path for an order held on an address-only match, and for correcting an
     * automatic decision. Only allowed before commission is raised: once a credit
     * exists, moving it is a clawback plus a new credit, not an edit.
     */
    public function resolveAttribution(VendorLead $lead, string $decision, User $admin): VendorLead
    {
        return DB::transaction(function () use ($lead, $decision, $admin) {
            $lead = VendorLead::lockForUpdate()->findOrFail($lead->id);

            if ($lead->commission_ledger_id !== null) {
                throw new RuntimeException(
                    'Commission has already been raised on this order. Use a clawback to change who is paid.'
                );
            }

            $buyer = match ($decision) {
                VendorLead::ATTRIBUTION_SELF => $lead->buyer
                    ?? throw new RuntimeException('No partner has been identified as the buyer of this order.'),
                VendorLead::ATTRIBUTION_CUSTOMER => null,
                default => throw new RuntimeException("Unknown attribution decision: {$decision}"),
            };

            $this->attribution->assign($lead, $decision, $buyer, $lead->attribution_reason);

            $lead->forceFill([
                'attribution_resolved_by' => $admin->id,
                'attribution_resolved_at' => now(),
            ])->save();

            if ($lead->isConverted()) {
                $this->raiseCommission($lead);
            }

            return $lead;
        });
    }

    /**
     * A vendor refund reverses the sale and opens the clawback.
     *
     * The ledger credit is not deleted — it is left standing and a clawback is
     * raised against it through the existing path, so the partner's statement
     * shows what happened rather than a line quietly vanishing.
     */
    public function refund(VendorLead $lead): VendorLead
    {
        return DB::transaction(function () use ($lead) {
            $lead = VendorLead::lockForUpdate()->find($lead->id);

            if ($lead === null || $lead->status === VendorLead::STATUS_REFUNDED) {
                return $lead;
            }

            $lead->forceFill([
                'status'      => VendorLead::STATUS_REFUNDED,
                'refunded_at' => now(),
            ])->save();

            $this->syncContactStatus($lead, 'lost');

            // A credit not yet approved can simply be voided; one already
            // approved or paid needs the clawback workflow and a human, so it is
            // surfaced rather than silently reversed.
            $ledger = $lead->commission_ledger_id
                ? CommissionLedger::find($lead->commission_ledger_id)
                : null;

            if ($ledger !== null && $ledger->status === 'pending') {
                $ledger->forceFill([
                    'status' => 'voided',
                    'notes'  => trim((string) $ledger->notes."\nVoided: vendor refunded {$lead->public_ref}."),
                ])->save();
            } elseif ($ledger !== null) {
                Log::warning('Vendor refund on an already-approved commission — clawback required', [
                    'lead'   => $lead->public_ref,
                    'ledger' => $ledger->id,
                ]);
            }

            return $lead;
        });
    }

    /**
     * Credit the commission for a confirmed sale.
     *
     * Paid to the lead's `earner_id`, which is not always the partner whose link
     * was used: a partner's own purchase pays their sponsor. Guarded by
     * `commission_ledger_id`, so this can be called more than once and still
     * raise exactly one credit.
     *
     * Nothing is raised while the order waits for an admin's attribution
     * decision, or when nobody is due it (a partner with no sponsor buying for
     * themselves).
     */
    public function raiseCommission(VendorLead $lead): ?CommissionLedger
    {
        if ($lead->commission_ledger_id !== null
            || $lead->attribution === VendorLead::ATTRIBUTION_REVIEW
            || $lead->earner_id === null) {
            return null;
        }

        $rate = Vendors::commissionRate((string) $lead->vendor);
        $base = $this->commissionBase($lead);

        if ($rate <= 0 || $base <= 0) {
            // Nothing to pay on. Left uncommissioned and visible on the admin
            // reconciliation list rather than written as a zero-value credit
            // that looks settled.
            return null;
        }

        $clawbackDays = Vendors::clawbackDays((string) $lead->vendor);

        $ledger = CommissionLedger::create([
            'earner_id'   => $lead->earner_id,
            'source_type' => VendorLead::class,
            'source_id'   => $lead->id,
            'type'        => 'credit',
            'amount'      => round(($base / 100) * $rate, 4),
            'status'      => 'pending',
            'clawback_eligible_until' => $clawbackDays > 0
                ? Carbon::parse($lead->converted_at ?? now())->addDays($clawbackDays)->toDateString()
                : null,
            'notes' => sprintf(
                '%s — %s (%s) · order %s · %s %s%s at %s%%%s',
                $lead->vendorName(),
                $lead->productName(),
                $lead->public_ref,
                $lead->vendor_order_ref ?: 'n/a',
                Vendors::commissionBasis((string) $lead->vendor) === 'order_total' ? 'order total' : 'revenue share',
                Str::upper((string) ($lead->currency ?: 'USD')),
                number_format($base / 100, 2, '.', ''),
                rtrim(rtrim(number_format($rate * 100, 2, '.', ''), '0'), '.'),
                $lead->isOwnPurchase()
                    ? sprintf(' · own purchase by %s, paid to their sponsor', $lead->buyer->name ?? 'a partner')
                    : '',
            ),
        ]);

        $lead->forceFill(['commission_ledger_id' => $ledger->id])->save();

        return $ledger;
    }

    // ── Internals ─────────────────────────────────────────────────────────────

    /**
     * What the commission rate is applied to, in minor units.
     *
     * Normally our revenue share as recorded when the order was built. An order
     * confirmed without passing our checkout (a payment link, or marked by hand)
     * has no share recorded, so it is worked out from the product's terms the
     * same way a quote does. A product with no price yields nothing, and the
     * order stays on the reconciliation worklist rather than being guessed at.
     */
    private function commissionBase(VendorLead $lead): int
    {
        $vendor = (string) $lead->vendor;

        if (Vendors::commissionBasis($vendor) === 'order_total') {
            return (int) $lead->amount_total;
        }

        if ((int) $lead->our_share_amount > 0) {
            return (int) $lead->our_share_amount;
        }

        $product  = Vendors::product($vendor, (string) $lead->product_key) ?? [];
        $quantity = max(1, (int) $lead->quantity);
        $subtotal = (int) ($lead->subtotal_amount ?: (int) round(((float) ($product['price'] ?? 0)) * 100) * $quantity);

        if ($subtotal <= 0) {
            return 0;
        }

        try {
            return Vendors::revenueShare($vendor, (string) $lead->product_key, $quantity, $subtotal);
        } catch (RuntimeException $e) {
            Log::warning('No commission base for vendor order', [
                'lead'  => $lead->public_ref,
                'error' => $e->getMessage(),
            ]);

            return 0;
        }
    }

    /**
     * File the customer in the partner's CRM.
     *
     * Matched on email within the partner's own contacts, so a returning
     * customer updates one record instead of accumulating duplicates. Failure
     * here is logged and swallowed: a CRM write must never cost us the lead
     * capture, which is the part that carries the money.
     *
     * @param  array<string,mixed>  $data
     */
    private function syncCrmContact(User $member, array $data, string $vendor, string $productKey): ?CrmContact
    {
        try {
            $attributes = [
                'owner_id'      => $member->id,
                'created_by'    => $member->id,
                'contact_type'  => 'lead',
                'lead_source'   => 'referral',
                'referred_by_user_id' => $member->id,
                'first_name'    => $data['first_name'],
                'last_name'     => $data['last_name']     ?? null,
                'phone'         => $data['phone']         ?? null,
                'company'       => $data['company']       ?? null,
                'address_line1' => $data['address_line1'] ?? null,
                'address_line2' => $data['address_line2'] ?? null,
                'city'          => $data['city']          ?? null,
                'state'         => $data['state']         ?? null,
                'postal_code'   => $data['postal_code']   ?? null,
                'country'       => $data['country']       ?? null,
                'quick_note'    => sprintf(
                    'Enquiry via %s — %s.',
                    Vendors::name($vendor),
                    (string) (Vendors::product($vendor, $productKey)['name'] ?? $productKey),
                ),
            ];

            $existing = CrmContact::where('owner_id', $member->id)
                ->whereRaw('LOWER(email) = ?', [Str::lower($data['email'])])
                ->first();

            if ($existing !== null) {
                // Status is deliberately not reset — a contact already marked
                // 'purchased' must not be demoted to 'interested' by a second
                // enquiry.
                $existing->fill(array_filter(
                    $attributes,
                    static fn ($v, $k) => $v !== null && ! in_array($k, ['owner_id', 'created_by'], true),
                    ARRAY_FILTER_USE_BOTH,
                ))->save();

                return $existing;
            }

            return CrmContact::create($attributes + [
                'email'  => $data['email'],
                'status' => 'interested',
            ]);
        } catch (\Throwable $e) {
            Log::error('CRM sync failed for vendor lead', [
                'member' => $member->id,
                'error'  => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function syncContactStatus(VendorLead $lead, string $status): void
    {
        $contact = $lead->crmContact;

        if ($contact === null) {
            return;
        }

        $contact->forceFill([
            'status'       => $status,
            'contact_type' => $status === 'purchased' ? 'customer' : $contact->contact_type,
        ])->save();
    }

    /**
     * A reference that is safe to put in Stripe's `client_reference_id`.
     *
     * Uppercase alphanumeric only: that field accepts letters, digits, dashes
     * and underscores, and a mixed-case reference is a transcription error
     * waiting to happen when someone reads it off a dashboard down the phone.
     */
    private function generateReference(): string
    {
        $prefix = (string) config('vendors.reference_prefix', 'QLV');

        do {
            $ref = $prefix.'-'.Str::upper(Str::random(10));
        } while (VendorLead::withTrashed()->where('public_ref', $ref)->exists());

        return $ref;
    }
}
