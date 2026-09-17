<?php

namespace App\Console\Commands;

use App\Models\PartnerCompany;
use App\Models\PartnerImport;
use App\Models\PartnerImportRow;
use App\Services\Partner\SpotImportCommitter;
use App\Services\Partner\SpotImportParser;
use App\Services\Partner\SpotImportValidator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Run a partner import from the command line.
 *
 * The admin screen is the right place for a list of a few thousand. It is the
 * wrong place for iHub's 1.3 million: the upload alone is 60MB through PHP's
 * request path, and the commit is a transaction measured in minutes, which no
 * web request should be holding open behind a proxy timeout.
 *
 * So the big ones run here, against a file already on the server, and the admin
 * screen is where somebody reviews and connects the legs in between. The stages
 * are separate commands on purpose — `--stage`, then look, then `--commit` —
 * because the one irreversible step should never be something you reached by
 * pressing return once.
 */
class ImportPartnerSpots extends Command
{
    protected $signature = 'partners:import
                            {company : Partner company slug, e.g. ihub}
                            {--file= : Absolute path to the CSV, for --stage}
                            {--stage : Read the file into staging and validate it}
                            {--import= : Id of a staged import, for the later stages}
                            {--link=* : Connect a leg: --link=EXTERNAL_ID:quantum-email-or-id}
                            {--revalidate : Re-run the checks}
                            {--commit : Create the positions. Irreversible.}
                            {--force : Skip the confirmation on --commit}';

    protected $description = 'Stage, check and commit a partner company spot import';

