<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * The single source of truth for what the pre-launch guard closes.
 *
 * Both the middleware and the sidebar ask this class, so a section cannot end
 * up hidden from the menu while its URLs stay reachable, or vice versa.
 */
class Prelaunch
{
    public static function enabled(): bool
    {
        return (bool) config('prelaunch.enabled', false);
    }

    /**
     * Is $section closed for $user right now?
     *
     * An unknown section is treated as OPEN rather than closed, so a typo in a
     * Blade template fails visibly instead of silently hiding a menu item
     * nobody can then find.
     */
    public static function closed(string $section, ?User $user = null): bool
    {
        if (! self::enabled()) {
            return false;
        }

        if (! array_key_exists($section, config('prelaunch.closed', []))) {
            return false;
        }

        return ! ($user?->canPreviewPrelaunch() ?? false);
    }

    /** Inverse of closed(), for readability in Blade. */
    public static function open(string $section, ?User $user = null): bool
    {
        return ! self::closed($section, $user);
    }

    /**
     * Would the guard close this route name for $user right now?
     *
     * Lets a caller ask about a route it is about to redirect to, so a redirect
     * target that gets closed later degrades into a different destination
     * rather than a coming-soon dead end.
     */
    public static function routeClosed(?string $routeName, ?User $user = null): bool
    {
        $section = self::sectionForRoute($routeName);

        return $section !== null && self::closed($section, $user);
    }

    /**
     * The section owning a route name, or null if the route is unrestricted.
     *
     * config('prelaunch.open') wins over the closed prefixes, so a section can
     * be shut while a named route inside it stays reachable.
     */
    public static function sectionForRoute(?string $routeName): ?string
    {
        if ($routeName === null) {
            return null;
        }

        if (in_array($routeName, config('prelaunch.open', []), true)) {
            return null;
        }

        foreach (config('prelaunch.closed', []) as $section => $prefixes) {
            foreach ($prefixes as $prefix) {
                if (str_starts_with($routeName, $prefix)) {
                    return $section;
                }
            }
        }

        return null;
    }

    /**
     * Should the membership/billing gate be skipped for this user?
     *
     * During pre-launch nothing is billed, so requiring a live subscription
     * would lock every new enrollee out of the tree they were just placed in.
     */
    public static function bypassesMembership(): bool
    {
        return self::enabled() && (bool) config('prelaunch.bypass_membership', false);
    }

    /** Human-readable section name for the coming-soon page. */
    public static function label(string $section): string
    {
        return match ($section) {
            'commissions' => 'Commissions',
            'crm'         => 'CRM',
            default       => 'This section',
        };
    }

    /**
     * When pre-launch ends, or null if no date is published.
     *
     * Returns null for a date that cannot be parsed rather than throwing: a
     * malformed env value should cost a countdown, not the whole page.
     */
    public static function endsAt(): ?Carbon
    {
        $value = config('prelaunch.ends_at');

        if (blank($value)) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
