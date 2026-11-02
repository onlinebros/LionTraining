<?php

namespace App\Console\Commands;

use App\Models\KnowledgeClaim;
use App\Models\Offering;
use App\Models\OfferingDocument;
use App\Services\ProposalStudio\Knowledge;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

/**
 * Moves an offering's knowledge library between servers: dev, where the
 * documents were imported and read, and live. `export` writes a zip of the
 * documents (files and extracted text) and their claims; `import` adds what
 * the other server does not have yet.
 *
 * Claims keep their status, so a claim approved on one server is approved on
 * the other, and the verbatim rule still holds: a claim is imported only if
 * its quote is in its document's text. Documents already present (same
 * title and size) are skipped, so it can be run again as the library grows.
 */
class ProposalStudioLibrary extends Command
{
    protected $signature = 'proposal-studio:library
        {action : export or import}
        {path : The zip to write or read}
        {--offering=plasmaguard : The offering key (export)}
        {--dry-run : Show what import would add}';

    protected $description = 'Export an offering\'s knowledge library to a zip, or import one';

    public function handle(Knowledge $knowledge): int
    {
        return match ($this->argument('action')) {
            'export' => $this->export(),
            'import' => $this->import($knowledge),
            default => $this->fail('The action is export or import.'),
        };
    }

    private function export(): int
    {
        $offering = Offering::where('key', $this->option('offering'))->firstOrFail();
        $zip = new ZipArchive;
        if ($zip->open($this->argument('path'), ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not write '.$this->argument('path'));
        }

        $documents = [];
        foreach ($offering->documents()->orderBy('id')->get() as $doc) {
            $file = null;
            if ($doc->file_path && Storage::disk('local')->exists($doc->file_path)) {
                $file = 'files/'.$doc->id.'.'.pathinfo($doc->file_path, PATHINFO_EXTENSION);
                $zip->addFile(Storage::disk('local')->path($doc->file_path), $file);
            }
            $documents[] = ['ref' => $doc->id, 'file' => $file] + $doc->only([
                'title', 'kind', 'audience', 'use_for_ai', 'source_url', 'original_name', 'mime', 'size_bytes',
                'extracted_text', 'text_chars', 'extraction_error',
            ]) + ['read_on' => $doc->read_on?->toDateString()];
        }
        $claims = $offering->claims()->orderBy('id')->get()
            ->map(fn (KnowledgeClaim $c) => ['document_ref' => $c->offering_document_id] + $c->only(['topic', 'quote', 'footnote', 'status']))
            ->all();

        $zip->addFromString('library.json', json_encode([
            'format' => 1,
            'exported_at' => now()->toIso8601String(),
            'offering' => $offering->only(['key', 'name', 'vendor_name', 'vendor_key', 'vendor_url', 'summary', 'ai_guidance', 'prohibited_claims', 'discovery_checklist']),
            'documents' => $documents,
            'claims' => $claims,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $zip->close();

        $this->info(count($documents).' documents and '.count($claims).' claims written to '.$this->argument('path'));

        return self::SUCCESS;
    }

    private function import(Knowledge $knowledge): int
    {
        $zip = new ZipArchive;
        if ($zip->open($this->argument('path')) !== true) {
            throw new RuntimeException('Could not open '.$this->argument('path'));
        }
        $data = json_decode((string) $zip->getFromName('library.json'), true);
        if (($data['format'] ?? null) !== 1) {
            throw new RuntimeException('Not a library export.');
        }
        $dry = (bool) $this->option('dry-run');

        $offering = Offering::firstOrNew(['key' => $data['offering']['key']]);
        // Fill in what this server's offering leaves blank; never overwrite an edit.
        foreach ($data['offering'] as $field => $value) {
            if (blank($offering->{$field}) && filled($value)) {
                $offering->{$field} = $value;
            }
        }
        $offering->is_active ??= true;

        $added = $skipped = $claimsAdded = 0;
        $refs = [];
        DB::transaction(function () use ($data, $zip, $dry, $offering, $knowledge, &$added, &$skipped, &$claimsAdded, &$refs) {
            if (! $dry) {
                $offering->save();
            }
            foreach ($data['documents'] as $d) {
                $existing = $offering->exists
                    ? $offering->documents()->where('title', $d['title'])->where('size_bytes', $d['size_bytes'])->first()
                    : null;
                if ($existing) {
                    $refs[$d['ref']] = $existing;
                    $skipped++;

                    continue;
                }
                $added++;
                $this->line("  + {$d['title']}");
                if ($dry) {
                    continue;
                }
                $stored = null;
                if ($d['file'] && ($bytes = $zip->getFromName($d['file'])) !== false) {
                    $stored = "offerings/{$offering->id}/".Str::uuid().'.'.pathinfo($d['file'], PATHINFO_EXTENSION);
                    Storage::disk('local')->put($stored, $bytes);
                }
                $doc = OfferingDocument::create(['offering_id' => $offering->id, 'file_path' => $stored] + collect($d)->except(['ref', 'file'])->all());
                $knowledge->index($doc);
                $refs[$d['ref']] = $doc;
            }

            foreach ($data['claims'] as $c) {
                $doc = $refs[$c['document_ref']] ?? null;
                if ($doc === null || $dry) {
                    continue;
                }
                if (! KnowledgeClaim::isQuotedFrom($c['quote'], (string) $doc->extracted_text)) {
                    $this->warn('  Skipped a claim not found word for word in "'.$doc->title.'".');

                    continue;
                }
                $claim = KnowledgeClaim::firstOrNew(['offering_document_id' => $doc->id, 'quote' => $c['quote']]);
                if (! $claim->exists) {
                    $claim->fill(['offering_id' => $offering->id, 'topic' => $c['topic'], 'footnote' => $c['footnote'], 'status' => $c['status']] + ($c['status'] !== 'draft' ? ['reviewed_at' => now()] : []))->save();
                    $claimsAdded++;
                }
            }
        });

        $this->info(($dry ? 'Would add ' : 'Added ')."{$added} documents ({$skipped} already here) and {$claimsAdded} claims to {$offering->name}.");

        return self::SUCCESS;
    }
}
