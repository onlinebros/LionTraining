<?php

namespace App\Services\Vendor;

use App\Models\User;
use App\Models\VendorLead;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Decides who a vendor sale counts for, and who is paid commission on it.
 *
 * The rule (owner, 2026-09-14): a partner is never paid on their own purchase.
 * It counts as their sale, for their record and for promotions, but the
 * commission goes to the partner who sponsored them, whichever share link was
 * used. Otherwise a partner could buy through their own link, or swap links with
 * a friend, and take the commission their sponsor was owed.
 *
 * Only sure matches are acted on automatically: an order from the back office,
 * or a buyer email or phone number that belongs to a partner account. A shipping
 * address match on its own is a suspicion rather than proof (a household can
 * hold two partners, an office building many), so the commission is held for an
 * admin instead of being moved.
 *
 * What this cannot see: a partner buying with a different email and phone and
 * shipping somewhere else. Card fingerprints do not close that gap, because the
 * charge is made on the vendor's Stripe account and fingerprints are not
 * comparable across unrelated accounts.
 */
class PurchaseAttribution
{
    /**
     * Work out the attribution and write it onto the lead. Does not save.
     *
     * An admin's decision is left alone, so a later webhook cannot quietly
     * undo it.
     */
    public function apply(VendorLead $lead): VendorLead
    {
        if ($lead->attribution_resolved_at !== null) {
            return $lead;
        }

        [$attribution, $buyer, $reason] = $this->detect($lead);

        return $this->assign($lead, $attribution, $buyer, $reason);
    }

    /** Write an attribution onto the lead. Does not save. */
    public function assign(VendorLead $lead, string $attribution, ?User $buyer, ?string $reason): VendorLead
    {
        $isSelf = $attribution === VendorLead::ATTRIBUTION_SELF;

        if ($isSelf && $buyer === null) {
            throw new InvalidArgumentException('An own purchase needs the partner who made it.');
        }

        $fields = match ($attribution) {
            // Counts for the buyer; paid to their sponsor, or to no one.
            VendorLead::ATTRIBUTION_SELF => [
                'buyer_user_id'      => $buyer->id,
                'credited_member_id' => $buyer->id,
                'earner_id'          => $buyer->sponsor_id,
            ],
            // Provisionally the link owner's sale. Nobody is paid until decided.
            VendorLead::ATTRIBUTION_REVIEW => [
                'buyer_user_id'      => $buyer?->id,
                'credited_member_id' => $lead->member_id,
                'earner_id'          => null,
            ],
            VendorLead::ATTRIBUTION_CUSTOMER => [
                'buyer_user_id'      => null,
                'credited_member_id' => $lead->member_id,
                'earner_id'          => $lead->member_id,
            ],
            default => throw new InvalidArgumentException("Unknown attribution: {$attribution}"),
        };

        if ($attribution === VendorLead::ATTRIBUTION_SELF && $buyer?->sponsor_id === null) {
            $reason = trim((string) $reason).'. They have no sponsor, so no commission is paid';
        }

        return $lead->forceFill($fields + [
            'attribution'        => $attribution,
            'attribution_reason' => $reason === null ? null : Str::limit($reason, 250),
        ]);
    }

    /**
     * @return array{0: string, 1: ?User, 2: ?string}
     */
    public function detect(VendorLead $lead): array
    {
        if ($lead->source === VendorLead::SOURCE_BACK_OFFICE && $lead->member !== null) {
            return [VendorLead::ATTRIBUTION_SELF, $lead->member, 'Ordered from the partner back office'];
        }

        $email = Str::lower(trim((string) $lead->email));

        if ($email !== '') {
            $partner = User::whereRaw('LOWER(email) = ?', [$email])->first();

            if ($partner !== null) {
                return [VendorLead::ATTRIBUTION_SELF, $partner, "Buyer email matches partner {$partner->name}"];
            }
        }

        $phone = $this->phoneKey($lead->phone);

        if ($phone !== null) {
            // Compared on the last ten digits, so "+1 (734) 555-0199" and
            // "734.555.0199" are the same number.
            $partners = User::whereRaw(
                "RIGHT(REGEXP_REPLACE(COALESCE(phone, ''), '[^0-9]', '', 'g'), 10) = ?",
                [$phone],
            )->get();

            if ($partners->count() === 1) {
                return [VendorLead::ATTRIBUTION_SELF, $partners->first(), "Buyer phone matches partner {$partners->first()->name}"];
            }

            if ($partners->count() > 1) {
                return [VendorLead::ATTRIBUTION_REVIEW, null, 'Buyer phone matches several partners: '.$this->names($partners)];
            }
        }

        $partners = $this->addressMatches($lead);

        if ($partners->isNotEmpty()) {
            return [
                VendorLead::ATTRIBUTION_REVIEW,
                $partners->count() === 1 ? $partners->first() : null,
                'Shipping address matches '.($partners->count() === 1 ? 'partner ' : 'partners ').$this->names($partners),
            ];
        }

        return [VendorLead::ATTRIBUTION_CUSTOMER, null, null];
    }

    // ── Internals ─────────────────────────────────────────────────────────────

    private function phoneKey(?string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $phone) ?? '';

        // Fewer than ten digits is an extension or a typo, not a number to
        // match anyone on.
        return strlen($digits) >= 10 ? substr($digits, -10) : null;
    }

    /** @return Collection<int, User> */
    private function addressMatches(VendorLead $lead): Collection
    {
        $line = $this->addressKey($lead->address_line1);
        $zip  = substr($this->addressKey($lead->postal_code), 0, 5);

        if ($line === '' || strlen($zip) < 5) {
            return collect();
        }

        return User::whereNotNull('address_line1')
            ->whereRaw(
                "LEFT(LOWER(REGEXP_REPLACE(COALESCE(postal_code, ''), '[^0-9A-Za-z]', '', 'g')), 5) = ?",
                [$zip],
            )
            ->get()
            ->filter(fn (User $user) => $this->addressKey($user->address_line1) === $line)
            ->values();
    }

    /** Letters and digits only, so "12 Elm Street," and "12 ELM STREET" match. */
    private function addressKey(?string $value): string
    {
        return preg_replace('/[^a-z0-9]/', '', Str::lower((string) $value)) ?? '';
    }

    /** @param  Collection<int, User>  $partners */
    private function names(Collection $partners): string
    {
        return $partners->pluck('name')->implode(', ');
    }
}
