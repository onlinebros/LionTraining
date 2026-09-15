<?php

namespace App\Services\Vendor\Shipping;

use App\Support\Vendors;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Live FedEx rates, quoted against the VENDOR's shipping account.
 *
 * Two separate credentials are in play and conflating them is the usual way
 * this goes wrong:
 *
 *   config/fedex.php   — OUR developer project. Who is allowed to ask.
 *   the vendor's       — THEIR shipping account number. Whose rates come back.
 *
 * Without the vendor's account linked to the project, FedEx answers with
 * published retail rates rather than erroring. That is the dangerous failure:
 * the checkout keeps working and quietly overcharges. `require_negotiated`
 * turns it into a refusal instead — see rateFrom().
 */
class FedExShippingRater implements ShippingRater
{
    public function __construct(private readonly string $vendorSlug) {}

    public function quote(array $origin, array $destination, array $parcel, int $cartons): ShippingQuote
    {
        $shipping  = Vendors::find($this->vendorSlug)['pricing']['shipping'] ?? [];
        $threshold = (int) ($shipping['freight_threshold_units'] ?? 0);

        // Past the parcel threshold LTL wins by enough that a parcel quote is
        // not merely rough, it is wrong. Checked before spending an API call.
        if ($threshold > 0 && $cartons > $threshold) {
            return ShippingQuote::freight(
                "{$cartons} cartons exceeds the parcel threshold of {$threshold}; needs an LTL freight quote."
            );
        }

        $account = (string) ($shipping['carrier']['account_number'] ?? '');

        if ($account === '' || blank(config('fedex.key'))) {
            return ShippingQuote::freight('FedEx rating is not configured, so shipping cannot be quoted.');
        }

        if (blank($destination['postal_code'] ?? null)) {
            return ShippingQuote::freight('No delivery postcode, so shipping cannot be quoted.');
        }

        try {
            $token = $this->token();

            if ($token === null) {
                return ShippingQuote::freight('Could not authenticate with FedEx.');
            }

            $response = Http::withToken($token)
                ->timeout((int) config('fedex.timeout', 12))
                ->withHeaders(['X-locale' => 'en_US'])
                ->post(config('fedex.host').'/rate/v1/rates/quotes',
                    $this->payload($account, $origin, $destination, $parcel, $cartons));

            if (! $response->successful()) {
                /*
                 * Includes FedEx's own 503s, which sandbox returns for long
                 * stretches. Someone else's outage must not take our checkout
                 * with it, and it must not silently produce a made-up number
                 * either — so the order goes to a person.
                 */
                Log::warning('FedEx rating unavailable', [
                    'vendor' => $this->vendorSlug,
                    'status' => $response->status(),
                    'error'  => $response->json('errors.0.code') ?? $response->body(),
                ]);

                return $this->onOutage($origin, $destination, $parcel, $cartons);
            }

            return $this->rateFrom($response->json(), $shipping);
        } catch (\Throwable $e) {
            Log::error('FedEx rating failed', ['vendor' => $this->vendorSlug, 'error' => $e->getMessage()]);

            return $this->onOutage($origin, $destination, $parcel, $cartons);
        }
    }

    /**
     * What to do when FedEx cannot be reached.
     *
     * Deliberately different from "FedEx answered and the rate looked wrong".
     * An unlinked carrier account is a misconfiguration we want surfaced, so
     * that refuses. An outage is someone else's problem, and refusing every
     * sale for the duration of it punishes the customer for FedEx's downtime —
     * so if a flat figure the vendor agreed to is configured, that is used and
     * honestly labelled `flat` rather than passed off as a carrier rate.
     *
     * With no flat figure configured there is nothing honest to fall back to,
     * and the order goes to a human.
     */
    private function onOutage(array $origin, array $destination, array $parcel, int $cartons): ShippingQuote
    {
        $shipping = Vendors::find($this->vendorSlug)['pricing']['shipping'] ?? [];

        if (($shipping['fallback_on_outage'] ?? true) && (int) ($shipping['flat_amount'] ?? 0) > 0) {
            return (new FlatShippingRater($this->vendorSlug))
                ->quote($origin, $destination, $parcel, $cartons);
        }

        return ShippingQuote::freight('Live shipping rates are temporarily unavailable.');
    }

