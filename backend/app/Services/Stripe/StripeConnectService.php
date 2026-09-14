<?php

namespace App\Services\Stripe;

use App\Exceptions\BillingException;
use App\Models\ConnectIdentityClaim;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Stripe\Account;
use Stripe\AccountSession;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\InvalidRequestException;

/**
 * Stripe Connect: the account a partner sets up so we can pay them commissions.
 *
 * Onboarding runs inside the Q3 back office through Stripe's embedded
 * components. The platform owns requirement collection
 * (`controller.requirement_collection = application`), which is what keeps the
 * whole flow on our page: when Stripe owns collection it forces its own sign-in
 * step, and that step opens a pop-up window. The price of owning collection is
 * that there is no Stripe dashboard for partners, so bank changes happen through
 * the embedded account-management component on our Get Paid page, and the
 * platform carries fees and negative balances.
 *
 * ── One person, one payout account ────────────────────────────────────────────
 *   1. users.stripe_connect_account_id is unique, so one connected account can
 *      never back two partners.
 *   2. Bank-account fingerprints are claimed in connect_identity_claims, so the
 *      same real bank account cannot be attached under a second partner.
 * Name + date-of-birth claims are recorded too, but only advisory.
 *
 * ── US tax reporting ──────────────────────────────────────────────────────────
 * Q3 is the payer of record for partner commissions, so partners paid $600 or
 * more in a year need a 1099. Stripe files it on our behalf, but only holds a
 * filing-grade legal name, address and TIN if the `tax_reporting_us_1099_misc`
 * capability was requested. It is requested when the account is created, so
 * nobody has to be chased for tax details in January. (There is no `_nec`
 * capability; `_misc` collects the data, and the form type is a Dashboard
 * setting.)
 */
class StripeConnectService
{
    public const TAX_CAPABILITY = 'tax_reporting_us_1099_misc';

    private const US_STATES = [
        'alabama' => 'AL', 'alaska' => 'AK', 'arizona' => 'AZ', 'arkansas' => 'AR', 'california' => 'CA',
        'colorado' => 'CO', 'connecticut' => 'CT', 'delaware' => 'DE', 'district of columbia' => 'DC',
        'florida' => 'FL', 'georgia' => 'GA', 'hawaii' => 'HI', 'idaho' => 'ID', 'illinois' => 'IL',
        'indiana' => 'IN', 'iowa' => 'IA', 'kansas' => 'KS', 'kentucky' => 'KY', 'louisiana' => 'LA',
        'maine' => 'ME', 'maryland' => 'MD', 'massachusetts' => 'MA', 'michigan' => 'MI', 'minnesota' => 'MN',
        'mississippi' => 'MS', 'missouri' => 'MO', 'montana' => 'MT', 'nebraska' => 'NE', 'nevada' => 'NV',
        'new hampshire' => 'NH', 'new jersey' => 'NJ', 'new mexico' => 'NM', 'new york' => 'NY',
        'north carolina' => 'NC', 'north dakota' => 'ND', 'ohio' => 'OH', 'oklahoma' => 'OK', 'oregon' => 'OR',
        'pennsylvania' => 'PA', 'rhode island' => 'RI', 'south carolina' => 'SC', 'south dakota' => 'SD',
        'tennessee' => 'TN', 'texas' => 'TX', 'utah' => 'UT', 'vermont' => 'VT', 'virginia' => 'VA',
        'washington' => 'WA', 'west virginia' => 'WV', 'wisconsin' => 'WI', 'wyoming' => 'WY',
    ];

    public function __construct(private readonly StripeClientFactory $stripe) {}

    public function enabled(): bool
    {
        return (bool) config('stripe.connect.enabled');
    }

    private function assertEnabled(): void
    {
        if (! $this->enabled()) {
            throw BillingException::connectDisabled();
        }
    }

    /**
     * Do we collect KYC requirements ourselves rather than Stripe?
     *
     * The single switch behind "does onboarding open a pop-up". Stripe only
     * accepts `disable_stripe_user_authentication` when the platform owns
     * collection.
     */
    public function platformOwnsRequirements(): bool
    {
        return config('stripe.connect.requirement_collection') === 'application';
    }

    /**
     * The `controller` block that decides the account's whole shape.
     *
     * Stripe validates these together: owning requirement collection obliges us
     * to own fees and losses, and to ask for no Stripe dashboard. The dashboard
     * type is immutable per account, so changing this only affects accounts
     * created afterwards (see `connect:reset-account`).
     */
    private function controllerParams(): array
    {
        $controller = [
            'fees'   => ['payer' => config('stripe.connect.fees_payer', 'application')],
            'losses' => ['payments' => config('stripe.connect.losses_payer', 'application')],
        ];

        if (! $this->platformOwnsRequirements()) {
            $controller['stripe_dashboard'] = ['type' => 'express'];

            return $controller;
        }

        $controller['requirement_collection'] = 'application';
        $controller['stripe_dashboard'] = ['type' => 'none'];

        return $controller;
    }

