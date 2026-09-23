<?php

namespace App\Support;

use App\Models\ProductPartnerAssignment;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * What a product partner is allowed to look at.
 *
 * Every screen in the portal asks this class rather than reading the
 * assignments table itself, because the answer is not "their rows" — it is
 * their rows, intersected with the vendor registry, widened for an admin
 * looking over their shoulder. Three rules in three places is how one of them
 * ends up out of step.
 *
 * The rules:
 *
 *   1. An assignment naming a vendor that is no longer in config/vendors.php
 *      grants nothing. The registry is config; a key removed from it must
 *      degrade to no access rather than to an error on someone's dashboard.
 *   2. product_key '*' covers every product the vendor has now or adds later.
 *   3. Admins see every vendor. They are not product partners, but they have to
 *      be able to open what a product partner opens to answer a question about
 *      it, and an admin already sees all of this on the admin side.
 *
 * Rule 3 answers "can an admin get in", not "what does PlasmaGuard see" — an
 * admin's own view is wider than any real partner's. For the second question
 * there is viewedBy() below.
 */
class ProductPartner
{
    /**
     * The product partner an admin is currently looking through, if any.
     *
     * Session rather than a query parameter: the portal is four screens with
     * links between them, and a parameter that has to be threaded through every
     * one of them is a parameter that gets dropped on the link somebody forgot.
     */
    public const VIEW_AS = 'product_partner.view_as';

    /**
     * Whose eyes the portal is being read through.
     *
     * Everything in the portal scopes to this rather than to the signed-in
     * account, which is what makes "see what they would see" true rather than
     * approximately true: an admin viewing as a partner gets that partner's
     * grants, and so their vendors, their orders and their totals.
     *
     * Only admins can be looking through anyone. For everybody else this is
     * the identity function, so a product partner can never end up scoped to
     * another partner by putting something in their own session.
     */
    public static function viewedBy(User $actor): User
    {
        if (! $actor->isAdmin()) {
            return $actor;
        }

        $id = session(self::VIEW_AS);

        if ($id === null) {
            return $actor;
        }

        $subject = User::find($id);

        /*
         * Deleted, or taken off the role since the session started. Dropped
         * rather than kept, because a view that corresponds to nobody is worse
         * than no view — it looks like data and is not.
         */
        if ($subject === null || ! $subject->isProductPartner()) {
            session()->forget(self::VIEW_AS);

            return $actor;
        }

        return $subject;
    }

    /** Is this account currently looking through somebody else's? */
    public static function isViewingAsAnother(User $actor): bool
    {
        return self::viewedBy($actor)->isNot($actor);
    }

    /**
     * The grants, as vendor slug => list of product keys, or null for all.
     *
     * @return array<string, array<int, string>|null>
     */
    public static function grants(User $user): array
    {
        if ($user->isAdmin()) {
            // Every registered vendor, every product. Nothing is hidden from an
            // admin that the admin section does not already show them.
            return array_fill_keys(array_keys(Vendors::all()), null);
        }

        if (! $user->isProductPartner()) {
            return [];
        }

        $grants = [];

        foreach ($user->productPartnerAssignments as $assignment) {
            // Rule 1: an unregistered vendor grants nothing.
            if (Vendors::find($assignment->vendor) === null) {
                continue;
            }

            // Rule 2: '*' wins over any narrower grant for the same vendor, and
            // once it is set a later specific row must not narrow it again.
            if ($assignment->coversAllProducts()) {
                $grants[$assignment->vendor] = null;
                continue;
            }

            if (array_key_exists($assignment->vendor, $grants) && $grants[$assignment->vendor] === null) {
                continue;
            }

            $grants[$assignment->vendor][] = $assignment->product_key;
        }

        return $grants;
    }

    /** @return array<int, string> Vendor slugs this account may see, in registry order. */
    public static function vendors(User $user): array
    {
        return array_keys(self::grants($user));
    }

