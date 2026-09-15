<?php

namespace App\Services\Vendor\Shipping;

/**
 * What a carrier said about a delivery address.
 *
 * A result, not a payment decision. Whether the buyer may pay is decided on the
 * order (VendorLead::addressReadyForPayment), because an address the carrier
 * could not confirm can still be confirmed by the buyer.
 */
final readonly class AddressVerification
{
    public const VERIFIED    = 'verified';     // deliverable as held
    public const SUGGESTED   = 'suggested';    // deliverable at a corrected address the buyer must choose
    public const UNVERIFIED  = 'unverified';   // the carrier could not confirm it
    public const UNAVAILABLE = 'unavailable';  // the check could not be run
    public const REJECTED    = 'rejected';     // cannot be delivered to at all (a PO Box)

    /**
     * @param  array<string,?string>|null  $address  The carrier's standard form, in our field names.
     */
    public function __construct(
        public string $status,
        public ?string $reason = null,
        public ?array $address = null,
        public ?string $classification = null,
    ) {}

    public static function unavailable(): self
    {
        return new self(self::UNAVAILABLE, 'Automatic address checking is not available right now.');
    }
}
