<?php

namespace App\Services\Partner;

use App\Models\PartnerImport;
use App\Models\PartnerImportRow;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Check a staged batch as a whole before any of it becomes real.
 *
 * Runs on demand and is safe to run repeatedly — an admin connects a leg,
 * re-validates, connects the next. It only writes row status, errors, warnings
 * and the two depth columns, so re-running cannot lose a connection somebody
 * made.
 *
 * The distinction it draws everywhere:
 *
 *   error   — the batch cannot commit. Something would end up in the genealogy
 *             wrong, and the genealogy has no undo.
 *   warning — commit anyway, but an admin should look.
 *
 * There is no duplicate-person check here, and cannot be: the file carries no
 * names and no email addresses, so we have nothing to compare against somebody
 * we already have. If an existing partner also appears in the partner company's
 * list, that surfaces when they claim — they cannot reuse the email address on
 * their existing account. That is the trade for not holding the personal data,
 * and it is the right way round.
 *
 * ── Every check is a query ───────────────────────────────────────────────────
 *
 * Nothing here loads rows into PHP. At 1.3 million staged rows a collection of
 * Eloquent models is several gigabytes and a walk over it is minutes; the same
 * questions asked as aggregates over an indexed column are seconds. The shape
 * that follows: mark everything valid in one statement, then mark the
 * exceptions — because the exceptions are almost always a handful of rows and
 * the valid ones are almost always all of them.
 */
class SpotImportValidator
{
    /** Codes shorter than this are not credentials; they are guessable. */
    private const MIN_CODE_LENGTH = 6;

    /**
     * How deep a tree we will build paths for.
     *
     * The commit walks one level per statement, so depth is a loop bound, not a
     * data limit. 200 is far past anything real — iHub's export is 72 deep —
     * and exists so a malformed file cannot make the loop run forever.
     */
    public const MAX_DEPTH = 200;

    public function validate(PartnerImport $import): PartnerImport
    {
        // A batch the parser rejected cannot be re-checked into health: the
        // rows it complained about are the ones that never made it into
        // staging, so there is nothing here to look at and a pass would
        // silently clear the reason it was rejected. Re-upload instead.
        //
        // A batch this class failed has validated_at set, so connecting a leg
        // and re-running still works — which is the whole point of the screen.
        if ($import->status === PartnerImport::STATUS_FAILED && $import->validated_at === null) {
            return $import;
        }

        // A committed batch is history. Re-running would reset every row from
        // 'committed' back to 'valid' and recompute depths against positions
        // that already exist, leaving the record claiming work is still to do
        // on an import that is finished and permanent.
        if ($import->isCommitted()) {
            return $import;
        }

        if ($import->rows()->doesntExist()) {
            $import->update([
                'status'       => PartnerImport::STATUS_FAILED,
                'errors'       => ['There is nothing staged to validate.'],
                'validated_at' => Carbon::now(),
            ]);

            return $import->refresh();
        }

        DB::transaction(function () use ($import) {
            // Start from a clean slate every run: an error fixed by connecting
            // a leg has to actually disappear.
            //
            // One statement, not four. Every one of these is a full rewrite of
            // the staging table — 1.5GB and ten minutes each on iHub's list —
            // and they were spread across the reset, the sponsor fallback and
            // both depth passes. Postgres rewrites the row whether you change
            // one column or six, so the columns are cleared together and the
            // sponsor fallback starts from the parent in the same pass.
            $import->rows()->update([
                'status'               => PartnerImportRow::STATUS_VALID,
                'errors'               => null,
                'warnings'             => null,
                'placement_depth'      => null,
                'enrollment_depth'     => null,
                'effective_sponsor_id' => DB::raw('external_parent_id'),
            ]);

            $this->flagBlankIds($import);
            $this->flagBadCodes($import);
            $this->flagDuplicateCodes($import);
            $this->flagSelfParents($import);
            $this->flagOrphanParents($import);
            $this->flagAlreadyImported($import);
            $this->flagBrokenLegLinks($import);

            $this->resolveEffectiveSponsors($import);
            $this->computeDepths($import);
            $this->flagUnreachable($import);

            $this->warnOnUnconnectedLegs($import);
            $this->warnOnUnresolvableSponsors($import);
        });

        $invalid = $import->rows()->where('status', PartnerImportRow::STATUS_INVALID)->count();
        $valid   = $import->rows()->where('status', PartnerImportRow::STATUS_VALID)->count();
        $errors  = $this->batchErrors($import);

        $import->update([
            'status'       => $invalid === 0 && $errors === []
                ? PartnerImport::STATUS_VALIDATED
                : PartnerImport::STATUS_FAILED,
            'valid_rows'   => $valid,
            'error_rows'   => $invalid,
            'errors'       => $errors ?: null,
            'validated_at' => Carbon::now(),
        ]);

        return $import->refresh();
    }

