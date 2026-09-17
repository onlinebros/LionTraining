<?php

namespace App\Services\Partner;

use App\Models\PartnerImport;
use App\Models\PartnerImportRow;
use RuntimeException;

/**
 * CSV → staging rows. No judgement, just reading.
 *
 * Everything this class rejects is a file it cannot read at all: no header, no
 * usable id column, a file with no rows. Whether the tree the file describes
 * makes sense is SpotImportValidator's problem, and it is kept separate so an
 * admin can re-validate after connecting a leg without re-uploading 60MB.
 *
 * It is also the boundary that keeps personal data out. A column we do not
 * recognise has its values dropped on the floor here — not stored in a `raw`
 * payload "in case we need it later", because that is precisely how the names
 * and email addresses we asked a partner not to send end up in our database
 * anyway. Only the column's name is kept, on the batch, so an admin can tell
 * the partner what was discarded.
 *
 * ── Scale ────────────────────────────────────────────────────────────────────
 *
 * The first real file is 1.3 million rows. Nothing here may hold the file, or
 * the staged rows, in memory: the CSV is streamed a line at a time and inserted
 * in chunks, and duplicate detection is left to the database's unique index
 * rather than a PHP set of a million strings.
 *
 * That last choice has a consequence worth stating. insertOrIgnore drops a
 * duplicate silently, so the fast path cannot say which line it dropped. The
 * row count tells us that it happened, and a second pass over the file — only
 * when it happened — finds the exact lines. The common case stays fast; the
 * failure case stays precise.
 */
class SpotImportParser
{
    /**
     * Rows per INSERT.
     *
     * Bounded by Postgres's 65,535 bind parameters per statement: at 9 bound
     * columns, 2,000 rows is 18,000 parameters, comfortably inside it and large
     * enough that the round trips stop mattering.
     */
    private const CHUNK = 2000;

    /** Parse an uploaded file into staging rows and return the batch. */
    public function parse(PartnerImport $import, string $absolutePath): PartnerImport
    {
        $handle = fopen($absolutePath, 'r');

        if ($handle === false) {
            throw new RuntimeException("Could not open the uploaded file at {$absolutePath}.");
        }

        try {
            $import->rows()->delete();

            [$map, $ignored] = $this->readHeader($handle, $import);

            if ($map === null) {
                return $import->refresh();
            }

            $lineNumber = 0;
            $buffer     = [];

            // Deliberately not one transaction. A 1.3M-row insert inside a
            // single transaction holds everything it touches until the end and
            // gives nothing back if it fails near the finish. Staging is
            // disposable — a failed parse is recovered by re-uploading, and the
            // rows a previous attempt wrote are cleared by the delete() above.
            while (($fields = fgetcsv($handle, escape: '')) !== false) {
                // fgetcsv returns [null] for a blank line. A trailing newline is
                // in every file a spreadsheet writes, so this is the normal end
                // of input, not an error.
                if ($fields === [null] || $this->isBlank($fields)) {
                    continue;
                }

                $lineNumber++;
                $values = $this->mapFields($fields, $map);

                $buffer[] = [
                    'partner_import_id'   => $import->id,
                    'line_number'         => $lineNumber,
                    // A row with no id still gets staged, under a placeholder
                    // unique to its line, so it is a numbered failure an admin
                    // can find rather than a row that silently vanished.
                    'external_user_id'    => $this->str($values, 'external_user_id', 64)
                                             ?? "(blank line {$lineNumber})",
                    'activation_code'     => $this->str($values, 'activation_code', 128),
                    'external_parent_id'  => $this->str($values, 'external_parent_id', 64),
                    'external_sponsor_id' => $this->str($values, 'external_sponsor_id', 64),
                    'status'              => PartnerImportRow::STATUS_PENDING,
                    'created_at'          => now(),
                    'updated_at'          => now(),
                ];

                if (count($buffer) >= self::CHUNK) {
                    $this->flush($buffer);
                }
            }

            $this->flush($buffer);

            $staged = $import->rows()->count();
            $errors = $this->loadErrors($absolutePath, $map, $lineNumber, $staged);

            $import->update([
                'status'          => $errors === null
                    ? PartnerImport::STATUS_UPLOADED
                    : PartnerImport::STATUS_FAILED,
                'total_rows'      => $lineNumber,
                'error_rows'      => 0,
                'ignored_columns' => $ignored ?: null,
                'errors'          => $errors,
            ]);

            return $import->refresh();
        } finally {
            fclose($handle);
        }
    }

    /**
     * Insert a chunk and empty the buffer.
     *
     * insertOrIgnore, so a duplicate id does not abort the run. On Postgres it
     * compiles to ON CONFLICT DO NOTHING; a raised unique violation would take
     * the whole statement with it and tell us nothing useful about which of the
     * two thousand rows caused it.
     *
     * @param  list<array<string, mixed>>  $buffer
     */
    private function flush(array &$buffer): void
    {
        if ($buffer !== []) {
            PartnerImportRow::insertOrIgnore($buffer);
            $buffer = [];
        }
    }

