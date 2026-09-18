<?php

namespace App\Jobs;

use App\Models\ScreenRecording;
use App\Services\ScreenRecording\VideoTrimmer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Re-encodes a recording down to its trimmed range, off the request.
 *
 * A ten-minute video takes minutes to encode on a two-core droplet, which is
 * far too long to hold a browser open for — and the admin does not need to
 * watch it happen. The recording stays playable at its previous length until
 * the moment the new file is swapped in.
 */
class TrimScreenRecordingJob implements ShouldQueue
{
    use Queueable;

    /** Long enough for a full-length recording; the trimmer stops itself first. */
    public int $timeout = 3900;

    /**
     * One attempt. A failed encode fails the same way every time — a bad range,
     * a missing binary, a full disk — and retrying just burns the CPU of a box
     * that is also serving the site.
     */
    public int $tries = 1;

    public function __construct(
        public int $recordingId,
        public float $start,
        public float $end,
    ) {}

    public function handle(VideoTrimmer $trimmer): void
    {
        $recording = ScreenRecording::find($this->recordingId);

        if (! $recording) {
            return; // deleted while queued
        }

        $recording->forceFill(['trim_status' => ScreenRecording::TRIM_PROCESSING])->save();

        try {
            $trimmer->trim($recording, $this->start, $this->end);
        } catch (Throwable $e) {
            $this->markFailed($recording, $e);

            throw $e;
        }
    }

    public function failed(Throwable $e): void
    {
        $recording = ScreenRecording::find($this->recordingId);

        if ($recording && ! $recording->trimFailed()) {
            $this->markFailed($recording, $e);
        }
    }

    private function markFailed(ScreenRecording $recording, Throwable $e): void
    {
        Log::error('Screen recording trim failed', [
            'recording' => $recording->uuid,
            'range' => [$this->start, $this->end],
            'error' => $e->getMessage(),
        ]);

        $recording->forceFill([
            'trim_status' => ScreenRecording::TRIM_FAILED,
            'trim_error' => mb_substr($e->getMessage(), 0, 1000),
        ])->save();
    }
}