    /**
     * Resolve a leg top onto one of our users.
     *
     * Accepts a numeric id or an email. Holding spots are refused as parents on
     * purpose: hanging a whole imported leg beneath a position nobody has
     * claimed means that if it is never claimed, the leg's commissions are
     * compressed past a node that exists only because of this import.
     */
    public function resolveExistingUser(?string $reference): ?User
    {
        $reference = trim((string) $reference);

        if ($reference === '') {
            return null;
        }

        $query = User::query()->activated();

        return ctype_digit($reference)
            ? $query->find((int) $reference)
            : $query->whereRaw('lower(email) = ?', [strtolower($reference)])->first();
    }

    // ── Errors ────────────────────────────────────────────────────────────────

    private function flagBlankIds(PartnerImport $import): void
    {
        // The parser stages these under a placeholder so they are visible at
        // all; they can never be committed.
        $this->fail(
            $import,
            fn ($q) => $q->where('external_user_id', 'like', '(blank line %'),
            'external_user_id is required and this row has none.',
        );
    }

    private function flagBadCodes(PartnerImport $import): void
    {
        $this->fail(
            $import,
            fn ($q) => $q->where(fn ($w) => $w->whereNull('activation_code')->orWhere('activation_code', '')),
            'activation_code is required. The partner company issues these — we do not generate '
            . 'them, because a code we invent is one their people have never seen.',
        );

        $this->fail(
            $import,
            fn ($q) => $q->whereNotNull('activation_code')
                ->whereRaw('char_length(activation_code) < ?', [self::MIN_CODE_LENGTH]),
            'activation_code is too short to be a credential (minimum ' . self::MIN_CODE_LENGTH
            . ' characters). Anyone can read the user ids off the partner’s own material; the '
            . 'code is the only thing protecting the position.',
        );
    }

    private function flagDuplicateCodes(PartnerImport $import): void
    {
        // Compared the way a claim compares them: case and whitespace folded.
        // Two codes differing only in case are one credential.
        $fold = "upper(regexp_replace(activation_code, '\\s', '', 'g'))";

        $this->fail(
            $import,
            fn ($q) => $q->whereNotNull('activation_code')
                ->whereRaw("{$fold} IN (
                    SELECT {$fold} FROM partner_import_rows
                     WHERE partner_import_id = ? AND activation_code IS NOT NULL
                     GROUP BY {$fold} HAVING count(*) > 1
                )", [$import->id]),
            'This activation code appears on more than one row. Codes must be unique — a shared '
            . 'code means one leaked code opens every position that carries it.',
        );
    }

    private function flagSelfParents(PartnerImport $import): void
    {
        $this->fail(
            $import,
            fn ($q) => $q->whereColumn('external_parent_id', 'external_user_id'),
            'This row is its own parent.',
        );
    }

    private function flagOrphanParents(PartnerImport $import): void
    {
        $this->fail(
            $import,
            fn ($q) => $q->whereNotNull('external_parent_id')
                ->whereNotExists(fn ($sub) => $sub
                    ->from('partner_import_rows as parent')
                    ->where('parent.partner_import_id', $import->id)
                    ->whereColumn('parent.external_user_id', 'partner_import_rows.external_parent_id')),
            'This row names a parent that is not in the file. Every external_parent_id has to '
            . 'appear as an external_user_id on some row here — if this leg hangs beneath somebody '
            . 'already in Quantum, leave the parent blank and connect it on the review screen.',
        );
    }

