<?php

namespace App\Console\Commands;

use App\Models\KartraFile;
use App\Models\TrainingContentBlock;
use App\Models\VideoAsset;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Moves the training library onto its serving storage.
 *
 * 24 GB of video across 124 files, which on a home connection is hours. So the
 * only design requirement that really matters is that it can be stopped and
 * started again: every object is copied, verified by size, and only then
 * recorded on the row. A row that names a disk and a path is one whose bytes
 * are known to have arrived, so a second run skips it and an interrupted run
 * loses at most the file that was in flight.
 *
 * It copies rather than moves. The local originals are the only copy of
 * material we can no longer re-download, so nothing is deleted here — see
 * --prune, which is a separate, deliberate step.
 *
 * Uploads stream through a file handle; a 500 MB video is never held in memory.
 */
class TrainingPublishMediaCommand extends Command
{
    protected $signature = 'training:publish-media
                            {--disk= : Target disk (default: config training.disk)}
                            {--videos : Only publish videos}
                            {--files : Only publish worksheets}
                            {--limit= : Stop after this many objects, for a first trial run}
                            {--verify : Re-check what is already published and fix any mismatch}
                            {--prune : After verifying, delete local originals that are safely published}
                            {--dry-run : Report what would happen and change nothing}';

    protected $description = 'Copy training videos and worksheets to the serving storage disk';

    private int $published = 0;
    private int $skipped   = 0;
    private int $failed    = 0;
    private int $bytes     = 0;

    public function handle(): int
    {
        $target = (string) ($this->option('disk') ?: config('training.disk', 'local'));
        $dry    = (bool) $this->option('dry-run');

        if (! array_key_exists($target, (array) config('filesystems.disks'))) {
            $this->error("Unknown disk: {$target}");

            return self::FAILURE;
        }

        $this->line("Target disk : <info>{$target}</info>");

        if ($target === 'local') {
            $this->warn('Target is the local private disk — the media is already there, so this is a no-op.');
            $this->warn('Set TRAINING_DISK or SPACES_BUCKET/SPACES_KEY/SPACES_SECRET to publish to object storage.');

            if (! $this->option('verify')) {
                return self::SUCCESS;
            }
        }

        if (! $this->checkTargetWritable($target, $dry)) {
            return self::FAILURE;
        }

        if ($dry) {
            $this->warn('Dry run — nothing will be uploaded or written.');
        }

        $doVideos = ! $this->option('files');
        $doFiles  = ! $this->option('videos');

        if ($doVideos) {
            $this->publishVideos($target, $dry);
        }

        if ($doFiles) {
            $this->publishFiles($target, $dry);
        }

        $this->newLine();
        $this->table(
            ['Published', 'Already there', 'Failed', 'Transferred'],
            [[$this->published, $this->skipped, $this->failed, $this->human($this->bytes)]],
        );

        if ($this->failed > 0) {
            $this->warn('Some objects failed. Run the command again — it resumes where it stopped.');

            return self::FAILURE;
        }

        if ($this->option('prune') && ! $dry) {
            $this->prune();
        }

        return self::SUCCESS;
    }

    /**
     * Prove we can write and read back before starting a multi-hour transfer.
     *
     * Object storage credentials fail at the first PUT, not at configuration
     * time, and finding that out after uploading nothing for twenty minutes is
     * a bad way to spend an evening.
     */
    private function checkTargetWritable(string $target, bool $dry): bool
    {
        if ($dry) {
            return true;
        }

        $probe = 'training/.write-check-' . bin2hex(random_bytes(6));

        try {
            $disk = Storage::disk($target);
            $disk->put($probe, 'ok');

            if ($disk->get($probe) !== 'ok') {
                $this->error("Wrote to {$target} but read back something else. Stopping.");

                return false;
            }

            $disk->delete($probe);
        } catch (Throwable $e) {
            $this->error("Cannot write to disk '{$target}': " . $e->getMessage());
            $this->line('Check the credentials in .env before starting a transfer.');

            return false;
        }

        return true;
    }

