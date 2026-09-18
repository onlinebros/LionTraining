<?php

namespace App\Console\Commands;

use App\Models\PartnerImport;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Check that a committed import built the tree it described.
 *
 * The commit reports how many rows it created and nothing else, which is the
 * one number least likely to be wrong. What matters is the shape: every
 * position placed, every path rooted where it should be, and one enrollment
 * tree rather than thousands.
 *
 * That last check is here because it is the one that caught a real bug. The
 * dev rehearsal committed 1,304,352 positions, reported success, and had 4,565
 * enrollment roots where there should have been one — a count nobody would have
 * looked at without being told to. Every assertion below is something that was
 * either wrong once or would be silent if it went wrong.
 */
class VerifyPartnerImport extends Command
{
    protected $signature = 'partners:verify {import : The committed import id}';

    protected $description = 'Check the shape of a committed partner import';

    public function handle(): int
    {
        $import = PartnerImport::with('company')->find($this->argument('import'));

        if ($import === null) {
            $this->error("No import with id {$this->argument('import')}.");

            return self::FAILURE;
        }

        if (! $import->isCommitted()) {
            $this->error("Import {$import->id} has not been committed — nothing to verify.");

            return self::FAILURE;
        }

        $this->line("Verifying import {$import->id} ({$import->company?->name})…");
        $this->newLine();

        $checks = array_merge(
            $this->countChecks($import),
            $this->shapeChecks($import),
            $this->rootChecks($import),
        );

        $rows = [];
        $failed = 0;

        foreach ($checks as [$label, $ok, $detail]) {
            $rows[] = [$ok ? 'OK' : 'FAIL', $label, $detail];
            $failed += $ok ? 0 : 1;
        }

        $this->table(['', 'Check', 'Value'], $rows);

        if ($failed > 0) {
            $this->error("{$failed} check(s) failed. Do not open the claim page until this is understood.");

            return self::FAILURE;
        }

        $this->info('Everything checks out.');

        return self::SUCCESS;
    }

    /** @return list<array{string, bool, string}> */
    private function countChecks(PartnerImport $import): array
    {
        $expected = $import->rows()->count();
        $created  = User::where('partner_import_id', $import->id)->count();
        $holding  = User::where('partner_import_id', $import->id)->holding()->count();

        return [
            [
                'One position per staged row',
                $created === $expected,
                number_format($created) . ' of ' . number_format($expected),
            ],
            [
                'All unclaimed to begin with',
                $holding === $created,
                number_format($holding) . ' holding',
            ],
            [
                'No staged row left uncommitted',
                $import->rows()->whereNull('created_user_id')->doesntExist(),
                number_format($import->rows()->whereNull('created_user_id')->count()) . ' without a position',
            ],
            [
                'Activation codes wiped from staging',
                $import->rows()->whereNotNull('activation_code')->doesntExist(),
                number_format($import->rows()->whereNotNull('activation_code')->count()) . ' plaintext left',
            ],
        ];
    }

    /** @return list<array{string, bool, string}> */
    private function shapeChecks(PartnerImport $import): array
    {
        $unplaced = User::where('partner_import_id', $import->id)
            ->whereNull('placement_path')->count();

        $noSponsorPath = User::where('partner_import_id', $import->id)
            ->whereNull('enrollment_path')->count();

        $depth = (int) DB::scalar(
            'select max(nlevel(placement_path)) from users where partner_import_id = ?',
            [$import->id],
        );

        $parentless = User::where('partner_import_id', $import->id)
            ->whereNull('placement_parent_id')->count();

        return [
            ['Every position has a placement path', $unplaced === 0, number_format($unplaced) . ' without one'],
            ['Every position has an enrollment path', $noSponsorPath === 0, number_format($noSponsorPath) . ' without one'],
            ['Every position has a parent', $parentless === 0, number_format($parentless) . ' without one'],
            ['Deepest level', $depth > 0, (string) $depth],
        ];
    }

    /**
     * The checks that catch a tree built in the wrong order.
     *
     * An import hangs off the accounts its legs were connected to, so every
     * position should sit inside one of their subtrees and the enrollment tree
     * should have no roots of its own. Roots mean paths were written before the
     * rows above them had one.
     *
     * @return list<array{string, bool, string}>
     */
    private function rootChecks(PartnerImport $import): array
    {
        $legParents = $import->topRows()->whereNotNull('parent_user_id')
            ->pluck('parent_user_id')->unique()->values();

        $paths = User::whereIn('id', $legParents)->pluck('placement_path')->filter()->values();

        $outside = $paths->isEmpty() ? -1 : (int) DB::scalar(
            'select count(*) from users
              where partner_import_id = ?
                and not (' . $paths->map(fn () => 'placement_path <@ ?::ltree')->implode(' or ') . ')',
            array_merge([$import->id], $paths->all()),
        );

        $enrollmentRoots = (int) DB::scalar(
            'select count(*) from users where partner_import_id = ? and nlevel(enrollment_path) = 1',
            [$import->id],
        );

        return [
            [
                'Every position sits under a connected leg',
                $outside === 0,
                $outside < 0 ? 'no legs resolved' : number_format($outside) . ' outside',
            ],
            [
                'One enrollment tree, not thousands',
                $enrollmentRoots === 0,
                number_format($enrollmentRoots) . ' enrollment root(s)',
            ],
        ];
    }
}
