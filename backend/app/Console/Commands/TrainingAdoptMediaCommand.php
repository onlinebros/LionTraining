<?php

namespace App\Console\Commands;

use App\Models\KartraFile;
use App\Models\KartraImport;
use App\Models\VideoAsset;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Attaches media already sitting on disk to the rows that describe it.
 *
 * The Kartra extract was downloaded once, and it cannot be downloaded again:
 * the portal subscription is what we migrated away from, and the CloudFront
 * URLs in the scrape are signed links that expire. The 24 GB on disk is the
 * only copy, so the import has to be able to find it rather than re-fetch it.
 *
 * The difficulty is that the downloader named each file
 * `<slug-of-title>-<row id>.mp4`, and the row id is a database autoincrement.
 * Re-running the import gives every row a new id, so the ids in the filenames
 * are meaningless the moment the table is rebuilt. Matching therefore ignores
 * the trailing id and keys on the slug, which was verified unique across all
 * 124 videos and all 89 worksheets.
 *
 * Safe to run repeatedly: it only fills in rows that have nothing attached.
 */
class TrainingAdoptMediaCommand extends Command
{
    protected $signature = 'training:adopt-media
                            {--disk= : Attach to media already published on this disk instead of the local Kartra download}
                            {--dry-run : Report what would be attached and change nothing}';

    protected $description = 'Attach already-downloaded Kartra videos and files to their imported records';

    /** The disk being adopted from, and whether it is the original download. */
    private string $disk = 'local';
    private bool $remote = false;

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        if ($dry) {
            $this->warn('Dry run — nothing will be written.');
        }

        // Adopting from a remote disk is how a second environment joins a
        // library that has already been published. The 24 GB is uploaded once,
        // from wherever it happens to live, and production attaches its rows to
        // those objects by name — it never needs a copy of its own.
        $this->disk   = (string) ($this->option('disk') ?: 'local');
        $this->remote = $this->disk !== 'local';

        $videoDir = $this->remote
            ? (string) config('training.paths.videos', 'training/videos')
            : (string) config('training.paths.source_videos', 'kartra-videos');

        $fileDir = $this->remote
            ? (string) config('training.paths.files', 'training/files')
            : (string) config('training.paths.source_files', 'kartra-files');

        try {
            $videos = $this->indexBySlug($videoDir);
            $files  = $this->indexBySlug($fileDir);
        } catch (\Throwable $e) {
            $this->error("Cannot read disk '{$this->disk}': " . $e->getMessage());

            return self::FAILURE;
        }

        $this->line(sprintf("On '%s': %d videos in %s, %d files in %s",
            $this->disk, count($videos), $videoDir, count($files), $fileDir));

        if ($videos === [] && $files === []) {
            $this->error('Nothing found to attach. Check the disk and the paths in config/training.php.');

            return self::FAILURE;
        }

        $v = $this->adoptVideos($videos, $videoDir, $dry);
        $f = $this->adoptFiles($files, $fileDir, $dry);

        $this->newLine();
        $this->table(
            ['', 'Attached', 'Already had one', 'No file found'],
            [
                ['Videos', $v['attached'], $v['skipped'], $v['missing']],
                ['Files',  $f['attached'], $f['skipped'], $f['missing']],
            ],
        );

        foreach ([...$v['unmatched'], ...$f['unmatched']] as $line) {
            $this->warn("  no file on disk for: {$line}");
        }

        if (! $dry && ($v['attached'] || $f['attached'])) {
            $this->info('Next: php artisan kartra:seed-training');
        }

