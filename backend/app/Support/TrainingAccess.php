<?php

namespace App\Support;

use App\Models\SiteSetting;
use App\Models\User;

/**
 * One answer to "may this person see the training library at all?".
 *
 * This is the outermost gate, above subscriptions and above the drip. While it
 * says 'admin', the library does not exist as far as the site is concerned: no
 * nav link, no routes, no video, no worksheet — for everybody except
 * administrators, who see it exactly as it will ship.
 *
 * That is what lets the Kartra material be loaded onto production and checked
 * against the real thing before a single member can reach it. Opening the
 * library is then one switch in the admin, not a deploy.
 *
 * The switch lives in site settings so it survives a release, and falls back to
 * config/training.php on a fresh install, where it ships closed.
 */
class TrainingAccess
{
    public const KEY = 'training_visibility';

    public const ADMIN_ONLY = 'admin';
    public const MEMBERS    = 'members';

    public static function visibility(): string
    {
        $stored = SiteSetting::get(self::KEY, config('training.visibility', self::ADMIN_ONLY));

        return $stored === self::MEMBERS ? self::MEMBERS : self::ADMIN_ONLY;
    }

    public static function isOpenToMembers(): bool
    {
        return self::visibility() === self::MEMBERS;
    }

    /**
     * Administrators are never held out — previewing the library before it
     * opens is the entire purpose of the closed state.
     */
    public static function visibleTo(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        return $user->isAdmin() || self::isOpenToMembers();
    }

    public static function open(): void
    {
        SiteSetting::set(self::KEY, self::MEMBERS);
    }

    public static function close(): void
    {
        SiteSetting::set(self::KEY, self::ADMIN_ONLY);
    }
}
