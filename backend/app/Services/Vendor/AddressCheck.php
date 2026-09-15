<?php

namespace App\Services\Vendor;

use App\Models\User;
use App\Models\VendorLead;
use App\Services\Vendor\Shipping\AddressVerification;
use App\Services\Vendor\Shipping\AddressVerifier;
use RuntimeException;

/**
 * Verifies an order's delivery address, and records what the buyer and an
 * admin decided about it.
 *
 * The owner's rule (2026-09-15): an address FedEx cannot confirm does not lose
 * the sale. The buyer can fix it or confirm it as entered, and a confirmed
 * address is flagged for an admin to check before PlasmaGuard ships. A PO Box
 * is the exception, because FedEx cannot deliver to one at all.
 */
class AddressCheck
{
    private const FIELDS = ['address_line1', 'address_line2', 'city', 'state', 'postal_code', 'country'];

    public function __construct(private readonly AddressVerifier $verifier) {}

    /** Whether the unsaved changes on this lead touch its delivery address. */
    public static function addressChanged(VendorLead $lead): bool
    {
        return $lead->isDirty(self::FIELDS);
    }

    /**
     * Check the lead's current address and record the result. Saves.
     *
     * Any earlier confirmation or admin review is cleared: it was about a
     * different address.
     */
    public function check(VendorLead $lead): AddressVerification
    {
        $result = $this->verifier->verify($lead->only(self::FIELDS));

        $lead->forceFill([
            'address_status'         => $result->status,
            'address_status_reason'  => $result->reason,
            'address_classification' => $result->classification,
            'address_suggestion'     => $result->status === AddressVerification::SUGGESTED ? $result->address : null,
            'address_checked_at'     => now(),
            'address_confirmed_at'   => null,
            'address_reviewed_by'    => null,
            'address_reviewed_at'    => null,
        ]);

        // Confirmed, with only the formatting changed: FedEx's form is the one to ship to.
        if ($result->status === AddressVerification::VERIFIED && $result->address !== null) {
            $lead->forceFill($result->address);
        }

        $lead->save();

        return $result;
    }

    /** The buyer took FedEx's corrected address. */
    public function acceptSuggestion(VendorLead $lead): void
    {
        $suggestion = $lead->address_suggestion;

        if ($lead->address_status !== AddressVerification::SUGGESTED || ! is_array($suggestion)) {
            throw new RuntimeException('There is no suggested address to use.');
        }

        $lead->forceFill(array_intersect_key($suggestion, array_flip(self::FIELDS)) + [
            'address_status'        => AddressVerification::VERIFIED,
            'address_status_reason' => null,
            'address_suggestion'    => null,
            'address_confirmed_at'  => null,
        ])->save();
    }

    /** The buyer confirmed an address FedEx did not confirm. */
    public function confirmAsEntered(VendorLead $lead): void
    {
        if (in_array($lead->address_status, [null, AddressVerification::VERIFIED, AddressVerification::REJECTED], true)) {
            throw new RuntimeException('This address cannot be confirmed as entered. Please enter a street address FedEx can deliver to.');
        }

        $lead->forceFill(['address_confirmed_at' => now()])->save();
    }

    /** An admin checked a buyer-confirmed address before it shipped. */
    public function markReviewed(VendorLead $lead, User $admin): void
    {
        $lead->forceFill([
            'address_reviewed_by' => $admin->id,
            'address_reviewed_at' => now(),
        ])->save();
    }
}
