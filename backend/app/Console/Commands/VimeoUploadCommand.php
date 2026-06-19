<?php

namespace App\Console\Commands;

use App\Models\VideoAsset;
use App\Services\VimeoUploadService;
use Illuminate\Console\Command;

class VimeoUploadCommand extends Command
{
    protected $signature = 'video:upload-vimeo
                            {id? : VideoAsset ID to upload (omit to process all pending)}
                            {--retry-failed : Also retry previously failed uploads}';

    protected $description = 'Upload pending video assets to Vimeo';

    public function handle(VimeoUploadService $vimeo): int
    {
        $id = $this->argument('id');

        if ($id) {
            $asset = VideoAsset::find($id);
            if (!$asset) {
                $this->error("VideoAsset #{$id} not found.");
                return self::FAILURE;
            }
            return $this->uploadOne($vimeo, $asset) ? self::SUCCESS : self::FAILURE;
        }

        $query = VideoAsset::query()->whereNotNull('local_path');

        if ($this->option('retry-failed')) {
            $query->whereIn('vimeo_status', ['pending', 'failed']);
        } else {
            $query->where('vimeo_status', 'pending');
        }

        $assets = $query->get();

        if ($assets->isEmpty()) {
            $this->info('No pending videos to upload.');
            return self::SUCCESS;
        }

        $this->info("Uploading {$assets->count()} video(s) to Vimeo…");

        $success = 0;
        $fail    = 0;

        foreach ($assets as $asset) {
            if ($this->uploadOne($vimeo, $asset)) {
                $success++;
            } else {
                $fail++;
            }
        }

        $this->info("Done. Success: $success | Failed: $fail");

        return $fail === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function uploadOne(VimeoUploadService $vimeo, VideoAsset $asset): bool
    {
        $this->line("Uploading: [{$asset->id}] {$asset->title} ({$asset->formattedSize()})…");

        if (!$asset->isDownloaded()) {
            $this->warn("  Skipped — local file not found: {$asset->local_path}");
            return false;
        }

        $ok = $vimeo->upload($asset);

        if ($ok) {
            $this->info("  Uploaded → {$asset->fresh()->vimeo_url}");
            $vimeo->syncToContentBlock($asset->fresh());
        } else {
            $this->error("  Failed — {$asset->fresh()->vimeo_upload_error}");
        }

        return $ok;
    }
}
