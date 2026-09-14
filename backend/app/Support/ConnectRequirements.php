<?php

namespace App\Support;

/**
 * Turns Stripe's requirement codes into words a partner or admin can act on.
 *
 * Stripe returns machine codes such as `individual.dob.day`, and the same real
 * question arrives at several granularities: `dob.day`, `dob.month` and
 * `dob.year` are one request for a date of birth. This collapses codes to the
 * thing being asked for and names it.
 */
class ConnectRequirements
{
    /**
     * Code prefix => what is being asked for. Longest prefix wins. Codes with no
     * phrasing fall through to a readable form of the code rather than being
     * hidden.
     */
    private const LABELS = [
        'individual.dob' => 'Date of birth',
        'individual.verification.document' => 'Identity document',
        'individual.verification.additional_document' => 'Additional identity document',
        'individual.id_number' => 'Full SSN / tax ID',
        'individual.ssn_last_4' => 'Last 4 of SSN',
        'individual.address' => 'Home address',
        'individual.phone' => 'Phone number',
        'individual.email' => 'Email address',
        'individual.first_name' => 'First name',
        'individual.last_name' => 'Last name',
        'individual.political_exposure' => 'Political exposure declaration',
        'individual.relationship' => 'Relationship to the business',

        'business_profile.url' => 'Business website',
        'business_profile.mcc' => 'Business category',
        'business_profile.product_description' => 'Business description',
        'business_type' => 'Business type',
        'company.tax_id' => 'Business tax ID (EIN)',
        'company.name' => 'Legal business name',
        'company.address' => 'Business address',
        'company.verification.document' => 'Business verification document',
        'company.owners_provided' => 'Confirmation of business owners',
        'company.directors_provided' => 'Confirmation of company directors',

        'external_account' => 'Bank account for payouts',
        'tos_acceptance' => 'Acceptance of Stripe’s terms',
    ];

    /** Requirements only the partner can supply, as an upload. */
    private const DOCUMENT_CODES = [
        'individual.verification.document',
        'individual.verification.additional_document',
        'company.verification.document',
    ];

    public static function label(string $code): string
    {
        // Person-scoped codes arrive prefixed with the person id; that names who, not what.
        $normalised = self::normalise($code);
        $best = null;

        foreach (array_keys(self::LABELS) as $prefix) {
            if (($normalised === $prefix || str_starts_with($normalised, $prefix.'.'))
                && ($best === null || strlen($prefix) > strlen($best))) {
                $best = $prefix;
            }
        }

        return $best !== null
            ? self::LABELS[$best]
            : ucfirst(str_replace(['_', '.'], [' ', ' → '], $code));
    }

    /**
     * The distinct things a list of codes is asking for.
     *
     * @param  array<int, string>  $codes
     * @return array<int, string>
     */
    public static function summarise(array $codes): array
    {
        return array_values(array_unique(array_map([self::class, 'label'], $codes)));
    }

    /**
     * Does this include something only the partner can upload?
     *
     * @param  array<int, string>  $codes
     */
    public static function needsDocument(array $codes): bool
    {
        foreach ($codes as $code) {
            $normalised = self::normalise($code);

            foreach (self::DOCUMENT_CODES as $document) {
                if ($normalised === $document || str_starts_with($normalised, $document.'.')) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function normalise(string $code): string
    {
        return preg_replace('/^person_[A-Za-z0-9]+\./', 'individual.', $code) ?? $code;
    }
}
