<?php

namespace App\Services\Vendor\Shipping;

/**
 * Anything that can put a number on delivering N cartons to an address.
 *
 * An interface because the carrier is not settled: PlasmaGuard want their own
 * negotiated FedEx rates, which needs API credentials they have not yet
 * supplied. Until then FlatShippingRater keeps the whole checkout buildable and
 * testable, and swapping it out is a container binding rather than a rewrite.
 */
interface ShippingRater
{
    /**
     * @param  array<string,mixed>  $origin       line1/city/state/postal_code/country
     * @param  array<string,mixed>  $destination  same shape
     * @param  array<string,mixed>  $parcel       length_in/width_in/height_in/weight_lb
     * @param  int                  $cartons      one per unit for this product
     */
    public function quote(array $origin, array $destination, array $parcel, int $cartons): ShippingQuote;
}