    /**
     * Whether the file is usable, and why not.
     *
     * The expensive branch — re-reading the file to name the duplicated ids —
     * runs only when the counts say there were some.
     *
     * @param  array<int, string>  $map
     * @return array<int, string>|null
     */
    private function loadErrors(string $path, array $map, int $read, int $staged): ?array
    {
        if ($read === 0) {
            return ['The file has a header but no rows.'];
        }

        if ($staged === $read) {
            return null;
        }

        $dropped = $read - $staged;
        $lines   = $this->findDuplicateLines($path, $map);

        return [
            "{$dropped} row(s) could not be staged because their external_user_id is not unique "
            . 'in this file. Each position needs its own id.',
            $lines === []
                ? 'The duplicates could not be located on a second read — re-export and try again.'
                : 'Duplicated id(s): ' . implode('; ', $lines),
        ];
    }

    /**
     * Second pass: which ids repeat, and where.
     *
     * Holds the ids in memory, which is the thing the main path is careful not
     * to do. Acceptable because this runs only on a file that is already going
     * to be rejected, and an admin cannot fix a duplicate they cannot find.
     * Stops after twenty so a pathological file cannot exhaust memory
     * describing itself.
     *
     * @param  array<int, string>  $map
     * @return list<string>
     */
    private function findDuplicateLines(string $path, array $map): array
    {
        $position = array_search('external_user_id', $map, true);
        $handle   = $position === false ? false : fopen($path, 'r');

        if ($handle === false) {
            return [];
        }

        try {
            fgetcsv($handle, escape: ''); // header

            $seen  = [];
            $dupes = [];
            $line  = 0;

            while (($fields = fgetcsv($handle, escape: '')) !== false) {
                if ($fields === [null] || $this->isBlank($fields)) {
                    continue;
                }

                $line++;
                $id = trim((string) ($fields[$position] ?? ''));

                if ($id === '') {
                    continue;
                }

                if (isset($seen[$id])) {
                    $dupes[$id][] = $line;

                    if (count($dupes) >= 20) {
                        break;
                    }

                    continue;
                }

                $seen[$id] = $line;
            }

            return array_map(
                fn (string $id) => "'{$id}' on lines {$seen[$id]} and " . implode(', ', $dupes[$id]),
                array_keys($dupes),
            );
        } finally {
            fclose($handle);
        }
    }

    /**
     * Read the header row and map column positions onto our column names.
     *
     * @return array{0: array<int, string>|null, 1: list<string>} The position
     *         map — null when the header is unusable, in which case the batch
     *         has been marked failed with the reason — and the names of the
     *         columns whose values will be discarded.
     */
    private function readHeader($handle, PartnerImport $import): array
    {
        $header = fgetcsv($handle, escape: '');

        if ($header === false || $header === [null]) {
            $import->update([
                'status' => PartnerImport::STATUS_FAILED,
                'errors' => ['The file is empty — the first line should be the column headers.'],
            ]);

            return [null, []];
        }

        $map     = [];
        $ignored = [];

        foreach ($header as $position => $name) {
            $resolved = SpotImportTemplate::resolveHeader((string) $name);

            if ($resolved === null) {
                if (trim((string) $name) !== '') {
                    $ignored[] = trim((string) $name);
                }

                continue;
            }

            // First occurrence wins. A file with two columns that both map to
            // `external_parent_id` is a merge artefact; taking the leftmost is
            // arbitrary but stable, and the later one joins the ignored list.
            if (in_array($resolved, $map, true)) {
                $ignored[] = trim((string) $name);

                continue;
            }

            $map[$position] = $resolved;
        }

        $missing = array_diff(SpotImportTemplate::requiredHeaders(), $map);

        if ($missing !== []) {
            $errors = [
                'The file is missing required column(s): ' . implode(', ', $missing) . '.',
            ];

            if ($ignored !== []) {
                $errors[] = 'Columns we did not recognise: ' . implode(', ', $ignored)
                    . '. Rename them to match the template, or download a fresh template.';
            }

            $import->update(['status' => PartnerImport::STATUS_FAILED, 'errors' => $errors]);

            return [null, $ignored];
        }

        return [$map, array_values(array_unique($ignored))];
    }

    /**
     * @param  array<int, string|null>  $fields
     * @param  array<int, string>  $map
     * @return array<string, string>
     */
    private function mapFields(array $fields, array $map): array
    {
        $values = [];

        foreach ($map as $position => $name) {
            $values[$name] = trim((string) ($fields[$position] ?? ''));
        }

        return $values;
    }

    /** @param array<int, string|null> $fields */
    private function isBlank(array $fields): bool
    {
        foreach ($fields as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, string> $values */
    private function str(array $values, string $key, int $max): ?string
    {
        $value = trim((string) ($values[$key] ?? ''));

        // Truncated rather than rejected. An identifier longer than our column
        // is somebody else's schema, not a reason to refuse a position — and a
        // truncated id that no longer matches its parent reference is caught by
        // the validator, with the line number, rather than failing here with
        // nothing an admin can act on.
        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
