<?php

namespace App\Services\Vendor\Shipping;

use App\Support\Vendors;

/**
 * The rater used until a carrier account is wired up.
 *
 * PlasmaGuard want quotes against their negotiated FedEx rates, which needs API
 * credentials they have not supplied yet. Rather than block the whole checkout
 * on that, this returns a configured flat figure and — importantly — labels it
 * `flat` rather than pretending it came from a carrier.
 *
 * It still enforces the two rules that are about the product rather than the
 * carrier: one carton per unit, and anything past the parcel threshold goes to
 * a human for a freight quote instead of being guessed at.
 */
class FlatShippingRater implements ShippingRater
{
    public function __construct(private readonly string $vendorSlug) {}

    public function quote(array $origin, array $destination, array $parcel, int $cartons): ShippingQuote
    {
        $config    = Vendors::find($this->vendorSlug)['pricing']['shipping'] ?? [];
        $threshold = (int) ($config['freight_threshold_units'] ?? 0);

        /*
         * One unit per carton and 45 to a pallet, so a large order is a lot of
         * separate parcels rather than one big one. Past the threshold, LTL
         * freight beats parcel comfortably and a parcel quote would be wrong by
         * enough to matter — so it stops and asks for a person.
         */
        if ($threshold > 0 && $cartons > $threshold) {
            return ShippingQuote::freight(
                "{$cartons} cartons exceeds the parcel threshold of {$threshold}; needs an LTL freight quote."
            );
        }

        $mode = $config['mode'] ?? 'live';

        if ($mode === 'free') {
            return new ShippingQuote(amount: 0, source: ShippingQuote::SOURCE_FREE);
        }

        $perCarton = (int) ($config['flat_amount'] ?? 0);

        /*
         * `live` mode with no carrier registered is a misconfiguration, not a
         * reason to invent a number. Config sets require_negotiated precisely so
         * a quote fails loudly rather than silently falling back to a figure
         * nobody agreed to.
         */
        if ($perCarton <= 0) {
            return ShippingQuote::freight(
                'No carrier account is registered and no flat fallback is configured, so shipping cannot be quoted.'
            );
        }

        return new ShippingQuote(
            amount: $perCarton * max(1, $cartons),
            source: ShippingQuote::SOURCE_FLAT,
            service: 'Ground',
            carrier: $config['carrier']['name'] ?? null,
        );
    }
}