    public function handle(
        SpotImportParser $parser,
        SpotImportValidator $validator,
        SpotImportCommitter $committer,
    ): int {
        $company = PartnerCompany::where('slug', $this->argument('company'))->first();

        if ($company === null) {
            $this->error("No partner company with slug '{$this->argument('company')}'.");

            return self::FAILURE;
        }

        try {
            if ($this->option('stage')) {
                return $this->stage($company, $parser, $validator);
            }

            $import = $this->resolveImport($company);

            if ($this->option('link') !== []) {
                $this->link($import, $validator);
            }

            if ($this->option('revalidate') || $this->option('link') !== []) {
                $this->line('Re-checking…');
                $validator->validate($import);
                $import->refresh();
            }

            $this->report($import);

            if ($this->option('commit')) {
                return $this->commit($import, $committer);
            }

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->newLine();
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    // ── Stages ────────────────────────────────────────────────────────────────

    private function stage(PartnerCompany $company, SpotImportParser $parser, SpotImportValidator $validator): int
    {
        $path = (string) $this->option('file');

        if (! is_file($path)) {
            $this->error("No file at {$path}.");

            return self::FAILURE;
        }

        $import = PartnerImport::create([
            'partner_company_id' => $company->id,
            'original_filename'  => basename($path),
            // Deliberately not copied onto the private disk: it is already on
            // the server, and a second copy of a file full of live activation
            // codes is a second thing somebody has to remember to delete.
            'stored_path'        => null,
            'uploaded_by'        => null,
            'notes'              => 'Staged from the command line.',
        ]);

        $this->line("Reading {$path} …");
        $started = microtime(true);
        $parser->parse($import, $path);
        $this->line(sprintf('  read in %.1fs', microtime(true) - $started));

        $import->refresh();

        if ($import->status === PartnerImport::STATUS_FAILED) {
            $this->report($import);

            return self::FAILURE;
        }

        $this->line('Checking…');
        $started = microtime(true);
        $validator->validate($import);
        $this->line(sprintf('  checked in %.1fs', microtime(true) - $started));

        $this->report($import->refresh());

        $this->newLine();
        $this->line("Import id {$import->id}. Connect the legs, then commit:");
        $this->line("  php artisan partners:import {$this->argument('company')} --import={$import->id} --link=EXTERNAL_ID:founder@example.com");
        $this->line("  php artisan partners:import {$this->argument('company')} --import={$import->id} --commit");

        return self::SUCCESS;
    }

    private function link(PartnerImport $import, SpotImportValidator $validator): void
    {
        foreach ($this->option('link') as $pair) {
            [$externalId, $reference] = array_pad(explode(':', (string) $pair, 2), 2, null);

            $row = $import->rows()->where('external_user_id', $externalId)->first();

            if ($row === null) {
                throw new RuntimeException("No staged row with id '{$externalId}'.");
            }

            if (! $row->isTopRow()) {
                throw new RuntimeException(
                    "'{$externalId}' is not the top of a leg — it has a parent inside the file, so "
                    .'it cannot be connected to one of our users.'
                );
            }

            $user = $validator->resolveExistingUser($reference);

            if ($user === null) {
                throw new RuntimeException("No active Quantum account matches '{$reference}'.");
            }

            $row->update(['parent_user_id' => $user->id, 'link_to_existing' => $reference]);

            $this->info("  {$externalId} → {$user->name} (#{$user->id})");
        }
    }

    private function commit(PartnerImport $import, SpotImportCommitter $committer): int
    {
        if (! $import->isCommittable()) {
            $this->error('This batch is not ready to commit. See above.');

            return self::FAILURE;
        }

        $count = number_format($import->valid_rows);

        if (! $this->option('force') && ! $this->confirm(
            "Create {$count} permanent positions in the live genealogy? There is no undo.",
            false,
        )) {
            $this->line('Nothing was imported.');

            return self::SUCCESS;
        }

        $this->line('Committing…');
        $started = microtime(true);
        $created = $committer->commit($import);
        $elapsed = microtime(true) - $started;

        $this->info(sprintf('  %s positions created in %.1fs.', number_format($created), $elapsed));
        $this->line('They are in the tree now, and hidden from every team view until claimed.');

        return self::SUCCESS;
    }

    // ── Reporting ─────────────────────────────────────────────────────────────

    private function resolveImport(PartnerCompany $company): PartnerImport
    {
        $id = $this->option('import');

        $import = $id
            ? PartnerImport::where('partner_company_id', $company->id)->find($id)
            : PartnerImport::where('partner_company_id', $company->id)->latest()->first();

        if ($import === null) {
            throw new RuntimeException('No staged import found. Run with --stage first.');
        }

        return $import;
    }

    private function report(PartnerImport $import): void
    {
        $this->newLine();
        $this->table(['', ''], [
            ['Import',     $import->id],
            ['File',       $import->original_filename],
            ['Status',     $import->status],
            ['Rows',       number_format($import->total_rows)],
            ['Ready',      number_format($import->valid_rows)],
            ['With errors', number_format($import->error_rows)],
            ['Legs',       number_format($import->topRows()->count())],
            ['Legs not connected', number_format($import->unlinkedTopRows())],
            ['Deepest level', (int) $import->rows()->max('placement_depth')],
            ['Rows with warnings', number_format($import->rows()->whereNotNull('warnings')->count())],
        ]);

        foreach ($import->errors ?? [] as $error) {
            $this->error("  {$error}");
        }

        if ($import->ignored_columns) {
            $this->warn('  Discarded columns: ' . implode(', ', $import->ignored_columns));
        }

        $this->reportLegs($import);
        $this->reportSampleErrors($import);
    }

    /**
     * Every leg, with how much of the file hangs off it.
     *
     * The number that matters most on a big import: iHub's list is 40 legs, and
     * one of them carries 99.99% of the rows. Connecting that one to the wrong
     * partner is the single most expensive mistake available here.
     */
    private function reportLegs(PartnerImport $import): void
    {
        $legs = $import->topRows()->with('parentUser:id,name,email')->get();

        if ($legs->isEmpty()) {
            return;
        }

        // Subtree size per leg, counted in SQL by walking down from each top.
        $rows = [];

        foreach ($legs as $leg) {
            $rows[] = [
                $leg->external_user_id,
                number_format($this->subtreeSize($import, $leg)),
                $leg->parentUser
                    ? "{$leg->parentUser->name} <{$leg->parentUser->email}>"
                    : 'NOT CONNECTED',
            ];
        }

        usort($rows, fn ($a, $b) => (int) str_replace(',', '', $b[1]) <=> (int) str_replace(',', '', $a[1]));

        $this->newLine();
        $this->line('Legs:');
        $this->table(['Leg', 'Positions below', 'Sits beneath'], array_slice($rows, 0, 50));

        if (count($rows) > 50) {
            $this->line('  … and ' . (count($rows) - 50) . ' more.');
        }
    }

    /** How many staged rows hang off one leg, the leg included. */
    private function subtreeSize(PartnerImport $import, PartnerImportRow $leg): int
    {
        return (int) DB::selectOne(
            'WITH RECURSIVE tree AS (
                SELECT external_user_id FROM partner_import_rows
                 WHERE partner_import_id = ? AND external_user_id = ?
                UNION ALL
                SELECT r.external_user_id
                  FROM partner_import_rows r
                  JOIN tree t ON r.external_parent_id = t.external_user_id
                 WHERE r.partner_import_id = ?
             )
             SELECT count(*) AS n FROM tree',
            [$import->id, $leg->external_user_id, $import->id],
        )->n;
    }

    private function reportSampleErrors(PartnerImport $import): void
    {
        $bad = $import->rows()
            ->where('status', PartnerImportRow::STATUS_INVALID)
            ->limit(10)
            ->get(['line_number', 'external_user_id', 'errors']);

        if ($bad->isEmpty()) {
            return;
        }

        $this->newLine();
        $this->line('First rows with errors:');

        foreach ($bad as $row) {
            $this->line("  line {$row->line_number} ({$row->external_user_id}): "
                . implode(' ', $row->errors ?? []));
        }
    }
}