    // ── Account creation ──────────────────────────────────────────────────────

    /** The partner's connected account id, creating the account on first use. */
    public function accountFor(User $user): string
    {
        $this->assertEnabled();

        if ($user->stripe_connect_account_id) {
            return $user->stripe_connect_account_id;
        }

        $base = $this->baseAccountParams($user);

        try {
            try {
                $account = $this->createAccount($user, array_replace_recursive($base, $this->prefillParams($user)));
            } catch (InvalidRequestException $e) {
                // Stripe refused a prefilled value. Every one of them is something
                // the partner can type into Stripe's form, so start without them
                // rather than leave the partner unable to set up at all.
                if (! $this->isPrefillError($e)) {
                    throw $e;
                }

                Log::warning('Stripe refused prefilled partner details; creating the account without them', [
                    'user_id' => $user->id,
                    'param'   => $e->getError()->param ?? null,
                    'error'   => $e->getMessage(),
                ]);

                $account = $this->createAccount($user, $base);
            }
        } catch (ApiErrorException $e) {
            Log::error('Stripe Connect account create failed', [
                'user_id' => $user->id,
                'error'   => $e->getMessage(),
            ]);

            throw new BillingException(
                'We could not start your payout account setup: '.($e->getError()->message ?? 'payment provider error')
            );
        }

        try {
            // Assigned directly: the account id is never mass-assignable.
            $user->stripe_connect_account_id = $account->id;
            $user->save();
        } catch (QueryException) {
            Log::error('Connect account id collision', ['user_id' => $user->id, 'account' => $account->id]);

            throw BillingException::duplicateConnectIdentity();
        }

        return $account->id;
    }

    private function createAccount(User $user, array $params): Account
    {
        return $this->stripe->client()->accounts->create($params, [
            // Same parameters within 24 hours return the same account, so two
            // tabs opening Get Paid at once cannot create two accounts.
            'idempotency_key' => $this->stripe->idempotencyKey('connect_account_user_'.$user->id, $params),
        ]);
    }

    /** What every account is created with, prefill or not. */
    private function baseAccountParams(User $user): array
    {
        return [
            'country' => config('stripe.connect.account_country', 'US'),
            'email'   => $user->email,

            // Money only ever flows out to these accounts. The tax capability adds
            // no funds flow; it makes Stripe collect what a 1099 needs.
            'capabilities' => $this->requestedCapabilities(),

            // `controller` replaces `type`; Stripe rejects a request with both.
            'controller' => $this->controllerParams(),

            // The company site, not the partner's referral link: Stripe reviews
            // this URL, and an invite page does not pass its website check.
            'business_profile' => [
                'url'                 => config('stripe.connect.business_url'),
                'product_description' => config('stripe.connect.product_description'),
            ],

            'metadata' => [
                'user_id'       => (string) $user->id,
                'referral_code' => (string) $user->referral_code,
                'app'           => 'q3',
            ],
        ];
    }

    /**
     * What we already know about the partner, so Stripe's form opens filled in.
     *
     * Only values Stripe will accept are sent. The profile form takes free text,
     * and one malformed field (a state spelled out, a phone without an area code)
     * makes Stripe refuse the whole account, so anything that does not normalise
     * cleanly is left for the partner to enter in Stripe's form. The partner
     * reviews every prefilled value during onboarding.
     *
     * @return array<string, mixed>
     */
    public function prefillParams(User $user): array
    {
        [$first, $last] = $this->splitName((string) $user->name);

        $params = [
            'business_type' => 'individual',
            'individual'    => array_filter([
                'first_name' => $first,
                'last_name'  => $last,
                'email'      => $user->email,
                'phone'      => $this->usPhone($user->phone),
                'address'    => $this->usAddress($user),
            ]),
        ];

        return $params;
    }

    private function isPrefillError(InvalidRequestException $e): bool
    {
        $param = (string) ($e->getError()->param ?? '');

        return (bool) preg_match('/^(individual|business_type)/', $param);
    }

    /** @return array{0: ?string, 1: ?string} */
    private function splitName(string $name): array
    {
        $parts = preg_split('/\s+/', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if (count($parts) < 2) {
            return [$parts[0] ?? null, null];
        }

        $last = array_pop($parts);

        return [implode(' ', $parts), $last];
    }

    /** E.164 for a valid US number, otherwise null. */
    private function usPhone(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';

        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            $digits = substr($digits, 1);
        }

        // Area code and exchange never start with 0 or 1.
        return preg_match('/^[2-9]\d{2}[2-9]\d{6}$/', $digits) ? '+1'.$digits : null;
    }

