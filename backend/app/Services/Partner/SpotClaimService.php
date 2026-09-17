<?php

namespace App\Services\Partner;

use App\Models\PartnerCompany;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Taking ownership of an imported position.
 *
 * The threat this is written against: the user ids are not secret. They are
 * printed on the partner company's own material, they run in sequence, and
 * anybody who was ever a member of that company has a list of them. The
 * activation code is the only thing standing between a stranger and somebody
 * else's position — including their downline and whatever that downline earns.
 *
 * So the ceiling is per spot, not per visitor. Route throttling limits how fast
 * one IP can guess; it does nothing about a hundred IPs grinding the same spot,
 * which is the shape of attack that matters when the prize is a specific
 * position high in a tree. A spot locks itself after a handful of wrong codes
 * and stays locked whoever is asking.
 */
class SpotClaimService
{
    public function __construct(private PartnerWebhookDispatcher $webhooks) {}

    /** Wrong codes against one spot before it stops answering. */
    public const MAX_ATTEMPTS = 5;

    /** How long a spot stays locked once it hits the ceiling. */
    public const LOCKOUT_MINUTES = 30;

    public const RESULT_OK           = 'ok';
    public const RESULT_UNKNOWN      = 'unknown';
    public const RESULT_ALREADY      = 'already_claimed';
    public const RESULT_LOCKED       = 'locked';

    /**
     * Check a user id and activation code against one company's spots.
     *
     * @return array{result:string, spot:?User, locked_until:?Carbon}
     */
    public function verify(PartnerCompany $company, string $externalId, string $code): array
    {
        $spot = User::query()
            ->where('partner_company_id', $company->id)
            ->where('external_user_id', trim($externalId))
            ->first();

        if ($spot === null) {
            // Hash anyway, and compare against a decoy. Returning early on an
            // unknown id makes the reply measurably faster than a wrong code
            // against a real one, which turns this endpoint into a way to
            // enumerate which positions exist without spending a guess on a
            // code. The work is small either way now that codes are keyed
            // HMACs rather than bcrypt, but so is the difference, and the
            // difference is the leak.
            ActivationCode::matches($code, $this->decoyDigest());

            return ['result' => self::RESULT_UNKNOWN, 'spot' => null, 'locked_until' => null];
        }

        if ($spot->claim_locked_until !== null) {
            if ($spot->claim_locked_until->isFuture()) {
                return ['result' => self::RESULT_LOCKED, 'spot' => null, 'locked_until' => $spot->claim_locked_until];
            }

            // The lockout served its time. Clear the counter with it — leaving
            // it at the ceiling means the owner's next single typo re-locks the
            // spot immediately, which is a lockout that never really lifts.
            $spot->forceFill(['claim_attempts' => 0, 'claim_locked_until' => null])->save();
        }

        if (! $spot->isHolding() || $spot->activation_code_hash === null) {
            // Someone has this position already. Said plainly, because the
            // common case by far is the owner coming back to a page they have
            // already been through and needing to be sent to the login form.
            return ['result' => self::RESULT_ALREADY, 'spot' => null, 'locked_until' => null];
        }

        if (! ActivationCode::matches($code, $spot->activation_code_hash)) {
            $this->recordFailure($spot);

            return $spot->claim_locked_until?->isFuture()
                ? ['result' => self::RESULT_LOCKED, 'spot' => null, 'locked_until' => $spot->claim_locked_until]
                : ['result' => self::RESULT_UNKNOWN, 'spot' => null, 'locked_until' => null];
        }

        // A correct code clears the counter, so a member who fumbled their code
        // twice last week is not one mistake away from a lockout today.
        if ($spot->claim_attempts > 0 || $spot->claim_locked_until !== null) {
            $spot->forceFill(['claim_attempts' => 0, 'claim_locked_until' => null])->save();
        }

        return ['result' => self::RESULT_OK, 'spot' => $spot, 'locked_until' => null];
    }

