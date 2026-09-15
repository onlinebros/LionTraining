<?php

namespace App\Services\Vendor\Shipping;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * FedEx Address Validation — the carrier that actually delivers these orders.
 *
 * Uses our FedEx developer project (config/fedex.php), the same credentials as
 * rating. It answers three questions: can FedEx deliver here, what is the
 * address's standard form, and is it residential or business.
 *
 * Every failure to get an answer (no token, a 5xx, a timeout, the sandbox's
 * canned reply) is reported as unavailable, never as a bad address. The buyer
 * is then asked to confirm it, instead of being told a real address is wrong
 * because FedEx was down.
 */
class FedExAddressVerifier implements AddressVerifier
{
    private const NOT_FOUND = 'FedEx could not confirm this street address. Please check the house number, street and ZIP code.';

    public function verify(array $address): AddressVerification
    {
        if (blank(config('fedex.key')) || blank($address['address_line1'] ?? null) || blank($address['postal_code'] ?? null)) {
            return AddressVerification::unavailable();
        }

        try {
            $token = FedExAuth::token();

            if ($token === null) {
                return AddressVerification::unavailable();
            }

            $response = Http::withToken($token)
                ->timeout((int) config('fedex.timeout', 12))
                ->withHeaders(['X-locale' => 'en_US'])
                ->post(config('fedex.host').'/address/v1/addresses/resolve', [
                    'addressesToValidate' => [['address' => $this->request($address)]],
                ]);

            if (! $response->successful()) {
                Log::warning('FedEx address validation unavailable', [
                    'status' => $response->status(),
                    'error'  => $response->json('errors.0.code') ?? $response->body(),
                ]);

                return AddressVerification::unavailable();
            }

            return $this->interpret($address, (array) $response->json());
        } catch (\Throwable $e) {
            Log::error('FedEx address validation failed', ['error' => $e->getMessage()]);

            return AddressVerification::unavailable();
        }
    }

    /**
     * @param  array<string,?string>  $entered
     * @param  array<string,mixed>  $body
     */
    private function interpret(array $entered, array $body): AddressVerification
    {
        foreach ($body['output']['alerts'] ?? [] as $alert) {
            /*
             * FedEx's sandbox answers every request with one canned address (a
             * street in Chile, whatever was sent). Reading that as a real answer
             * would mark every address wrong, or worse, right.
             */
            if (($alert['code'] ?? null) === 'VIRTUAL.RESPONSE') {
                Log::info('FedEx address validation returned a sandbox virtual response');

                return AddressVerification::unavailable();
            }
        }

        $resolved = $body['output']['resolvedAddresses'][0] ?? null;

        if (! is_array($resolved)) {
            return new AddressVerification(AddressVerification::UNVERIFIED, self::NOT_FOUND);
        }

        $attributes = (array) ($resolved['attributes'] ?? []);

        // Production sends these as the strings "true" and "false".
        $flag = static fn (string $key): bool => filter_var($attributes[$key] ?? false, FILTER_VALIDATE_BOOLEAN);

        $classification = strtoupper((string) ($resolved['classification'] ?? '')) ?: null;

        if ($flag('POBox')) {
            return new AddressVerification(
                AddressVerification::REJECTED,
                'FedEx cannot deliver to a PO Box. Please enter a street address.',
                null,
                $classification,
            );
        }

        if (strtoupper((string) ($resolved['countryCode'] ?? '')) !== strtoupper((string) (($entered['country'] ?? null) ?: 'US'))) {
            return new AddressVerification(AddressVerification::UNVERIFIED, self::NOT_FOUND, null, $classification);
        }

        $problem = match (true) {
            $flag('SuiteRequiredButMissing') => 'This building needs an apartment, suite or unit number.',
            $flag('InvalidSuiteNumber')      => 'The apartment, suite or unit number was not recognised.',
            $flag('MultipleMatches')         => 'More than one address matches. Please check the street and ZIP code.',
            default                          => null,
        };

        if ($problem !== null) {
            return new AddressVerification(AddressVerification::UNVERIFIED, $problem, null, $classification);
        }

        /*
         * DPV (the postal delivery-point check) is the strongest signal and is
         * used whenever FedEx sends it. Without it, a resolved address is still
         * not trusted if the house number was interpolated from a street range
         * rather than matched.
         */
        $deliverable = match (true) {
            array_key_exists('DPV', $attributes)      => $flag('DPV'),
            array_key_exists('Resolved', $attributes) => $flag('Resolved') && ! $flag('InterpolatedStreetAddress'),
            default                                   => $flag('Matched') && ! $flag('InterpolatedStreetAddress'),
        };

        if (! $deliverable) {
            return new AddressVerification(AddressVerification::UNVERIFIED, self::NOT_FOUND, null, $classification);
        }

        $standard = $this->standardized($entered, $resolved);

        /*
         * FedEx confirmed it. If it only tidied the formatting ("Court" to "CT"),
         * its version is used. If it moved the address (a different ZIP, city,
         * state or house number), the buyer chooses: a confident correction to
         * the wrong place is how a $6,000 system ends up on the wrong doorstep.
         */
        return new AddressVerification(
            $this->samePlace($entered, $standard) ? AddressVerification::VERIFIED : AddressVerification::SUGGESTED,
            null,
            $standard,
            $classification,
        );
    }