    /** A complete US address in Stripe's shape, or null if any part would be refused. */
    private function usAddress(User $user): ?array
    {
        // Membership is US-only, so a blank country is read as US.
        $country = strtolower(trim((string) $user->country));

        if (! in_array($country, ['', 'us', 'usa', 'u.s.', 'u.s.a.', 'united states', 'united states of america'], true)) {
            return null;
        }

        $state  = $this->usStateCode($user->state);
        $postal = trim((string) $user->postal_code);

        if (blank($user->address_line1) || blank($user->city) || $state === null || ! preg_match('/^\d{5}(-\d{4})?$/', $postal)) {
            return null;
        }

        return array_filter([
            'line1'       => trim((string) $user->address_line1),
            'line2'       => filled($user->address_line2) ? trim((string) $user->address_line2) : null,
            'city'        => trim((string) $user->city),
            'state'       => $state,
            'postal_code' => $postal,
            'country'     => 'US',
        ]);
    }

    private function usStateCode(?string $state): ?string
    {
        $value = strtolower(trim((string) $state, " \t\n\r\0\x0B."));

        if (strlen($value) === 2 && in_array(strtoupper($value), self::US_STATES, true)) {
            return strtoupper($value);
        }

        return self::US_STATES[$value] ?? null;
    }

    /** @return array<string, array{requested: bool}> */
    private function requestedCapabilities(): array
    {
        $capabilities = ['transfers' => ['requested' => true]];

        if (config('stripe.connect.tax_reporting')) {
            $capabilities[self::TAX_CAPABILITY] = ['requested' => true];
        }

        return $capabilities;
    }

    // ── Embedded components ───────────────────────────────────────────────────

    /**
     * A short-lived client secret for the embedded Connect components.
     *
     * Stripe requires `disable_stripe_user_authentication` to be identical on
     * every component in a session, and only accepts it at all when the
     * platform owns requirement collection.
     */
    public function createAccountSession(User $user): AccountSession
    {
        $this->assertEnabled();

        $noStripeLogin = $this->platformOwnsRequirements();

        $features = fn (array $extra) => $noStripeLogin
            ? $extra + ['disable_stripe_user_authentication' => true]
            : $extra;

        $components = [
            'account_onboarding' => [
                'enabled'  => true,
                'features' => $features(['external_account_collection' => true]),
            ],
            'payouts' => [
                'enabled'  => true,
                'features' => $features([
                    'instant_payouts'             => false,
                    'standard_payouts'            => true,
                    'edit_payout_schedule'        => false,
                    'external_account_collection' => true,
                ]),
            ],
            'notification_banner' => [
                'enabled'  => true,
                'features' => $features(['external_account_collection' => true]),
            ],
        ];

        // No Stripe dashboard to send a partner to for bank changes, so the
        // back office hosts that too.
        if ($noStripeLogin) {
            $components['account_management'] = [
                'enabled'  => true,
                'features' => $features(['external_account_collection' => true]),
            ];
        }

        return $this->stripe->client()->accountSessions->create([
            'account'    => $this->accountFor($user),
            'components' => $components,
        ]);
    }

    /** Stripe-hosted onboarding, for when the embedded component cannot load. */
    public function createAccountLink(User $user, string $returnUrl, string $refreshUrl): string
    {
        $this->assertEnabled();

        return $this->stripe->client()->accountLinks->create([
            'account'            => $this->accountFor($user),
            'type'               => 'account_onboarding',
            'return_url'         => $returnUrl,
            'refresh_url'        => $refreshUrl,
            'collection_options' => ['fields' => 'eventually_due', 'future_requirements' => 'include'],
        ])->url;
    }

    // ── Status sync ───────────────────────────────────────────────────────────

    /**
     * Pull the latest account state onto the user.
     *
     * Called from the Get Paid page, the `account.updated` webhook, the admin
     * screens, and just before a transfer, so money never moves on a stale idea
     * of whether payouts are enabled.
     *
     * @param  bool  $enforceIdentity  Refuse a bank account already claimed by
     *                                 another partner. True while onboarding; false for status refreshes,
     *                                 where blocking money a partner has already earned is the wrong remedy for
     *                                 a duplicate suspicion. The claim is recorded either way.
     */
    public function syncAccount(User $user, bool $enforceIdentity = true): ?Account
    {
        if (! $user->stripe_connect_account_id) {
            return null;
        }

        try {
            $account = $this->stripe->client()->accounts->retrieve(
                $user->stripe_connect_account_id,
                ['expand' => ['external_accounts']],
            );
        } catch (ApiErrorException $e) {
            Log::warning('Connect account retrieve failed', [
                'user_id' => $user->id,
                'error'   => $e->getMessage(),
            ]);

            return null;
        }

        $this->applyAccount($user, $account, $enforceIdentity);

        return $account;
    }

