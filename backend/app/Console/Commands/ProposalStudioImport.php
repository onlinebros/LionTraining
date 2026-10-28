<?php

namespace App\Console\Commands;

use App\Models\Offering;
use App\Models\OfferingDocument;
use App\Services\ProposalStudio\DocumentText;
use App\Services\ProposalStudio\Library;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

/**
 * Loads a folder of documents into an offering's knowledge library: the
 * vendor's PDFs and slide decks, or training transcripts. A file already in
 * the library (same file name) is skipped, so it can be run again as a
 * folder grows.
 *
 * --text-dir holds plain-text versions of files whose own text cannot be read
 * (a scanned PDF): <same name>.txt. It is used only when the file yields too
 * little text itself, because a file's own text layer is the vendor's exact
 * wording and a transcription is not.
 */
class ProposalStudioImport extends Command
{
    protected $signature = 'proposal-studio:import
        {path : A folder (or one file) to import}
        {--offering=plasmaguard : The offering key}
        {--text-dir= : Plain-text versions of files that have no text layer}
        {--kind= : Document kind for every file (default: guessed from the name)}
        {--audience=internal : internal or shareable}
        {--dry-run : Show what would be imported}';

    protected $description = 'Import a folder of documents into an offering\'s knowledge library';

    // Below this, a file's own text is taken to be a scan with no text layer.
    private const MIN_CHARS = 200;

    public function handle(Library $library, DocumentText $extractor): int
    {
        $offering = Offering::where('key', $this->option('offering'))->first();
        if ($offering === null) {
            $this->error("No offering with the key {$this->option('offering')}.");

            return self::FAILURE;
        }
        $kind = $this->option('kind');
        if ($kind !== null && ! isset(OfferingDocument::KINDS[$kind])) {
            $this->error('Kind must be one of: '.implode(', ', array_keys(OfferingDocument::KINDS)));

            return self::FAILURE;
        }
        $audience = (string) $this->option('audience');
        if (! isset(OfferingDocument::AUDIENCES[$audience])) {
            $this->error('Audience must be internal or shareable.');

            return self::FAILURE;
        }

        $path = rtrim((string) $this->argument('path'), '/');
        $files = is_dir($path) ? (glob($path.'/*') ?: []) : [$path];
        $supported = (array) config('proposal-studio.upload_mimes');
        $existing = $offering->documents()->pluck('original_name')->filter()->all();
        $textDir = $this->option('text-dir') ? rtrim((string) $this->option('text-dir'), '/') : null;

        $added = $skipped = 0;
        foreach ($files as $file) {
            $name = basename($file);
            $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (! is_file($file) || ! in_array($extension, $supported, true)) {
                continue;
            }
            if (in_array($name, $existing, true)) {
                $this->line("  already in the library: {$name}");
                $skipped++;

                continue;
            }

            $text = null;
            $note = '';
            try {
                $own = $extractor->extract($file, $extension);
            } catch (Throwable) {
                $own = '';
            }
            if (mb_strlen($own) < self::MIN_CHARS) {
                $override = $textDir ? $textDir.'/'.pathinfo($name, PATHINFO_FILENAME).'.txt' : null;
                if ($override && is_file($override)) {
                    $text = (string) file_get_contents($override);
                    $note = ' (no text layer: using the transcription)';
                } else {
                    $note = ' (no text layer and no transcription: saved without text)';
                }
            }

            $attributes = [
                'title' => self::title($name),
                'kind' => $kind ?? self::guessKind($name, $extension),
                'audience' => $audience,
                'read_on' => now()->toDateString(),
            ];
            $this->line(($this->option('dry-run') ? '  would add ' : '  adding ')."{$attributes['title']} [{$attributes['kind']}]{$note}");
            if (! $this->option('dry-run')) {
                $library->addFile($offering, $file, $name, $attributes, null, $text);
            }
            $added++;
        }

        $this->info(($this->option('dry-run') ? 'Would add' : 'Added')." {$added}, skipped {$skipped}.");

        return self::SUCCESS;
    }

    public static function title(string $name): string
    {
        $title = pathinfo($name, PATHINFO_FILENAME);
        $title = preg_replace(['/\s*\(\d{3,4}p\)/i', '/_v\d+$/i', '/\[\d+\]$/'], ['', '', ' (copy)'], $title) ?? $title;
        $title = trim(str_replace('_', ' ', $title));

        return Str::limit($title, 250, '');
    }

    public static function guessKind(string $name, string $extension): string
    {
        $lower = mb_strtolower($name);

        return match (true) {
            $extension === 'txt' && (str_contains($lower, 'training') || str_contains($lower, 'zoom') || str_contains($lower, 'dean')) => 'training',
            (bool) preg_match('/\b(results?|tests?|tested|certif\w*)\b/', $lower) => 'test_report',
            str_contains($lower, 'price') || str_contains($lower, 'pricing') => 'pricing',
            str_contains($lower, 'faq') || str_contains($lower, 'rfp') || str_contains($lower, 'competitive') => 'faq',
            str_contains($lower, 'case study') || str_contains($lower, 'before and after') => 'case_study',
            in_array($extension, ['pdf', 'pptx', 'docx'], true) => 'brochure',
            default => 'other',
        };
    }
}