    /**
     * Fill in a verified spot and hand it to its owner.
     *
     * The position does not move. Everything about where they sit was decided
     * at import; this writes the person onto it.
     *
     * @param  array{name:string, email:string, password:string, phone?:?string,
     *               address_line1?:?string, address_line2?:?string, city?:?string,
     *               state?:?string, postal_code?:?string, country?:?string}  $details
     *
     * @throws SpotAlreadyClaimed When somebody got there first.
     */
    public function claim(User $spot, array $details): User
    {
        return DB::transaction(function () use ($spot, $details) {
            // Re-read under a lock. Two tabs, or a double-submitted form, must
            // not both get past the holding check and write the account twice —
            // the second would overwrite the first owner's password with the
            // second's.
            $fresh = User::query()->lockForUpdate()->findOrFail($spot->id);

            if (! $fresh->isHolding()) {
                throw new SpotAlreadyClaimed();
            }

            $fresh->fill([
                'name'          => $details['name'],
                'email'         => strtolower(trim($details['email'])),
                'password'      => $details['password'],
                'phone'         => $details['phone'] ?? $fresh->phone,
                'address_line1' => $details['address_line1'] ?? null,
                'address_line2' => $details['address_line2'] ?? null,
                'city'          => $details['city'] ?? $fresh->city,
                'state'         => $details['state'] ?? $fresh->state,
                'postal_code'   => $details['postal_code'] ?? $fresh->postal_code,
                'country'       => $details['country'] ?? $fresh->country,
            ]);

            $fresh->account_status = User::ACCOUNT_ACTIVE;
            $fresh->is_active      = true;
            $fresh->claimed_at     = Carbon::now();

            // Imported positions are created in bulk, which bypasses the model
            // event that hands out referral codes — and an unclaimed position
            // has nobody to share a link anyway. Now it does.
            if (blank($fresh->referral_code)) {
                $fresh->referral_code = $this->freshReferralCode();
            }

            // Spent. The code cannot be replayed to take the position back off
            // the person who just claimed it.
            $fresh->activation_code_hash = null;
            $fresh->claim_attempts       = 0;
            $fresh->claim_locked_until   = null;

            $fresh->save();

            // Recorded here, so the payload is frozen against the state being
            // committed; the delivery job is queued afterCommit by the
            // dispatcher, so no worker can act on a claim that has not landed.
            // A partner endpoint is never allowed to fail a claim.
            $this->webhooks->spotClaimed($fresh);

            return $fresh;
        });
    }

    /**
     * Issue a fresh code for one spot, returning the plaintext once.
     *
     * The support path for "I lost my code". It returns the code to the caller
     * and stores only the hash, so this is the single moment the plaintext
     * exists — an admin reads it to the member and it is gone.
     */
    public function reissueCode(User $spot): string
    {
        // Unambiguous alphabet: no O/0, no I/1. These get read down a phone.
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $code = '';

        for ($i = 0; $i < 10; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        $code = substr($code, 0, 5) . '-' . substr($code, 5);

        $spot->forceFill([
            'activation_code_hash' => ActivationCode::hash($code),
            'claim_attempts'       => 0,
            'claim_locked_until'   => null,
        ])->save();

        return $code;
    }

    private function recordFailure(User $spot): void
    {
        $attempts = $spot->claim_attempts + 1;

        $spot->forceFill([
            'claim_attempts'     => $attempts,
            'claim_locked_until' => $attempts >= self::MAX_ATTEMPTS
                ? Carbon::now()->addMinutes(self::LOCKOUT_MINUTES)
                : $spot->claim_locked_until,
        ])->save();
    }

    /**
     * An unused referral code.
     *
     * The same alphabet and length User::booted() uses, so a claimed position's
     * code is indistinguishable from any other partner's.
     */
    private function freshReferralCode(): string
    {
        do {
            $code = strtoupper(Str::random(8));
        } while (User::where('referral_code', $code)->exists());

        return $code;
    }

    /**
     * A digest of a value nobody holds, for the unknown-id branch to compare
     * against so that branch does the same work as the real one.
     */
    private function decoyDigest(): string
    {
        static $decoy = null;

        return $decoy ??= ActivationCode::hash(Str::random(40));
    }
}