    /** Write a retrieved Account onto the user. */
    public function applyAccount(User $user, Account $account, bool $enforceIdentity = true): void
    {
        $requirements = $account->requirements?->toArray();
        $future       = $account->future_requirements?->toArray();

        $user->connect_charges_enabled   = (bool) $account->charges_enabled;
        $user->connect_payouts_enabled   = (bool) $account->payouts_enabled;
        $user->connect_details_submitted = (bool) $account->details_submitted;

        // Stripe omits a capability until it is requested, so absence means
        // "never asked for" rather than "not ready".
        $capabilities = $account->capabilities?->toArray() ?? [];
        $user->connect_tax_reporting_status = $capabilities[self::TAX_CAPABILITY] ?? 'unrequested';

        $user->connect_requirements = $requirements === null ? null : [
            'currently_due'        => $requirements['currently_due'] ?? [],
            'eventually_due'       => $requirements['eventually_due'] ?? [],
            'past_due'             => $requirements['past_due'] ?? [],
            'pending_verification' => $requirements['pending_verification'] ?? [],
            'disabled_reason'      => $requirements['disabled_reason'] ?? null,
            'current_deadline'     => $requirements['current_deadline'] ?? null,
            'errors'               => $this->errorList($requirements['errors'] ?? []),

            // What Stripe will ask for later, typically once payouts approach
            // $3,000. Kept so it can be collected before it pauses payouts.
            'future' => $future === null ? null : [
                'currently_due'    => $future['currently_due'] ?? [],
                'eventually_due'   => $future['eventually_due'] ?? [],
                'current_deadline' => $future['current_deadline'] ?? null,
                'errors'           => $this->errorList($future['errors'] ?? []),
            ],
        ];

        $user->connect_synced_at = now();
        $user->save();

        $this->recordIdentityClaims($user, $account, $enforceIdentity);
    }

    /** @return array<int, array{code: ?string, reason: ?string, requirement: ?string}> */
    private function errorList(array $errors): array
    {
        return array_map(fn ($error) => [
            'code'        => $error['code'] ?? null,
            'reason'      => $error['reason'] ?? null,
            'requirement' => $error['requirement'] ?? null,
        ], $errors);
    }

    // ── Duplicate-person safeguards ───────────────────────────────────────────

    /**
     * Claim the identifiers this account exposes.
     *
     * @throws BillingException when a bank account already belongs to another partner.
     */
    public function recordIdentityClaims(User $user, Account $account, bool $enforceIdentity = true): void
    {
        $enforce = $enforceIdentity && (bool) config('stripe.safeguards.enforce_connect_uniqueness');

        foreach ($this->extractClaims($account) as [$type, $identifier, $sourceRef, $enforceable]) {
            $hash = ConnectIdentityClaim::hashFor($type, $identifier);

            $conflict = ConnectIdentityClaim::where('claim_hash', $hash)
                ->where('claim_type', $type)
                ->where('user_id', '!=', $user->id)
                ->exists();

            if ($conflict) {
                if ($enforce && $enforceable) {
                    Log::warning('Connect identity conflict blocked', ['user_id' => $user->id, 'claim_type' => $type]);

                    throw BillingException::duplicateConnectIdentity();
                }

                Log::notice('Connect identity duplicate recorded (advisory)', ['user_id' => $user->id, 'claim_type' => $type]);
            }

            try {
                ConnectIdentityClaim::updateOrCreate(
                    ['user_id' => $user->id, 'claim_type' => $type, 'claim_hash' => $hash],
                    [
                        // Only enforced claims compete for the unique slot.
                        'unique_hash' => ($enforce && $enforceable) ? $hash : null,
                        'source_ref'  => $sourceRef,
                    ],
                );
            } catch (QueryException) {
                throw BillingException::duplicateConnectIdentity();
            }
        }
    }

    /** @return array<int, array{0: string, 1: string, 2: ?string, 3: bool}> type, identifier, source ref, enforceable */
    private function extractClaims(Account $account): array
    {
        $claims = [];

        foreach ($account->external_accounts->data ?? [] as $external) {
            if (! empty($external->fingerprint)) {
                $claims[] = [ConnectIdentityClaim::TYPE_BANK_ACCOUNT, $external->fingerprint, $external->id ?? null, true];
            }
        }

        $individual = $account->individual ?? null;
        $dob        = $individual->dob ?? null;

        if ($individual && $dob && $dob->year && $dob->month && $dob->day) {
            $claims[] = [
                ConnectIdentityClaim::TYPE_INDIVIDUAL,
                sprintf('%s|%s|%04d-%02d-%02d', $individual->first_name ?? '', $individual->last_name ?? '', $dob->year, $dob->month, $dob->day),
                $account->id,
                false,
            ];
        }

        return $claims;
    }
}
