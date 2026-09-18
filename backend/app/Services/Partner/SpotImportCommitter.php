<?php

namespace App\Services\Partner;

use App\Models\PartnerImport;
use App\Models\PartnerImportRow;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Staging → live genealogy. The irreversible step.
 *
 * ── Why this is set-based and not a loop ─────────────────────────────────────
 *
 * The first real import is iHub Global's: 1,304,352 positions, 72 levels deep.
 * Written the obvious way — create a User, enroll it, place it, repeat — that
 * is about six statements per row, so roughly eight million round trips, plus a
 * bcrypt hash each. It does not finish.
 *
 * So the whole commit is a dozen statements that each touch every row:
 *
 *   1. INSERT ... SELECT   every position becomes a users row, ids from the
 *                          sequence, activation codes hashed by pgcrypto
 *   2. UPDATE ... FROM     resolve placement_parent_id and sponsor_id by
 *                          joining staging back to the rows just created
 *   3. one UPDATE per      build the ltree paths a level at a time, parents
 *      level               before children, which is what the depth columns
 *                          computed during validation are for
 *   4. INSERT ... SELECT   the legacy sponsorships rows
 *
 * Minutes instead of days, and — because none of it is in PHP — flat memory.
 *
 * ── What that costs ──────────────────────────────────────────────────────────
 *
 * Eloquent events do not fire. Two things User::booted() would have done have
 * to be handled deliberately:
 *
 *   role_id        set in the INSERT.
 *   referral_code  deliberately left null. Generating a million unique random
 *                  codes in SQL invites birthday collisions against a unique
 *                  index — at 36^8 and 1.3M rows you would expect a few hundred
 *                  — and an unclaimed position has nobody to share a referral
 *                  link anyway. SpotClaimService assigns one at claim.
 *
 * ── Still one transaction ────────────────────────────────────────────────────
 *
 * A half-committed genealogy is worse than a failed import: the failure is
 * visible and fixable, the half is real positions with real paths that the next
 * attempt would duplicate. On a file this size that transaction is long and
 * holds locks, which is a scheduling problem for the runbook, not a reason to
 * split it.
 */
class SpotImportCommitter
{
    /**
     * Turn a validated batch into holding spots.
     *
     * @return int How many positions were created.
     */
    public function commit(PartnerImport $import): int
    {
        if ($import->isCommitted()) {
            throw new RuntimeException('This import has already been committed.');
        }

        if ($import->status !== PartnerImport::STATUS_VALIDATED) {
            throw new RuntimeException('Only a batch that has passed validation can be committed.');
        }

        if (($unlinked = $import->unlinkedTopRows()) > 0) {
            throw new RuntimeException(
                "{$unlinked} leg(s) are not connected to anyone in our system yet. Connect every "
                .'leg top to an existing partner first — a leg committed without one becomes the '
                .'root of its own tree, and positions cannot be moved afterwards.'
            );
        }

        // Both depths, not just placement. A row with no enrollment depth never
        // gets an enrollment path — buildPaths() walks by level and a null
        // level is never reached — so it would commit silently as a position
        // outside the enrollment tree entirely. Validation flags a placement
        // chain that loops; this catches the same thing in the sponsor chain,
        // which nothing else looks at.
        foreach (['placement_depth', 'enrollment_depth'] as $column) {
            $missing = $import->rows()->whereNull($column)->count();

            if ($missing > 0) {
                throw new RuntimeException(
                    "{$missing} row(s) have no {$column}, so their paths cannot be built in order. "
                    .'Either validation did not finish, or the file changed underneath it. '
                    .'Re-check the batch before committing.'
                );
            }
        }

        return DB::transaction(function () use ($import) {
            $roleId = Role::where('name', Role::FREE_MEMBER)->value('id');
            $now    = Carbon::now();

            $created = $this->createSpots($import, $roleId, $now);

            $this->resolvePlacementParents($import);
            $this->resolveSponsors($import);

            $this->buildPaths($import, 'placement');
            $this->buildPaths($import, 'enrollment');

            $this->writeSponsorships($import, $now);
            $this->closeRows($import);

            $import->update([
                'status'         => PartnerImport::STATUS_COMMITTED,
                'committed_rows' => $created,
                'committed_at'   => $now,
            ]);

            return $created;
        });
    }