    private function flagAlreadyImported(PartnerImport $import): void
    {
        $this->fail(
            $import,
            fn ($q) => $q->whereExists(fn ($sub) => $sub
                ->from('users')
                ->where('users.partner_company_id', $import->partner_company_id)
                ->whereColumn('users.external_user_id', 'partner_import_rows.external_user_id')),
            'This company already has a position with this id, brought in by an earlier import. '
            . 'Remove the row, or give the position a new id.',
        );
    }

    private function flagBrokenLegLinks(PartnerImport $import): void
    {
        // Somebody connected this leg and the account has since been deleted or
        // deactivated, which nulls parent_user_id but leaves what was typed.
        // Without this the row reads as connected on the review page, while
        // committing it would have nowhere to hang the leg.
        $this->fail(
            $import,
            fn ($q) => $q->whereNull('external_parent_id')
                ->whereNotNull('link_to_existing')
                ->whereNull('parent_user_id'),
            'The Quantum account this leg was connected to no longer exists or is not active. '
            . 'Pick the partner it belongs under again.',
        );
    }

    // ── Depth, and the cycles it exposes ──────────────────────────────────────

    /**
     * Number every row by how far it sits below the top of its leg.
     *
     * Done here rather than at commit because it answers two questions at once:
     * it is the order the committer builds ltree paths in, and any row it
     * cannot reach is a row on a cycle. With orphan parents already ruled out,
     * unreachable and cyclic are the same set.
     *
     * A loop rather than a recursive CTE. Both are O(rows); the loop's
     * advantage is that it writes the answer down as it goes, so the committer
     * does not recompute it, and a pathological file stops at MAX_DEPTH with
     * rows still null rather than running until something gives out.
     */
    /**
     * Write down who each row's sponsor actually resolves to.
     *
     * The sponsor column if the file contains that id, and the position
     * directly above otherwise. A partner tracks recruitment across a system
     * wider than the slice they export, so a sponsor we cannot see is expected
     * — iHub's list has 4,581 of them.
     *
     * Worked out here, once, rather than implied in two places. It was implied
     * in two places, and they disagreed: the depth pass treated an unresolvable
     * sponsor as the top of an enrollment chain while the commit fell back to
     * the position above, so those rows had their enrollment path built before
     * the row they actually hang under had one, and came out as roots.
     */
    private function resolveEffectiveSponsors(PartnerImport $import): void
    {
        // The fallback to the parent was already applied by the reset above.
        // Only the rows whose sponsor the file does contain need touching, and
        // there is no reason to rewrite the other 1.3 million to find out.
        DB::statement(
            'UPDATE partner_import_rows AS child
                SET effective_sponsor_id = child.external_sponsor_id
               FROM partner_import_rows AS sponsor
              WHERE child.partner_import_id = ?
                AND sponsor.partner_import_id = ?
                AND sponsor.external_user_id = child.external_sponsor_id
                AND child.external_sponsor_id IS NOT NULL
                AND child.external_sponsor_id <> child.external_user_id',
            [$import->id, $import->id],
        );
    }

    private function computeDepths(PartnerImport $import): void
    {
        foreach (['placement' => 'external_parent_id', 'enrollment' => 'effective_sponsor_id'] as $tree => $column) {
            $depthColumn = "{$tree}_depth";

            // Both depth columns were cleared by the reset at the top of
            // validate(); clearing them again here is another full rewrite for
            // nothing.

            // Level 1: the tops. A blank column — for placement that is a leg
            // top, for enrollment a row with no resolvable sponsor and no
            // position above it either. The orWhereNotExists is belt and
            // braces: after resolveEffectiveSponsors() nothing should name a
            // row that is not here, and a row that somehow does still has to
            // get a depth or the commit refuses to run.
            $import->rows()
                ->where(fn ($q) => $q
                    ->whereNull($column)
                    ->orWhereNotExists(fn ($sub) => $sub
                        ->from('partner_import_rows as up')
                        ->where('up.partner_import_id', $import->id)
                        ->whereColumn('up.external_user_id', "partner_import_rows.{$column}")))
                ->update([$depthColumn => 1]);

            for ($depth = 2; $depth <= self::MAX_DEPTH; $depth++) {
                $affected = DB::affectingStatement(
                    "UPDATE partner_import_rows AS child
                        SET {$depthColumn} = ?
                       FROM partner_import_rows AS up
                      WHERE child.partner_import_id = ?
                        AND up.partner_import_id = ?
                        AND child.{$column} = up.external_user_id
                        AND child.{$depthColumn} IS NULL
                        AND up.{$depthColumn} = ?",
                    [$depth, $import->id, $import->id, $depth - 1],
                );

                if ($affected === 0) {
                    break;
                }
            }
        }
    }

