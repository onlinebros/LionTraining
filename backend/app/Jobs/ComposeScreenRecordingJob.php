<?php

namespace App\Jobs;

use App\Models\ScreenRecording;
use App\Services\ScreenRecording\VideoComposer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Builds a combined recording from its clips, off the request.
 *
 * Every clip is re-encoded to a shared shape before they can be joined, so a
 * five-minute combined video is minutes of work on a two-core droplet. Nobody
 * should be holding a browser open for that.
 */
class ComposeScreenRecordingJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 5400;

    /** One attempt: a bad clip fails the same way every time. */
    public int $tries = 1;

    public function __construct(public int $compositionId) {}

    public function handle(VideoComposer $composer): void
    {
        $composition = ScreenRecording::find($this->compositionId);

        if (! $composition) {
            return; // deleted while queued
        }

        try {
            $composer->compose($composition);
        } catch (Throwable $e) {
            $this->markFailed($composition, $e);

            throw $e;
        }
    }

    public function failed(Throwable $e): void
    {
        $composition = ScreenRecording::find($this->compositionId);

        if ($composition && $composition->status !== ScreenRecording::STATUS_FAILED) {
            $this->markFailed($composition, $e);
        }
    }

    private function markFailed(ScreenRecording $composition, Throwable $e): void
    {
        Log::error('Combining recordings failed', [
            'recording' => $composition->uuid,
            'error' => $e->getMessage(),
        ]);

        $composition->forceFill([
            // Only a never-rendered composition drops to failed. A rebuild that
            // fails leaves the previous version playable rather than pulling a
            // working video out from under whoever is watching it.
            'status' => $composition->isReady()
                ? ScreenRecording::STATUS_READY
                : ScreenRecording::STATUS_FAILED,
            'upload_error' => mb_substr($e->getMessage(), 0, 1000),
        ])->save();
    }
}
