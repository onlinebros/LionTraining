<?php

namespace App\Support;

/**
 * The single source of truth for how someone is allowed to create an account.
 *
 * The guard middleware, the Blade templates that render (or hide) a "create
 * account" link, and the tests all ask this class, so the door cannot end up
 * bolted on the server while the marketing page still invites people through
 * it — or the reverse, which is worse.
 */
class Registration
{
    /** Is an invitation required to create an account? */
    public static function inviteOnly(): bool
    {
        return (bool) config('registration.invite_only', true);
    }

    /** Inverse of inviteOnly(), for readability in Blade. */
    public static function openToPublic(): bool
    {
        return ! self::inviteOnly();
    }

    /**
     * Is $routeName one of the general signup routes the guard closes?
     *
     * An unlisted route is treated as OPEN. That is the opposite of how you
     * would build this if the list were the only defence, but it is not: the
     * invitation flow carries the sponsor, and everything else that creates a
     * user sits behind auth. Failing closed on unknown route names here would
     * mean a typo in the config silently bricks the login page instead of
     * showing up as an obviously unguarded /register.
     */
    public static function routeClosed(?string $routeName): bool
    {
        if ($routeName === null || ! self::inviteOnly()) {
            return false;
        }

        return in_array($routeName, config('registration.closed_routes', []), true);
    }

    public static function notice(): string
    {
        return (string) config('registration.notice', 'Registration is invitation only.');
    }
}
