<?php

namespace App\Services\Vendor\Shipping;

/**
 * Checks that an order can be delivered where the buyer said.
 *
 * Bound in AppServiceProvider: FedEx when its credentials are present, and a
 * verifier that reports "unavailable" otherwise, so the checkout never waits
 * on a carrier that is not set up.
 */
interface AddressVerifier
{
    /**
     * @param  array<string,?string>  $address  address_line1, address_line2, city, state, postal_code, country
     */
    public function verify(array $address): AddressVerification;
}