    // ── 1. The positions ──────────────────────────────────────────────────────

    /**
     * One users row per staged row.
     *
     * The name is the partner's own identifier, because it is the only thing we
     * know about the position — the import carries no personal data. Everything
     * else is a constant or comes straight from staging.
     */
    private function createSpots(PartnerImport $import, ?int $roleId, Carbon $now): int
    {
        return DB::affectingStatement(
            'INSERT INTO users (
                name, is_active, account_status, role_id,
                partner_company_id, partner_import_id, external_user_id,
                activation_code_hash, imported_at,
                placement_status, placement_queued_at,
                created_at, updated_at
             )
             SELECT
                \'Spot \' || r.external_user_id,
                false,
                ?,
                ?,
                ?,
                r.partner_import_id,
                r.external_user_id,
                ' . ActivationCode::sqlExpression('r.activation_code') . ',
                ?, ?, ?, ?, ?
               FROM partner_import_rows AS r
              WHERE r.partner_import_id = ?',
            [
                User::ACCOUNT_HOLDING,
                $roleId,
                $import->partner_company_id,
                ActivationCode::sqlKey(),
                $now,
                User::PLACEMENT_PLACED,
                $now,
                $now, $now,
                $import->id,
            ],
        );
    }

    // ── 2. The two parent links ───────────────────────────────────────────────

    /**
     * Point each new position at the one above it.
     *
     * Two cases in one statement's worth of work: a row with a parent inside
     * the file resolves against the positions just created, and a leg top
     * resolves to the Quantum partner an admin connected it to.
     */
    private function resolvePlacementParents(PartnerImport $import): void
    {
        // Inside the file.
        DB::statement(
            'UPDATE users AS child
                SET placement_parent_id = parent.id
               FROM partner_import_rows AS r
               JOIN users AS parent
                 ON parent.partner_company_id = ?
                AND parent.external_user_id = r.external_parent_id
              WHERE r.partner_import_id = ?
                AND child.partner_import_id = ?
                AND child.external_user_id = r.external_user_id
                AND r.external_parent_id IS NOT NULL',
            [$import->partner_company_id, $import->id, $import->id],
        );

        // The legs, onto partners who were already here.
        DB::statement(
            'UPDATE users AS child
                SET placement_parent_id = r.parent_user_id
               FROM partner_import_rows AS r
              WHERE r.partner_import_id = ?
                AND child.partner_import_id = ?
                AND child.external_user_id = r.external_user_id
                AND r.external_parent_id IS NULL
                AND r.parent_user_id IS NOT NULL',
            [$import->id, $import->id],
        );
    }

    /**
     * Who recruited each position.
     *
     * The sponsor column is honoured when it names somebody inside the file.
     * When it names somebody the partner has but did not export — 4,581 rows of
     * iHub's list do — there is nothing to point at, and the position directly
     * above becomes the sponsor. That is what a unilevel means anyway, and
     * validation warns on every such row so the difference is not a surprise.
     */
    private function resolveSponsors(PartnerImport $import): void
    {
        // effective_sponsor_id, not external_sponsor_id: validation already
        // worked out that a sponsor the file does not contain falls back to the
        // position above, and wrote it down. Reading the raw column here is
        // what made the commit and the depth pass disagree, which built 4,581
        // of iHub's positions as enrollment roots.
        DB::statement(
            'UPDATE users AS child
                SET sponsor_id = sponsor.id
               FROM partner_import_rows AS r
               JOIN users AS sponsor
                 ON sponsor.partner_company_id = ?
                AND sponsor.external_user_id = r.effective_sponsor_id
              WHERE r.partner_import_id = ?
                AND child.partner_import_id = ?
                AND child.external_user_id = r.external_user_id
                AND r.effective_sponsor_id IS NOT NULL',
            [$import->partner_company_id, $import->id, $import->id],
        );

        // What is left is the leg tops, whose sponsor is the Quantum partner
        // the leg was connected to.
        DB::statement(
            'UPDATE users
                SET sponsor_id = placement_parent_id
              WHERE partner_import_id = ?
                AND sponsor_id IS NULL',
            [$import->id],
        );
    }

    // ── 3. The paths ──────────────────────────────────────────────────────────

    /**
     * Build one ltree column, a level at a time.
     *
     * A path is its parent's path with the row's own id appended, so the rows
     * have to be written parents-first. The depth columns computed during
     * validation give that ordering for free: level 1 hangs off whatever was
     * already in the tree, and every level after it hangs off the level before.
     *
     * The loop runs once per level — 72 statements for iHub — rather than once
     * per row. It stops as soon as a level touches nothing, so a shallow import
     * costs a handful of statements.
     *
     * @param  'placement'|'enrollment'  $tree
     */
    private function buildPaths(PartnerImport $import, string $tree): void
    {
        $pathColumn   = "{$tree}_path";
        $parentColumn = $tree === 'placement' ? 'placement_parent_id' : 'sponsor_id';
        $depthColumn  = "{$tree}_depth";

        // Level 1 sits directly beneath something that already has a path: a
        // Quantum partner for a leg top, or — in the enrollment tree — the
        // position above, for a row whose sponsor was outside the file.
        // A null parent means a root of its own tree, and its path is its id.
        for ($depth = 1; $depth <= SpotImportValidator::MAX_DEPTH; $depth++) {
            // The new paths are computed in a subquery rather than joined onto
            // the target: Postgres will not let UPDATE ... FROM join the table
            // being updated, and the parent of a row being updated is a row in
            // that same table.
            $affected = DB::affectingStatement(
                "UPDATE users AS u
                    SET {$pathColumn} = src.new_path
                   FROM (
                        SELECT c.id,
                               CASE
                                   WHEN p.{$pathColumn} IS NULL THEN c.id::text::ltree
                                   ELSE p.{$pathColumn} || c.id::text::ltree
                               END AS new_path
                          FROM users AS c
                          JOIN partner_import_rows AS r
                            ON r.partner_import_id = c.partner_import_id
                           AND r.external_user_id = c.external_user_id
                          LEFT JOIN users AS p ON p.id = c.{$parentColumn}
                         WHERE c.partner_import_id = ?
                           AND r.{$depthColumn} = ?
                           AND c.{$pathColumn} IS NULL
                   ) AS src
                  WHERE u.id = src.id",
                [$import->id, $depth],
            );

            if ($affected === 0) {
                break;
            }
        }
    }

    // ── 4. The legacy table ───────────────────────────────────────────────────

    /**
     * The sponsorships rows the rest of the application still reads.
     *
     * EnrollmentService calls this table legacy and it is, but roughly fifteen
     * admin and member screens read it, and the two representations are not
     * allowed to disagree. One statement, so the cost of keeping that promise
     * is the same here as anywhere else.
     */
    private function writeSponsorships(PartnerImport $import, Carbon $now): void
    {
        DB::statement(
            'INSERT INTO sponsorships (sponsor_id, sponsored_id, status, notes, accepted_at, created_at, updated_at)
             SELECT u.sponsor_id, u.id, ?, ?, ?, ?, ?
               FROM users u
              WHERE u.partner_import_id = ?
                AND u.sponsor_id IS NOT NULL
             ON CONFLICT (sponsor_id, sponsored_id) DO NOTHING',
            ['active', "Imported from {$import->company?->name}.", $now, $now, $now, $import->id],
        );
    }

    // ── 5. Close the staging rows ─────────────────────────────────────────────

    /**
     * Mark the staged rows committed and drop the plaintext codes.
     *
     * The credential leaves staging here. After this, nobody — including us —
     * can read the list of activation codes back out of this system: what
     * remains is a keyed digest per position.
     */
    private function closeRows(PartnerImport $import): void
    {
        DB::statement(
            'UPDATE partner_import_rows AS r
                SET created_user_id = u.id,
                    status = ?,
                    activation_code = NULL,
                    updated_at = ?
               FROM users AS u
              WHERE r.partner_import_id = ?
                AND u.partner_import_id = ?
                AND u.external_user_id = r.external_user_id',
            [PartnerImportRow::STATUS_COMMITTED, Carbon::now(), $import->id, $import->id],
        );
    }
}