    /**
     * Pick a rate out of FedEx's reply.
     *
     * @param  array<string,mixed>  $body
     * @param  array<string,mixed>  $shipping
     */
    private function rateFrom(array $body, array $shipping): ShippingQuote
    {
        $wanted = (array) config('fedex.services', []);
        $best   = null;

        foreach ($body['output']['rateReplyDetails'] ?? [] as $detail) {
            $service = (string) ($detail['serviceType'] ?? '');

            if ($wanted !== [] && ! in_array($service, $wanted, true)) {
                continue;
            }

            $rates = [];

            foreach ($detail['ratedShipmentDetails'] ?? [] as $rated) {
                $type = (string) ($rated['rateType'] ?? '');
                $rates[$type] = (float) ($rated['totalNetCharge'] ?? 0);
            }

            // ACCOUNT is the negotiated figure; PAYOR_ACCOUNT_* are the shapes
            // FedEx uses on some accounts for the same thing.
            $negotiated = null;

            foreach ($rates as $type => $amount) {
                if (str_contains($type, 'ACCOUNT') && $amount > 0) {
                    $negotiated = $amount;
                    break;
                }
            }

            $list      = $rates['LIST'] ?? null;
            $candidate = $negotiated ?? $list;

            if ($candidate === null || $candidate <= 0) {
                continue;
            }

            /*
             * If the negotiated rate is missing, or identical to published, the
             * vendor's account is not really linked and we would be quoting
             * retail on their behalf — which makes the product look overpriced
             * and nobody notices for weeks. Refuse rather than guess.
             */
            if (($shipping['carrier']['require_negotiated'] ?? true)
                && ($negotiated === null || ($list !== null && abs($negotiated - $list) < 0.005))) {
                continue;
            }

            if ($best === null || $candidate < $best['amount']) {
                $best = [
                    'amount'  => $candidate,
                    'service' => $detail['serviceName'] ?? $service,
                ];
            }
        }

        if ($best === null) {
            return ShippingQuote::freight(
                'FedEx returned no negotiated rate for this address. The carrier account may not be linked.'
            );
        }

        return new ShippingQuote(
            amount:  (int) round($best['amount'] * 100),
            source:  ShippingQuote::SOURCE_CARRIER,
            service: $best['service'],
            carrier: 'FedEx',
        );
    }

    /**
     * The request body.
     *
     * Dimensions are sent, not just weight, and this is the single most
     * important line in the class: 12 x 12 x 15 in DIM-weights to about 16 lb
     * against an actual 9 lb. Rate on weight alone and every quote is ~40%
     * light, absorbed by whoever eats the difference.
     *
     * @return array<string,mixed>
     */
    private function payload(string $account, array $origin, array $destination, array $parcel, int $cartons): array
    {
        $package = [
            'weight'     => ['units' => 'LB', 'value' => (float) ($parcel['weight_lb'] ?? 1)],
            'dimensions' => [
                'length' => (int) ($parcel['length_in'] ?? 1),
                'width'  => (int) ($parcel['width_in'] ?? 1),
                'height' => (int) ($parcel['height_in'] ?? 1),
                'units'  => 'IN',
            ],
        ];

        return [
            'accountNumber'     => ['value' => $account],
            'requestedShipment' => [
                'shipper'   => ['address' => $this->address($origin)],
                'recipient' => ['address' => $this->address($destination)],
                'pickupType'      => config('fedex.pickup_type', 'USE_SCHEDULED_PICKUP'),
                'rateRequestType' => array_values((array) config('fedex.rate_request_types', ['ACCOUNT', 'LIST'])),
                // One entry per carton — one unit per box for this product, so
                // three systems is three parcels rather than one heavier one.
                'requestedPackageLineItems' => array_fill(0, max(1, $cartons), $package),
            ],
        ];
    }

    /**
     * A FedEx address.
     *
     * `residential` is sent for a delivery the FedEx address check classified
     * as a home. Rated without it, a house is quoted as a business and the
     * residential surcharge is missing from the quote.
     *
     * @return array<string,mixed>
     */
    private function address(array $a): array
    {
        return array_filter([
            'city'                => $a['city'] ?? null,
            'stateOrProvinceCode' => $a['state'] ?? null,
            'postalCode'          => $a['postal_code'] ?? null,
            'countryCode'         => $a['country'] ?? 'US',
            'residential'         => ($a['residential'] ?? false) === true ? true : null,
        ], static fn ($v) => $v !== null && $v !== '');
    }

    /** The shared FedEx token; address validation uses the same one. */
    private function token(): ?string
    {
        return FedExAuth::token();
    }
}
