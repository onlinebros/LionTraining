<?php

namespace App\Services\Partner;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Folding an imported position, and everything under it, into an account the
 * same person already had.
 *
 * The founder case. A founder holds a Quantum position from before any of this,
 * and an iHub position with an organisation beneath it. Both are theirs. They
 * want one account and one team: the founder position, carrying the iHub
 * downline.
 *
 * ── This does not move a position ────────────────────────────────────────────
 *
 * Worth being precise, because "placements are permanent" is the rule the whole
 * import is built around. The imported spot is not relocated — it is retired,
 * and the people who were beneath it are re-hung beneath the account that
 * absorbed it. Everyone in that subtree moves up exactly one level and keeps
 * their order relative to each other.
 *
 * That is still a change to a live genealogy, and it is the only operation in
 * this system that makes one. It is deliberately narrow: an administrator runs
 * it, on a spot that has already been claimed, into an account that has already
 * been proven to belong to the same person.
 *
 * ── How the paths move ───────────────────────────────────────────────────────
 *
 * ltree makes the whole subtree one statement. If the spot's path is `1.9` and
 * the surviving account's is `1.5`, then a descendant at `1.9.20.31` becomes
 * `1.5.20.31`: replace the spot's path prefix with the survivor's.
 *
 *   new = survivor.path || subpath(descendant.path, nlevel(spot.path))
 *
 * One UPDATE over a GIST-indexed range, whether the subtree is three rows or a
 * million.
 */
class SpotMergeService
{
    public function __construct(private PartnerWebhookDispatcher $webhooks) {}

    /**
     * Merge $spot into $into.
     *
     * $ownershipProven is the difference between the two ways this is reached.
     * Normally the position must already have been claimed — that is how we
     * know it belongs to the person asking, and an administrator merging an
     * unclaimed position would be handing somebody a downline on their own
     * say-so. The claim flow sets it true because ownership was just proven a
     * better way: they typed the activation code.
     *
     * @return int How many positions were re-hung.
     */
    public function merge(User $spot, User $into, bool $ownershipProven = false): int
    {
        $this->guard($spot, $into, $ownershipProven);

        return DB::transaction(function () use ($spot, $into, $ownershipProven) {
            // Re-read both under a lock and re-check. Between the screen that
            // offered this and the click that ran it, either side could have
            // been claimed, merged or moved.
            $spot = User::query()->lockForUpdate()->findOrFail($spot->id);
            $into = User::query()->lockForUpdate()->findOrFail($into->id);

            $this->guard($spot, $into, $ownershipProven);

            // Before anything moves, while the position still has its path and
            // its parent: this is the only moment a correct payload can be
            // built. The dispatcher freezes it and queues delivery for after
            // the commit.
            $this->markClaimed($spot);
            $this->webhooks->spotClaimed($spot, $into);

            $moved = $this->rehangSubtree($spot, $into, 'placement');
            $this->rehangSubtree($spot, $into, 'enrollment');

            $this->reparentDirects($spot, $into);
            $this->retire($spot, $into);

            return $moved;
        });
    }

    /**
     * An absorbed position counts as claimed.
     *
     * Somebody proved it was theirs and it now has an owner — the account that
     * absorbed it. Recording that keeps the claimed/unclaimed numbers honest,
     * and spends the activation code so it cannot be used again.
     */
    private function markClaimed(User $spot): void
    {
        if (! $spot->isHolding()) {
            return;
        }

        $spot->forceFill([
            'account_status'       => User::ACCOUNT_ACTIVE,
            'claimed_at'           => Carbon::now(),
            'activation_code_hash' => null,
            'claim_attempts'       => 0,
            'claim_locked_until'   => null,
        ])->save();
    }

    /**
     * Why this pair cannot be merged, or null.
     *
     * Public so a screen can explain the greyed-out button rather than only
     * refusing the click.
     */
    public function reasonItCannotMerge(User $spot, User $into, bool $ownershipProven = false): ?string
    {
        if ($spot->id === $into->id) {
            return 'A position cannot be merged into itself.';
        }

        if ($spot->isHolding() && ! $ownershipProven) {
            return 'That position has not been claimed yet. Its owner claims it first, which is '
                . 'how we know it is theirs — merging an unclaimed position would hand somebody '
                . 'a downline on an administrator’s say-so.';
        }

        if ($spot->merged_into_user_id !== null) {
            return 'That position has already been merged into another account.';
        }

        if ($into->merged_into_user_id !== null) {
            return 'The account you are merging into has itself been merged into another one.';
        }

        if (! $into->isActivated()) {
            return 'The account you are merging into is not an active member account.';
        }

        if ($spot->placement_path === null || $into->placement_path === null) {
            return 'Both accounts must hold a position in the structure before one can absorb '
                . 'the other.';
        }

        // The survivor sitting inside the subtree being moved would, after the
        // move, be its own ancestor: every path under the spot gets rewritten
        // to start with the survivor's path, including the survivor's own.
        if ($this->isDescendantOf($into, $spot)) {
            return 'The account you are merging into sits beneath the position being merged. '
                . 'Absorbing it would make it its own upline.';
        }

        return null;
    }

