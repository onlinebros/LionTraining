<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

/**
 * Where a vendor order can be delivered, and what a valid address looks like
 * in each place.
 *
 * Canada was added 2026-10-28 after a Canadian buyer could not get past the
 * shipping quote: the form had no country, so their address went to FedEx as a
 * US one and neither the address check nor the rate could resolve it.
 *
 * The province is stored as its two-letter code and the postal code as
 * "A1A 1A1". FedEx and Stripe Tax both want the code, and the address check
 * compares what was typed with what FedEx sends back — "Ontario" against "ON"
 * would read as FedEx moving the address and ask the buyer to choose.
 */
final class ShippingCountries
{
    public const DEFAULT = 'US';

    /** @var array<string,string> */
    public const NAMES = [
        'US' => 'United States',
        'CA' => 'Canada',
    ];

    /** @var array<string,string> */
    public const PROVINCES = [
        'AB' => 'Alberta',
        'BC' => 'British Columbia',
        'MB' => 'Manitoba',
        'NB' => 'New Brunswick',
        'NL' => 'Newfoundland and Labrador',
        'NS' => 'Nova Scotia',
        'NT' => 'Northwest Territories',
        'NU' => 'Nunavut',
        'ON' => 'Ontario',
        'PE' => 'Prince Edward Island',
        'QC' => 'Quebec',
        'SK' => 'Saskatchewan',
        'YT' => 'Yukon',
    ];

    /** @return list<string> */
    public static function codes(): array
    {
        return array_keys(self::NAMES);
    }

    public static function name(?string $code): string
    {
        return self::NAMES[strtoupper((string) $code)] ?? (string) $code;
    }

    public static function isDomestic(?string $code): bool
    {
        return strtoupper((string) ($code ?: self::DEFAULT)) === self::DEFAULT;
    }

    /** "State" or "Province", for labels and messages. */
    public static function regionLabel(?string $code): string
    {
        return strtoupper((string) $code) === 'CA' ? 'Province' : 'State';
    }

    /** "ZIP code" or "Postal code", for labels and messages. */
    public static function postalLabel(?string $code): string
    {
        return strtoupper((string) $code) === 'CA' ? 'Postal code' : 'ZIP code';
    }

    /**
     * Normalise and check the country-specific parts of a validated address.
     *
     * Runs after the ordinary validation rules, on their output. A US address
     * passes through unchanged, as it always has.
     *
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     *
     * @throws ValidationException
     */
    public static function normalize(array $data): array
    {
        $country = strtoupper((string) (($data['country'] ?? null) ?: self::DEFAULT));
        $data['country'] = $country;

        if ($country !== 'CA') {
            return $data;
        }

        $province = self::provinceCode((string) ($data['state'] ?? ''));
        $postal   = self::canadianPostal((string) ($data['postal_code'] ?? ''));
        $errors   = [];

        if ($province === null) {
            $errors['state'] = 'Choose a Canadian province or territory, for example ON or Ontario.';
        }

        if ($postal === null) {
            $errors['postal_code'] = 'Enter a Canadian postal code, for example K1A 0B1.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $data['state']       = $province;
        $data['postal_code'] = $postal;

        return $data;
    }

    /** "ontario", "Ont.", "ON" → "ON"; null when it is not a province. */
    public static function provinceCode(string $value): ?string
    {
        $key = preg_replace('/[^A-Z]/', '', strtoupper($value)) ?? '';

        if (isset(self::PROVINCES[$key])) {
            return $key;
        }

        foreach (self::PROVINCES as $code => $name) {
            if ($key === preg_replace('/[^A-Z]/', '', strtoupper($name))) {
                return $code;
            }
        }

        // The French names buyers in Quebec and New Brunswick may well type.
        return match ($key) {
            'QUBEC', 'QUEBEC', 'QUE', 'PQ'  => 'QC',
            'ONT'                           => 'ON',
            'NOUVEAUBRUNSWICK'              => 'NB',
            'NOUVELLECOSSE', 'NOUVELLEECOSSE' => 'NS',
            'COLOMBIEBRITANNIQUE'           => 'BC',
            'NEWFOUNDLANDLABRADOR', 'NEWFOUNDLAND', 'LABRADOR', 'NF', 'NFLD' => 'NL',
            'PEI'                           => 'PE',
            'YUKONTERRITORY'                => 'YT',
            default                         => null,
        };
    }

    /**
     * "k1a0b1", "K1A-0B1" → "K1A 0B1"; null when it is not a postal code.
     *
     * Canada Post never uses D, F, I, O, Q or U, nor W or Z as the first
     * letter, so a typed O-for-zero is caught here rather than by FedEx.
     */
    public static function canadianPostal(string $value): ?string
    {
        $key = preg_replace('/[^A-Z0-9]/', '', strtoupper($value)) ?? '';

        if (! preg_match('/^[ABCEGHJ-NPRSTVXY]\d[ABCEGHJ-NPRSTV-Z]\d[ABCEGHJ-NPRSTV-Z]\d$/', $key)) {
            return null;
        }

        return substr($key, 0, 3).' '.substr($key, 3);
    }
}