    /**
     * FedEx's standard form, in our field names.
     *
     * @param  array<string,?string>  $entered
     * @param  array<string,mixed>  $resolved
     * @return array<string,?string>
     */
    private function standardized(array $entered, array $resolved): array
    {
        $lines = array_values(array_filter((array) ($resolved['streetLinesToken'] ?? []), 'filled'));
        $base  = $resolved['parsedPostalCode']['base'] ?? null;
        $addOn = $resolved['parsedPostalCode']['addOn'] ?? null;

        /*
         * FedEx often folds the unit into its first line, and sometimes leaves a
         * unit it could not validate out altogether. A second line the buyer
         * typed is kept unless FedEx's first line already carries it, so a unit
         * number is never silently dropped.
         */
        $line2 = $lines[1] ?? null;

        if ($line2 === null && filled($entered['address_line2'] ?? null)
            && ! str_contains($this->key($lines[0] ?? ''), $this->key($entered['address_line2']))) {
            $line2 = $entered['address_line2'];
        }

        return [
            'address_line1' => $lines[0] ?? $entered['address_line1'],
            'address_line2' => $line2,
            'city'          => filled($resolved['city'] ?? null) ? (string) $resolved['city'] : $entered['city'],
            'state'         => filled($resolved['stateOrProvinceCode'] ?? null) ? (string) $resolved['stateOrProvinceCode'] : $entered['state'],
            'postal_code'   => filled($base)
                ? $base.(filled($addOn) ? '-'.$addOn : '')
                : ((string) ($resolved['postalCode'] ?? '') ?: $entered['postal_code']),
            'country'       => strtoupper((string) ($resolved['countryCode'] ?? (($entered['country'] ?? null) ?: 'US'))),
        ];
    }

    /**
     * Is the standard form the same place as what the buyer typed?
     *
     * Compared on house number, city, state and the five-digit ZIP only.
     * Street-name spelling is left out, because standardizing it is exactly the
     * formatting change that should not need the buyer's decision.
     *
     * @param  array<string,?string>  $entered
     * @param  array<string,?string>  $standard
     */
    private function samePlace(array $entered, array $standard): bool
    {
        $houseNumber = static fn (?string $line): string => preg_match('/^\s*(\d+[A-Z]?)/i', (string) $line, $m)
            ? strtoupper($m[1])
            : '';

        return $this->key($entered['city'] ?? null) === $this->key($standard['city'] ?? null)
            && $this->key($entered['state'] ?? null) === $this->key($standard['state'] ?? null)
            && substr($this->key($entered['postal_code'] ?? null), 0, 5) === substr($this->key($standard['postal_code'] ?? null), 0, 5)
            && $houseNumber($entered['address_line1'] ?? null) === $houseNumber($standard['address_line1'] ?? null);
    }

    /**
     * @param  array<string,?string>  $address
     * @return array<string,mixed>
     */
    private function request(array $address): array
    {
        return array_filter([
            'streetLines'         => array_values(array_filter([$address['address_line1'] ?? null, $address['address_line2'] ?? null], 'filled')),
            'city'                => $address['city'] ?? null,
            'stateOrProvinceCode' => $address['state'] ?? null,
            'postalCode'          => $address['postal_code'] ?? null,
            'countryCode'         => ($address['country'] ?? null) ?: 'US',
        ], static fn ($v) => $v !== null && $v !== '' && $v !== []);
    }

    /** Uppercase letters and digits only. */
    private function key(?string $value): string
    {
        return preg_replace('/[^A-Z0-9]/', '', strtoupper((string) $value)) ?? '';
    }
}