        return self::SUCCESS;
    }

    /**
     * Map every file in a directory by its slug, with the trailing `-<id>`
     * removed. `lesson-10-the-genius-code-77.mp4` indexes as
     * `lesson-10-the-genius-code`.
     */
    private function indexBySlug(string $directory): array
    {
        $disk = Storage::disk($this->disk);

        // Object stores have no directories, so exists() on a prefix is false
        // even when objects sit under it. Only the local disk can be checked
        // this way; for a remote one, an empty listing is the real answer.
        if (! $this->remote && ! $disk->exists($directory)) {
            return [];
        }

        $index = [];

        foreach ($disk->files($directory) as $path) {
            $base = pathinfo($path, PATHINFO_FILENAME);
            $key  = preg_replace('/-\d+$/', '', $base);

            // First one wins. Two worksheets do land on the same key — "What
            // is Life all about" and "More Evidence" were each attached to two
            // different lessons in Kartra, under two download ids. They are
            // byte-identical, so pointing both records at one file is the right
            // answer rather than a clash to resolve.
            $index[$key] ??= $path;
        }

        return $index;
    }

    private function adoptVideos(array $index, string $directory, bool $dry): array
    {
        $out = ['attached' => 0, 'skipped' => 0, 'missing' => 0, 'unmatched' => []];

        $imports = KartraImport::whereNotNull('kartra_video_url')->orderBy('id')->get();

        foreach ($imports as $import) {
            if ($import->video_asset_id) {
                $out['skipped']++;
                continue;
            }

            $path = $index[Str::slug((string) $import->kartra_title)] ?? null;

            if ($path === null) {
                $out['missing']++;
                $out['unmatched'][] = "video — {$import->kartra_title}";
                continue;
            }

            $out['attached']++;

            if ($dry) {
                continue;
            }

            $disk = Storage::disk($this->disk);

            $asset = VideoAsset::create([
                'title'          => $import->kartra_title,
                'source'         => 'kartra',
                'source_url'     => $import->kartra_video_url,
                'source_id'      => $import->kartra_id,
                // A remote adoption has no local copy to point at — the row
                // names the published object directly and is already published.
                'local_path'     => $this->remote ? null : $path,
                'local_filename' => basename($path),
                'disk'           => $this->remote ? $this->disk : null,
                'storage_path'   => $this->remote ? $path : null,
                'published_at'   => $this->remote ? now() : null,
                'file_size'      => $disk->size($path),
                'mime_type'      => $this->remote ? 'video/mp4' : ($disk->mimeType($path) ?: 'video/mp4'),
                // Self-hosted from here on. Vimeo was the original plan and is
                // deliberately left unset: the file is served from our storage
                // through the gated stream.
                'vimeo_status'   => 'self_hosted',
                'notes'          => $this->remote
                    ? "Attached to the published object on '{$this->disk}' by training:adopt-media."
                    : 'Adopted from the Kartra extract by training:adopt-media.',
            ]);

            $import->update(['video_asset_id' => $asset->id, 'status' => 'downloaded']);
        }

        return $out;
    }

    /**
     * Worksheets are trickier: the KartraFile rows carry the Kartra download id
     * and a display name, and the file on disk is named from the display name.
     */
    private function adoptFiles(array $index, string $directory, bool $dry): array
    {
        $out = ['attached' => 0, 'skipped' => 0, 'missing' => 0, 'unmatched' => []];

        $disk = Storage::disk($this->disk);

        foreach (KartraFile::orderBy('id')->get() as $file) {
            // Already attached to something on the disk we are adopting from.
            if ($file->local_path && ($file->disk ?: 'local') === $this->disk && $disk->exists($file->local_path)) {
                $out['skipped']++;
                continue;
            }

            $name = $file->display_name ?: $file->original_filename ?: '';
            $path = $index[Str::slug($name)] ?? $index[Str::slug(pathinfo($name, PATHINFO_FILENAME))] ?? null;

            if ($path === null) {
                $out['missing']++;
                $out['unmatched'][] = "file — {$name}";
                continue;
            }

            $out['attached']++;

            if ($dry) {
                continue;
            }

            $file->update([
                'local_path'     => $path,
                'local_filename' => basename($path),
                'disk'           => $this->remote ? $this->disk : null,
                'file_size'      => $disk->size($path),
                'mime_type'      => $this->remote ? 'application/pdf' : ($disk->mimeType($path) ?: 'application/pdf'),
                'status'         => 'downloaded',
                'error_message'  => null,
            ]);
        }

        return $out;
    }
}