    private function flagUnreachable(PartnerImport $import): void
    {
        $this->fail(
            $import,
            fn ($q) => $q->whereNull('placement_depth'),
            'Following this row’s parents never reaches the top of a leg — the chain loops back '
            . 'on itself, or runs deeper than we will build. A position cannot sit beneath its '
            . 'own downline.',
        );
    }

    // ── Warnings ──────────────────────────────────────────────────────────────

    private function warnOnUnconnectedLegs(PartnerImport $import): void
    {
        $import->rows()
            ->whereNull('external_parent_id')
            ->whereNull('parent_user_id')
            ->update(['warnings' => json_encode([
                'Top of a leg. Connect it to an existing Quantum partner before this batch can '
                . 'commit — everything below it comes with it, permanently.',
            ])]);
    }

    /**
     * Sponsors the file names but does not contain.
     *
     * Not an error: a partner tracks recruitment in a system bigger than the
     * slice they exported, so a sponsor outside the file is expected and the
     * position is still perfectly placeable. The position above becomes the
     * sponsor instead, and the count is reported so nobody is surprised later
     * that the enrollment tree is not exactly what the column said.
     */
    private function warnOnUnresolvableSponsors(PartnerImport $import): void
    {
        $import->rows()
            ->whereNotNull('external_sponsor_id')
            ->whereNotExists(fn ($sub) => $sub
                ->from('partner_import_rows as s')
                ->where('s.partner_import_id', $import->id)
                ->whereColumn('s.external_user_id', 'partner_import_rows.external_sponsor_id'))
            ->update(['warnings' => json_encode([
                'The sponsor named on this row is not in this file, so we cannot record it. The '
                . 'position directly above will be recorded as the sponsor instead.',
            ])]);
    }

    // ── Batch-level summary ───────────────────────────────────────────────────

    /** @return array<int, string> */
    private function batchErrors(PartnerImport $import): array
    {
        $errors = [];

        $cyclic = $import->rows()->whereNull('placement_depth')->count();

        if ($cyclic > 0) {
            $errors[] = "The parent column describes {$cyclic} row(s) that never reach the top of "
                . 'a leg — a loop, or a chain deeper than ' . self::MAX_DEPTH . ' levels. Fix the '
                . 'parent ids on the rows flagged below.';
        }

        if ($import->rows()->whereNull('external_parent_id')->doesntExist()) {
            $errors[] = 'Every row in this file names a parent inside the file, which means nothing '
                . 'connects it to our system. At least one row must have a blank external_parent_id.';
        }

        return $errors;
    }

    /**
     * Mark the rows a predicate selects as invalid, appending the reason.
     *
     * Appends rather than replaces, so a row that is wrong in two ways reports
     * both — an admin who fixes the first and re-uploads only to be told about
     * the second has been sent round the loop for nothing.
     *
     * @param  \Closure(\Illuminate\Database\Eloquent\Builder<PartnerImportRow>): mixed  $where
     */
    private function fail(PartnerImport $import, \Closure $where, string $message): void
    {
        $query = $import->rows();
        $where($query);

        // json, not jsonb, on the column — so concatenate as jsonb and cast
        // back. Binding the message rather than interpolating it keeps a
        // partner's data out of the SQL text.
        $query->update([
            'status' => PartnerImportRow::STATUS_INVALID,
            'errors' => DB::raw(
                "(coalesce(errors::jsonb, '[]'::jsonb) || to_jsonb(array["
                . DB::connection()->getPdo()->quote($message)
                . '::text]))::json'
            ),
        ]);
    }
}
