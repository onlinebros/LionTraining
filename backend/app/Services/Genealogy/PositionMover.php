<?php

namespace App\Services\Genealogy;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Move a position, and everything under it, to sit beneath somebody else.
 *
 * ── Read this before using it ────────────────────────────────────────────────
 *
 * "Placements are permanent" is the rule this module is built around. The whole
 * import pipeline — staging, the leg-connection screen, the refusal to commit an
 * unconnected leg — exists because a position in the wrong place cannot be put
 * right afterwards.
 *
 * This is the deliberate exception, and it is narrow on purpose:
 *
 *   - an administrator runs it, by hand, naming both sides
 *   - it is for the cases the structure genuinely got wrong: a founder whose
 *     imported position landed under the wrong upline, a leg connected to the
 *     wrong account
 *   - it is not a feature anybody else can reach, and it never runs by itself
 *
 * Everything that was beneath the moved position stays beneath it, keeping its
 * shape and its internal order. What changes is where the whole branch hangs.
 *
 * ── What it does not do ──────────────────────────────────────────────────────
 *
 * It does not touch commissions already earned, ledger rows, or anything else
 * that was calculated against the old structure. Moving a position changes who
 * earns from it *in future*. If money has already moved on the old shape, that
 * is a separate conversation and this will not have it for you.
 */
class PositionMover
{
    /**
     * Move $position beneath $newParent.
     *
     * @return int How many rows had a path rewritten, the position included.
     */
    public function move(User $position, User $newParent): int
    {
        $this->guard($position, $newParent);

        return DB::transaction(function () use ($position, $newParent) {
            // Re-read under lock: between the screen and the click, either side
            // could have been merged, claimed or moved by somebody else.
            $position  = User::query()->lockForUpdate()->findOrFail($position->id);
            $newParent = User::query()->lockForUpdate()->findOrFail($newParent->id);

            $this->guard($position, $newParent);

            // Placement first, then enrollment. Both read the position's
            // *current* paths, and the enrollment column has not been touched
            // when the second call reads it, so the in-memory model is still
            // right for what each one needs.
            $moved = $this->rehang($position, $newParent, 'placement');

            $this->rehang($position, $newParent, 'enrollment');

            $position->forceFill([
                'placement_parent_id' => $newParent->id,
                // The sponsor moves with the placement. In a unilevel they are
                // the same relationship, and leaving the old sponsor behind
                // would mean the person credited with recruiting them is no
                // longer anywhere near them in the structure.
                'sponsor_id'          => $newParent->id,
                'updated_at'          => Carbon::now(),
            ])->save();

            $this->updateLegacySponsorship($position, $newParent);

            return $moved;
        });
    }

    /**
     * Why this move is not allowed, or null.
     *
     * Public so a screen or a command can explain itself before acting rather
     * than only refusing afterwards.
     */
    public function reasonItCannotMove(User $position, User $newParent): ?string
    {
        if ($position->id === $newParent->id) {
            return 'A position cannot be moved beneath itself.';
        }

        if ($position->placement_path === null || $newParent->placement_path === null) {
            return 'Both accounts must already hold a position in the structure.';
        }

        if ($position->merged_into_user_id !== null) {
            return 'That position has been merged into another account and is no longer in the '
                . 'structure. Move the account that absorbed it instead.';
        }

        if ($newParent->merged_into_user_id !== null) {
            return 'The account you are moving it under has been merged into another one.';
        }

        if (! $newParent->isActivated()) {
            return 'The account you are moving it under is not an active member account.';
        }

        // Moving a position beneath its own descendant would detach the whole
        // branch from the tree and leave it circling itself.
        if (str_starts_with((string) $newParent->placement_path, $position->placement_path . '.')) {
            return 'That account sits beneath the position you are moving, so the position would '
                . 'end up below itself.';
        }

        if ($position->placement_parent_id === $newParent->id) {
            return 'It already sits directly beneath that account.';
        }

        return null;
    }

    private function guard(User $position, User $newParent): void
    {
        if (($reason = $this->reasonItCannotMove($position, $newParent)) !== null) {
            throw new RuntimeException($reason);
        }
    }

    /**
     * Rewrite one path column for the position and everything under it.
     *
     * `<@` covers the position itself as well as its descendants, so the branch
     * moves in one statement and cannot end up half-relocated.
     *
     * @param  'placement'|'enrollment'  $tree
     * @return int Rows rewritten.
     */
    private function rehang(User $position, User $newParent, string $tree): int
    {
        $column = "{$tree}_path";

        $from = $tree === 'placement' ? $position->placement_path : $position->enrollment_path;
        $to   = $tree === 'placement' ? $newParent->placement_path : $newParent->enrollment_path;

        if ($from === null || $to === null) {
            return 0;
        }

        // Keep the position's own label, and everything after it, then hang
        // that under the new parent.
        //
        // The offset is nlevel(from) - 1, not nlevel(from). The range being
        // rewritten includes the position itself, whose path *is* `from` —
        // asking subpath() to start past its last label is an error, not an
        // empty result. One off by one and Postgres says only "invalid
        // positions".
        //
        //   position   1.2.4     → subpath(…, 2) = '4'     → 1.3 || 4     = 1.3.4
        //   its child  1.2.4.5   → subpath(…, 2) = '4.5'   → 1.3 || 4.5   = 1.3.4.5
        return DB::affectingStatement(
            "UPDATE users
                SET {$column} = ?::ltree || subpath({$column}, nlevel(?::ltree) - 1),
                    updated_at = ?
              WHERE {$column} <@ ?::ltree",
            [$to, $from, Carbon::now(), $from],
        );
    }

    /**
     * Keep the legacy sponsorships table agreeing with the genealogy.
     *
     * Roughly fifteen screens still read it, and EnrollmentService is explicit
     * that the two representations must never disagree.
     */
    private function updateLegacySponsorship(User $position, User $newParent): void
    {
        DB::table('sponsorships')->where('sponsored_id', $position->id)->delete();

        DB::table('sponsorships')->insertOrIgnore([
            'sponsor_id'   => $newParent->id,
            'sponsored_id' => $position->id,
            'status'       => 'active',
            'notes'        => 'Position moved by an administrator.',
            'accepted_at'  => Carbon::now(),
            'created_at'   => Carbon::now(),
            'updated_at'   => Carbon::now(),
        ]);
    }
}
