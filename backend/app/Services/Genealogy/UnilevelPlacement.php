<?php

namespace App\Services\Genealogy;

use App\Models\User;

/**
 * Unilevel placement: everyone sits directly beneath their sponsor.
 *
 * There is no slot-finding here and there is nothing to search for, because a
 * unilevel node has unlimited width — the sponsor can always take another
 * direct. That also means there is no spillover: a partner is never placed
 * under someone who did not personally enroll them.
 *
 * The practical consequence for pre-launch is worth stating plainly, because it
 * shapes what the campaign can honestly promise: joining early confers depth in
 * your own tree, but it confers no position relative to anyone else, and no
 * one's recruiting ever fills a slot beneath you. Structures with spillover
 * (binary, matrix) are the ones where early position is itself an asset.
 */
class UnilevelPlacement implements PlacementStrategy
{
    public function findParentFor(User $user): ?User
    {
        // Identical to the enrollment parent by definition. A user with no
        // sponsor is a tree root — see config('genealogy.default_sponsor_id')
        // to funnel unreferred signups under a house account instead.
        return $user->sponsor;
    }

    public function name(): string
    {
        return 'unilevel';
    }
}
