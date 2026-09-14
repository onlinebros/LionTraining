<?php

namespace App\Services\Genealogy;

use App\Models\User;

/**
 * Decides where in the placement structure a newly enrolled partner belongs.
 *
 * The seam exists so the structure is a config choice rather than something
 * spread through the codebase. A binary or matrix strategy added later
 * implements this same method; nothing that calls it needs to change.
 */
interface PlacementStrategy
{
    /**
     * The placement parent for $user, or null if they are a tree root.
     *
     * Implementations must be deterministic: called twice for the same user
     * against the same data, they return the same parent. Placement order is
     * something partners will ask about, and "why am I below someone who
     * joined after me" has to be answerable from stored data.
     */
    public function findParentFor(User $user): ?User;

    /** Identifier recorded on the placement audit trail. */
    public function name(): string;
}