    // ── Steps ─────────────────────────────────────────────────────────────────

    private function guard(User $spot, User $into, bool $ownershipProven = false): void
    {
        if (($reason = $this->reasonItCannotMerge($spot, $into, $ownershipProven)) !== null) {
            throw new RuntimeException($reason);
        }
    }

    /**
     * Rewrite every descendant's path so the subtree hangs off $into instead.
     *
     * @param  'placement'|'enrollment'  $tree
     * @return int Rows moved.
     */
    private function rehangSubtree(User $spot, User $into, string $tree): int
    {
        $column = "{$tree}_path";

        $spotPath = $tree === 'placement' ? $spot->placement_path : $spot->enrollment_path;
        $intoPath = $tree === 'placement' ? $into->placement_path : $into->enrollment_path;

        if ($spotPath === null || $intoPath === null) {
            return 0;
        }

        return DB::affectingStatement(
            "UPDATE users
                SET {$column} = ?::ltree || subpath({$column}, nlevel(?::ltree)),
                    updated_at = ?
              WHERE {$column} <@ ?::ltree
                AND id <> ?",
            [$intoPath, $spotPath, Carbon::now(), $spotPath, $spot->id],
        );
    }

    /** The spot's direct children become the survivor's. */
    private function reparentDirects(User $spot, User $into): void
    {
        User::where('placement_parent_id', $spot->id)
            ->update(['placement_parent_id' => $into->id, 'updated_at' => Carbon::now()]);

        User::where('sponsor_id', $spot->id)
            ->update(['sponsor_id' => $into->id, 'updated_at' => Carbon::now()]);

        // The legacy sponsorships table has to agree. updateOrIgnore semantics:
        // a row that would collide with one the survivor already has is dropped
        // rather than duplicated, because the unique index is (sponsor, sponsored).
        DB::statement(
            'UPDATE sponsorships SET sponsor_id = ?, updated_at = ?
              WHERE sponsor_id = ?
                AND NOT EXISTS (
                    SELECT 1 FROM sponsorships existing
                     WHERE existing.sponsor_id = ?
                       AND existing.sponsored_id = sponsorships.sponsored_id
                )',
            [$into->id, Carbon::now(), $spot->id, $into->id],
        );

        DB::table('sponsorships')->where('sponsor_id', $spot->id)->delete();
    }

    /**
     * Take the absorbed position out of the structure, without deleting it.
     *
     * It keeps its external_user_id, because iHub will quote that at us for
     * years and "merged into account 40 on this date" is the only useful answer
     * to the support call that eventually comes. Its email is released, so the
     * person can use that address on the account that survived.
     */
    private function retire(User $spot, User $into): void
    {
        $spot->forceFill([
            'merged_into_user_id' => $into->id,
            'merged_at'           => Carbon::now(),

            // Out of the tree entirely. 'excluded' is the placement status that
            // already means "never enters the structure", and a null path takes
            // it out of every descendant query in one move.
            'placement_status'    => User::PLACEMENT_EXCLUDED,
            'placement_path'      => null,
            'enrollment_path'     => null,
            'placement_parent_id' => null,

            // Not a member any more: it cannot be logged into, it is not
            // counted, and it does not appear in anybody's team.
            'account_status'      => User::ACCOUNT_MERGED,
            'is_active'           => false,
            'email'               => null,
            'password'            => null,
            'remember_token'      => null,

            // Released with the login. A retired position's invitation link
            // must stop working: it is still printed in whatever the person
            // shared before the merge, and following it would try to enroll
            // somebody beneath an account that is no longer in the structure.
            // The account that absorbed this one has its own code, which is the
            // one they should be sharing now.
            'referral_code'       => null,
        ])->save();
    }

    private function isDescendantOf(User $candidate, User $ancestor): bool
    {
        if ($candidate->placement_path === null || $ancestor->placement_path === null) {
            return false;
        }

        return str_starts_with($candidate->placement_path, $ancestor->placement_path . '.');
    }
}
