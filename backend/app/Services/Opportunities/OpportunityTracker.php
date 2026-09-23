<?php

namespace App\Services\Opportunities;

use App\Models\User;
use App\Models\UserOpportunity;
use App\Support\Opportunity;
use Illuminate\Support\Facades\Log;

/**
 * Carries "which front door did this person come through" from a marketing
 * site to the account they eventually create.
 *
 * One back office, one or more public sites, all posting the same /join/{code}
 * form. The form is the only place that can record which door somebody came
 * through — and it has to record it before the account exists.
 *
 * Deliberately the same shape as ConversionTracker: remember() on the way in,
 * attribute() on the way out, session in between. Two trackers with two
 * different shapes on the same sign-up flow is how one of them ends up not
 * being called.
 *
 * Neither method may ever cost somebody their registration. A lost attribution
 * is a marketing report with a hole in it; a thrown exception here is a person
 * who could not sign up.
 */
class OpportunityTracker
{
    /** ?o=plasmaguard — which business line the visitor is being sold. */
    public const PARAM = Opportunity::PARAM;

    /**
     * ?s=q3.life — which host they were on.
     *
     * Separate from `o` because one business line can be sold from several
     * sites: a replicated domain, a partner's own, a paid campaign's landing
     * page. Without it every PlasmaGuard signup looks like it came from the
     * same place. Falls back to the business line's configured site.
     */
    public const SITE_PARAM = 's';

    public const SESSION_KEY      = Opportunity::SESSION_KEY;
    public const SESSION_SITE_KEY = 'signup_entry_site';

    /**
     * Remember what this visitor is being sold, while they are on the sign-up
     * page.
     *
     * An unknown key is dropped rather than stored: the value arrives from a
     * public query string, and a mistyped or probed link must land somebody on
     * the ordinary sign-up rather than in a business line that does not exist.
     * Anything already remembered survives, so a stray `?o=` does not wipe a
     * good attribution from a minute ago.
     */
    public function remember(?string $opportunity, ?string $site = null): void
    {
        if ($key = Opportunity::sanitise($opportunity)) {
            session([self::SESSION_KEY => $key]);
        }

        if ($host = $this->host($site)) {
            session([self::SESSION_SITE_KEY => $host]);
        }
    }

    /** What is currently remembered, falling back to the default business line. */
    public function current(): Opportunity
    {
        return Opportunity::get(session(self::SESSION_KEY));
    }

    /**
     * Write the remembered door onto a brand new account.
     *
     * The business line becomes their primary, which is what decides whether a
     * card is asked for at all. Called from the sign-up flow immediately after
     * the account is created and before the redirect that would otherwise send
     * them to card capture.
     */
    public function attribute(User $user, ?string $opportunity = null, ?string $site = null): ?Opportunity
    {
        $key  = Opportunity::sanitise($opportunity) ?? session(self::SESSION_KEY);
        $host = $this->host($site) ?? session(self::SESSION_SITE_KEY);

        if (blank($key) && blank($host)) {
            return null;
        }

        try {
            $line = Opportunity::get($key);

            if (filled($key)) {
                $user->associateOpportunity($key, UserOpportunity::SOURCE_SIGNUP, primary: true);
            }

            $entrySite = $host ?: $line->site();

            if (filled($entrySite) && $user->entry_site !== $entrySite) {
                $user->forceFill(['entry_site' => $entrySite])->save();
            }

            $this->forget();

            return $line;
        } catch (\Throwable $e) {
            Log::warning('Could not record a signup opportunity', [
                'user'        => $user->id,
                'opportunity' => $key,
                'error'       => $e->getMessage(),
            ]);

            return null;
        }
    }

    public function forget(): void
    {
        session()->forget([self::SESSION_KEY, self::SESSION_SITE_KEY]);
    }

    /**
     * A bare hostname from whatever the query string carried.
     *
     * Accepts "q3.life" and "https://q3.life/some/page" alike, keeps
     * the host, drops the rest, and refuses anything that is not a plausible
     * hostname. This value is displayed to staff and filtered on, so it must
     * not be a place to park arbitrary text.
     */
    private function host(?string $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        $host = str_contains($value, '//') ? parse_url($value, PHP_URL_HOST) : $value;
        $host = strtolower(trim((string) $host));

        if ($host === '' || strlen($host) > 120 || ! preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $host)) {
            return null;
        }

        return $host;
    }
}