    private function publishVideos(string $target, bool $dry): void
    {
        $prefix = trim((string) config('training.paths.videos', 'training/videos'), '/');
        $assets = VideoAsset::orderBy('id')->get();

        $this->newLine();
        $this->line("Videos → {$prefix}/");

        $bar = $this->output->createProgressBar($assets->count());
        $bar->start();

        foreach ($assets as $asset) {
            if ($this->limitReached()) {
                break;
            }

            $source = $asset->local_path;

            if (! filled($source) || ! Storage::disk('local')->exists($source)) {
                $this->failed++;
                $this->newLine();
                $this->warn("  missing source for asset #{$asset->id} {$asset->title}");
                $bar->advance();
                continue;
            }

            $key = $prefix . '/' . basename($source);

            if ($this->alreadyThere($asset->disk, $asset->storage_path, $target, $key, $source)) {
                $this->skipped++;
                $bar->advance();
                continue;
            }

            if ($dry) {
                $this->published++;
                $bar->advance();
                continue;
            }

            if ($this->copy($source, $target, $key)) {
                $asset->update([
                    'disk'         => $target,
                    'storage_path' => $key,
                    'published_at' => now(),
                ]);
                $this->published++;
                $this->bytes += (int) Storage::disk('local')->size($source);
            } else {
                $this->failed++;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
    }

    private function publishFiles(string $target, bool $dry): void
    {
        $prefix = trim((string) config('training.paths.files', 'training/files'), '/');
        $files  = KartraFile::whereNotNull('local_path')->orderBy('id')->get();

        $this->newLine();
        $this->line("Worksheets → {$prefix}/");

        $bar = $this->output->createProgressBar($files->count());
        $bar->start();

        foreach ($files as $file) {
            if ($this->limitReached()) {
                break;
            }

            $source = $file->local_path;

            if (! Storage::disk('local')->exists($source)) {
                $this->failed++;
                $bar->advance();
                continue;
            }

            $key = $prefix . '/' . basename($source);

            // The content blocks are what actually get served, so they are what
            // must end up pointing at the published object.
            $blocks = TrainingContentBlock::where('file_path', $source)->get();

            $alreadyOn = $blocks->first(fn ($b) => $b->file_disk === $target && $b->file_path === $key);

            if ($alreadyOn || $this->objectMatches($target, $key, $source)) {
                if (! $dry) {
                    $this->repointBlocks($source, $target, $key);
                }
                $this->skipped++;
                $bar->advance();
                continue;
            }

            if ($dry) {
                $this->published++;
                $bar->advance();
                continue;
            }

            if ($this->copy($source, $target, $key)) {
                $this->bytes += (int) Storage::disk('local')->size($source);
                $this->repointBlocks($source, $target, $key);
                $file->update(['status' => 'downloaded']);
                $this->published++;
            } else {
                $this->failed++;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
    }

    /**
     * Point every content block that served the local file at the published one.
     *
     * Two lessons can share a worksheet — Kartra attached the same PDF under two
     * download ids — so this deliberately updates all matching blocks, not one.
     */
    private function repointBlocks(string $source, string $target, string $key): void
    {
        TrainingContentBlock::where('file_path', $source)->update([
            'file_path' => $key,
            'file_disk' => $target,
        ]);
    }

    /** Is this row already published, with the object still the right size? */
    private function alreadyThere(?string $disk, ?string $path, string $target, string $key, string $source): bool
    {
        if ($disk !== $target || ! filled($path)) {
            return false;
        }

        if (! $this->option('verify')) {
            return true;
        }

        return $this->objectMatches($target, $path, $source);
    }

    /**
     * Size comparison rather than a checksum.
     *
     * A truncated upload — the realistic failure here, and one this project has
     * already been bitten by — always changes the length. Hashing 24 GB on both
     * sides to catch a corruption mode that object storage does not exhibit
     * would add hours to every verify run.
     */
    private function objectMatches(string $target, string $key, string $source): bool
    {
        try {
            $disk = Storage::disk($target);

            if (! $disk->exists($key)) {
                return false;
            }

            return (int) $disk->size($key) === (int) Storage::disk('local')->size($source);
        } catch (Throwable) {
            return false;
        }
    }

    /** Stream one file to the target disk and confirm it landed whole. */
    private function copy(string $source, string $target, string $key): bool
    {
        $local = Storage::disk('local');

        try {
            $handle = $local->readStream($source);

            if ($handle === false || $handle === null) {
                $this->newLine();
                $this->warn("  cannot read {$source}");

                return false;
            }

            try {
                Storage::disk($target)->writeStream($key, $handle);
            } finally {
                if (is_resource($handle)) {
                    fclose($handle);
                }
            }

            if (! $this->objectMatches($target, $key, $source)) {
                $this->newLine();
                $this->warn("  size mismatch after upload: {$key} — removing the partial object");

                try {
                    Storage::disk($target)->delete($key);
                } catch (Throwable) {
                    // Leaving it is worse than failing loudly, but not fatal:
                    // the next run overwrites it.
                }

                return false;
            }

            return true;
        } catch (Throwable $e) {
            $this->newLine();
            $this->warn("  failed {$source}: " . $e->getMessage());

            return false;
        }
    }

    /**
     * Delete local originals whose published copy verifies.
     *
     * Kept behind its own flag and re-verified here rather than trusting the
     * transfer that just ran: this is the step that makes the Kartra extract
     * unrecoverable, and the droplet's disk is not a reason to rush it.
     */
    private function prune(): void
    {
        if (! $this->confirm('Delete local originals that are verified on the target disk?', false)) {
            $this->line('Left in place.');

            return;
        }

        $freed = 0;
        $kept  = 0;

        foreach (VideoAsset::whereNotNull('storage_path')->get() as $asset) {
            if (! filled($asset->local_path) || ! Storage::disk('local')->exists($asset->local_path)) {
                continue;
            }

            if (! $this->objectMatches($asset->disk, $asset->storage_path, $asset->local_path)) {
                $kept++;
                continue;
            }

            $freed += (int) Storage::disk('local')->size($asset->local_path);
            Storage::disk('local')->delete($asset->local_path);
        }

        $this->info('Freed ' . $this->human($freed) . ($kept ? ", kept {$kept} that did not verify." : '.'));
    }

    private function limitReached(): bool
    {
        $limit = $this->option('limit');

        return $limit !== null && ($this->published + $this->failed) >= (int) $limit;
    }

    private function human(int $bytes): string
    {
        if ($bytes < 1_048_576) {
            return round($bytes / 1024, 1) . ' KB';
        }

        if ($bytes < 1_073_741_824) {
            return round($bytes / 1_048_576, 1) . ' MB';
        }

        return round($bytes / 1_073_741_824, 2) . ' GB';
    }
}
