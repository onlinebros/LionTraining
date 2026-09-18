<?php

namespace App\Services\Presentations;

use App\Models\PresentationAttendee;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Works out which guests went on to sign up.
 *
 * The hard part is that the email changes. People watch under a throwaway
 * address and sign up under their real one once they have decided, so matching
 * on email alone would lose most of the conversions that matter — and credit
 * the ones it did find to whoever happened to share an address.
 *
 * So the primary link is a token, minted when a guest clicks the call to action
 * and carried through to the signup form. It survives any change of address
 * because it never looks at the address. Email matching stays as a fallback for
 * the person who wandered off and came back a week later through some other
 * door, and is recorded as such so a member can see how confident the link is.
 */
class ConversionTracker
{
    public const BY_TOKEN  = 'token';
    public const BY_EMAIL  = 'email';
    public const BY_MANUAL = 'manual';

    /** The query parameter the call-to-action link carries. */
    public const PARAM = 'pv';

    /** Session key, so the token survives the trip through the landing page. */
    public const SESSION_KEY = 'presentation_cta_token';

    /**
     * Mint (or reuse) the token this guest's call-to-action link carries.
     *
     * Reused rather than regenerated so a guest who clicks twice, or comes back
     * to the room and clicks again, is still one person.
     */
    public function tokenFor(PresentationAttendee $attendee): string
    {
        if (blank($attendee->cta_token)) {
            $attendee->forceFill(['cta_token' => Str::random(40)])->save();
        }

        return $attendee->cta_token;
    }

    /** Remember the token while the visitor is on the sign-up page. */
    public function remember(?string $token): void
    {
        if (filled($token)) {
            session([self::SESSION_KEY => $token]);
        }
    }

    /**
     * Link a brand new account back to the guest who watched.
     *
     * Called from the signup flow. Deliberately forgiving — a failure here must
     * never cost somebody their registration — but it is the definitive path,
     * so it wins over anything email matching later decides.
     */
    public function attribute(User $user, ?string $token = null): ?PresentationAttendee
    {
        $token ??= session(self::SESSION_KEY);

        if (blank($token)) {
            return null;
        }

        try {
            $attendee = PresentationAttendee::where('cta_token', $token)->first();

            if (! $attendee || $attendee->converted_user_id) {
                return $attendee;
            }

            $this->markConverted($attendee, $user, self::BY_TOKEN);

            // Everything else this person watched under the same address is the
            // same person, so credit it too — otherwise a member sees one
            // conversion and three unconverted rows for the same human.
            $this->spreadToSiblings($attendee, $user, self::BY_TOKEN);

            session()->forget(self::SESSION_KEY);

            return $attendee->refresh();
        } catch (\Throwable $e) {
            Log::warning('Could not attribute a presentation conversion', [
                'user'  => $user->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Fallback sweep: guests whose watching address matches an account.
     *
     * Only fills gaps — never overrides a token match, which is the stronger
     * claim. Run on a schedule; cheap, since it only looks at guests who have
     * not been linked yet.
     *
     * @return int how many were newly linked
     */
    public function sweepByEmail(): int
    {
        $linked = 0;

        PresentationAttendee::whereNull('converted_user_id')
            ->select(['id', 'email', 'host_user_id', 'converted_user_id'])
            ->chunkById(500, function ($attendees) use (&$linked) {
                $emails = $attendees->pluck('email')->unique()->all();
                $users  = User::whereIn('email', $emails)->get()->keyBy(
                    fn (User $u) => mb_strtolower($u->email)
                );

                foreach ($attendees as $attendee) {
                    $user = $users->get(mb_strtolower($attendee->email));

                    if (! $user) {
                        continue;
                    }

                    $this->markConverted($attendee, $user, self::BY_EMAIL);
                    $linked++;
                }
            });

        return $linked;
    }

    /** An admin or member saying "this guest is this person". */
    public function linkManually(PresentationAttendee $attendee, User $user): void
    {
        $this->markConverted($attendee, $user, self::BY_MANUAL);
        $this->spreadToSiblings($attendee, $user, self::BY_MANUAL);
    }

    // ── Internals ─────────────────────────────────────────────────────────────

    private function markConverted(PresentationAttendee $attendee, User $user, string $how): void
    {
        $attendee->forceFill([
            'converted_user_id' => $user->id,
            'converted_at'      => now(),
            'conversion_match'  => $how,
        ])->save();
    }

    /**
     * The same person, on the same member's other showings.
     *
     * Matched on the watching address rather than the signup one: these are the
     * rows that share the throwaway email, which is exactly the set we want.
     */
    private function spreadToSiblings(PresentationAttendee $attendee, User $user, string $how): void
    {
        PresentationAttendee::where('email', $attendee->email)
            ->where('id', '!=', $attendee->id)
            ->whereNull('converted_user_id')
            ->get()
            ->each(fn (PresentationAttendee $sibling) => $this->markConverted($sibling, $user, $how));
    }
}
