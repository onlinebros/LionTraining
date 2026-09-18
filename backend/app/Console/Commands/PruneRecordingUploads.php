<?php

namespace App\Console\Commands;

use App\Models\ScreenRecording;
use App\Services\ScreenRecording\RecordingStorage;
use Illuminate\Console\Command;

/**
 * Sweep up captures that never finished.
 *
 * A studio tab closed mid-recording leaves two things behind: an 'uploading'
 * row nobody can act on, and a part-file on the droplet's 30 GB disk. The
 * part-file is the one that matters — a handful of abandoned hour-long
 * recordings would fill the disk and take the site down with it.
 */
class PruneRecordingUploads extends Command
{
    protected $signature = 'recordings:prune {--hours= : Override the configured staleness window}';

    protected $description = 'Delete abandoned screen-recording uploads and their buffered files';

    public function handle(RecordingStorage $storage): int
    {
        $hours = (int) ($this->option('hours') ?: config('screen-recordings.stale_upload_hours'));
        $cutoff = now()->subHours($hours);

        $stale = ScreenRecording::where('status', ScreenRecording::STATUS_UPLOADING)
            ->where('updated_at', '<', $cutoff)
            ->get();

        foreach ($stale as $recording) {
            $storage->discardUpload($recording);
            $recording->forceDelete();

            $this->line("Pruned abandoned upload {$recording->uuid} ({$recording->title}).");
        }

        // Part-files whose row is already gone — a failed abort, or a manual
        // deletion — would otherwise sit on disk forever.
        $orphans = 0;
        $directory = $storage->tempDirectory();

        if (is_dir($directory)) {
            foreach (glob($directory.'/*.part') ?: [] as $file) {
                $uuid = pathinfo($file, PATHINFO_FILENAME);

                if (ScreenRecording::withTrashed()->where('uuid', $uuid)->exists()) {
                    continue;
                }

                if (filemtime($file) < $cutoff->getTimestamp()) {
                    @unlink($file);
                    $orphans++;
                }
            }
        }

        $this->info("Pruned {$stale->count()} abandoned upload(s) and {$orphans} orphaned file(s).");

        return self::SUCCESS;
    }
}
