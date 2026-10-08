<?php

namespace App\Support;

use App\Models\User;

/**
 * A super admin signed in as a member, to see and do what they would.
 *
 * Unlike the product partner "view as" (ProductPartner::VIEW_AS), this is a
 * real sign-in: the session belongs to the member for as long as it lasts, and
 * anything done in it is done as them. The admin's id is kept in the session so
 * the banner can say whose session it really is and the way back does not
 * need a password.
 */
class Impersonation
{
    public const SESSION_KEY = 'impersonator_id';

    /** Why this person cannot be signed in as, or null when they can. */
    public static function refusal(User $actor, User $subject): ?string
    {
        return match (true) {
            ! $actor->isSuperAdmin()   => 'Only a super admin can sign in as a member.',
            $actor->is($subject)       => 'That is your own account.',
            // An admin session is the whole back office. Borrowing one would
            // let a super admin act as another member of staff, which is not
            // a support action.
            $subject->isAdmin()        => 'Staff accounts cannot be signed in as.',
            ! $subject->is_active      => $subject->name.' is deactivated. Activate them first.',
            $subject->account_status !== User::ACCOUNT_ACTIVE
                                       => $subject->name.' has not activated their account, so there is nothing to sign in to.',
            default                    => null,
        };
    }

    /** The admin behind the current session, if it is an impersonated one. */
    public static function impersonator(): ?User
    {
        $id = session(self::SESSION_KEY);

        return $id === null ? null : User::find($id);
    }

    public static function active(): bool
    {
        return session()->has(self::SESSION_KEY);
    }
}
