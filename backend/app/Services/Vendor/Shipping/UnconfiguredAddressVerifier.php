<?php

namespace App\Services\Vendor\Shipping;

/**
 * Used while no carrier credentials are installed.
 *
 * Reports every address as unavailable rather than valid or invalid, so the
 * buyer is asked to confirm it and the order is flagged for an admin.
 */
class UnconfiguredAddressVerifier implements AddressVerifier
{
    public function verify(array $address): AddressVerification
    {
        return AddressVerification::unavailable();
    }
}