    public static function hasAnyAccess(User $user): bool
    {
        return self::grants($user) !== [];
    }

    /**
     * Which vendor a request is about.
     *
     * A partner with one vendor never chooses; one with several picks from a
     * switcher. An unknown or unpermitted slug falls back to their first rather
     * than aborting — the alternative is a 403 every time somebody keeps a
     * bookmark from a grant that has since been narrowed.
     */
    public static function resolveVendor(User $user, ?string $requested = null): ?string
    {
        $vendors = self::vendors($user);

        if ($vendors === []) {
            return null;
        }

        return ($requested !== null && in_array($requested, $vendors, true))
            ? $requested
            : $vendors[0];
    }

    /** Products of this vendor the account may see, or null for all of them. */
    public static function productKeys(User $user, string $vendor): ?array
    {
        return self::grants($user)[$vendor] ?? null;
    }

    public static function covers(User $user, string $vendor, ?string $productKey = null): bool
    {
        $grants = self::grants($user);

        if (! array_key_exists($vendor, $grants)) {
            return false;
        }

        // All products, or no particular product was asked about.
        if ($grants[$vendor] === null || $productKey === null) {
            return true;
        }

        return in_array($productKey, $grants[$vendor], true);
    }

    /**
     * Narrow a vendor_leads query to what this account may see.
     *
     * Used by every list, count and total in the portal. An account with no
     * grants gets an impossible condition rather than an unfiltered query: the
     * failure mode of a missing scope here is showing one vendor another
     * vendor's customers.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     */
    public static function scopeLeads($query, User $user, ?string $vendor = null)
    {
        $grants = self::grants($user);

        if ($vendor !== null) {
            $grants = array_key_exists($vendor, $grants) ? [$vendor => $grants[$vendor]] : [];
        }

        if ($grants === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function ($outer) use ($grants) {
            foreach ($grants as $slug => $products) {
                $outer->orWhere(function ($inner) use ($slug, $products) {
                    $inner->where('vendor', $slug);

                    if ($products !== null) {
                        $inner->whereIn('product_key', $products);
                    }
                });
            }
        });
    }

    /**
     * The business lines that sell this vendor's products.
     *
     * Read from config/opportunities.php (`vendor` on each line) rather than
     * hard-coded, so a second vendor with its own line needs no change here.
     * This is the link between "can see PlasmaGuard's numbers" and "can sell
     * PlasmaGuard" — the two are separate grants and this is what pairs them.
     *
     * @return array<int, string>
     */
    public static function opportunitiesForVendor(string $vendor): array
    {
        $keys = [];

        foreach (Opportunity::all() as $key => $opportunity) {
            if ($opportunity->vendorKey() === $vendor) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * The business line to put a partner on so they can sell what they can see.
     *
     * The first line matching any vendor they hold. Null when none of their
     * vendors has a line pointed at it, which is a configuration gap rather
     * than a user error — the admin screen says so instead of offering a
     * button that would do nothing.
     */
    public static function sellingOpportunityFor(User $user): ?string
    {
        foreach (self::vendors($user) as $vendor) {
            $keys = self::opportunitiesForVendor($vendor);

            if ($keys !== []) {
                return $keys[0];
            }
        }

        return null;
    }

    /** Every assignment on the system, for the admin screen that manages them. */
    public static function assignmentsFor(User $user): Collection
    {
        return $user->productPartnerAssignments()->orderBy('vendor')->orderBy('product_key')->get();
    }

    /**
     * The products of a vendor, as key => name, for a grant form.
     *
     * @return array<string, string>
     */
    public static function productOptions(string $vendor): array
    {
        $options = [];

        foreach ((array) (Vendors::find($vendor)['products'] ?? []) as $key => $product) {
            $options[$key] = (string) ($product['name'] ?? $key);
        }

        return $options;
    }

    public const ALL_PRODUCTS = ProductPartnerAssignment::ALL_PRODUCTS;
}
