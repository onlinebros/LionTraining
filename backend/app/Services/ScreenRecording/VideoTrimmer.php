<?php

namespace App\Services\ScreenRecording;

use App\Models\ScreenRecording;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Cuts a stored recording down to a start/end range.
 *
 * The cut is a re-encode, not a stream copy. Stream copy is instant but can
 * only cut on a keyframe, so "remove the first ten seconds" lands wherever the
 * nearest keyframe happens to be — often mid-word. Re-encoding costs CPU but
 * puts the cut exactly where the admin put it, and it is a background job, so
 * the cost is paid where nobody is waiting on a request.
 *
 * The output is always H.264/AAC in MP4. That is a deliberate second win: the
 * browser's WebM recordings carry no duration in their header and will not
 * scrub in some players, and this pass fixes that on the way through.
 */
class VideoTrimmer
{
    public function __construct(private readonly RecordingStorage $storage)
    {
    }

    public function available(): bool
    {
        return is_executable($this->binary('ffmpeg'));
    }

    /**
     * Cut $recording down to [$start, $end] and swap the result in.
     *
     * The original object is never touched. On the first trim its key is
     * recorded in original_path; on every later trim it is the source again, so
     * a narrower cut can always be widened back out.
     */
    public function trim(ScreenRecording $recording, float $start, float $end): ScreenRecording
    {
        if (! $this->available()) {
            throw new RuntimeException('ffmpeg is not installed on this server.');
        }

        $sourceKey = $recording->trimSourcePath();

        if (blank($sourceKey)) {
            throw new RuntimeException('This recording has no stored file to trim.');
        }

        $duration = round($end - $start, 2);

        if ($duration < 0.5) {
            throw new RuntimeException('A trimmed recording must be at least half a second long.');
        }

        $workDir = $this->workDirectory();
        $input   = $workDir.'/'.$recording->uuid.'-source';
        $output  = $workDir.'/'.$recording->uuid.'-trimmed.mp4';
        $poster  = $workDir.'/'.$recording->uuid.'-poster.jpg';

        try {
            $this->download($recording, $sourceKey, $input);
            $this->runFfmpeg($input, $output, $start, $duration);

            if (! is_file($output) || filesize($output) === 0) {
                throw new RuntimeException('ffmpeg produced no output.');
            }

            // A trimmed start makes the old poster frame wrong — it shows a
            // moment that is no longer in the video.
            $this->extractPoster($output, $poster);

            $this->publish($recording, $output, is_file($poster) ? $poster : null, $sourceKey, $start, $end);
        } finally {
            foreach ([$input, $output, $poster] as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
        }

        return $recording->refresh();
    }

    /** Put the original file back and throw away the trimmed one. */
    public function revert(ScreenRecording $recording): ScreenRecording
    {
        if (! $recording->hasOriginal()) {
            throw new RuntimeException('This recording has never been trimmed.');
        }

        $disk    = $this->storage->disk($recording);
        $trimmed = $recording->path;

        $recording->forceFill([
            'path'             => $recording->original_path,
            'original_path'    => null,
            'trim_start'       => null,
            'trim_end'         => null,
            'trim_status'      => null,
            'trim_error'       => null,
            'duration_seconds' => $recording->original_duration_seconds ?: $recording->duration_seconds,
        ])->save();

        // Only after the row points at the original, so a failure here leaves a
        // stray object rather than a recording pointing at a deleted file.
        try {
            $disk->delete($trimmed);
        } catch (\Throwable $e) {
            Log::warning('Could not delete a reverted trim', [
                'recording' => $recording->uuid,
                'key'       => $trimmed,
                'error'     => $e->getMessage(),
            ]);
        }

        return $recording->refresh();
    }

    // ── Steps ─────────────────────────────────────────────────────────────────

    private function download(ScreenRecording $recording, string $key, string $to): void
    {
        $disk   = $this->storage->disk($recording);
        $source = $disk->readStream($key);

        if ($source === null) {
            throw new RuntimeException("Could not read {$key} from storage.");
        }

        $target = fopen($to, 'wb');

        if ($target === false) {
            fclose($source);
            throw new RuntimeException('Could not open a working file for the trim.');
        }

        try {
            stream_copy_to_stream($source, $target);
        } finally {
            fclose($source);
            fclose($target);
        }
    }

    private function runFfmpeg(string $input, string $output, float $start, float $duration): void
    {
        // -ss BEFORE -i seeks by keyframe first and then decodes forward to the
        // exact point, so this is both fast and frame-accurate. Putting it after
        // -i would decode the whole leading section for no benefit.
        $command = [
            $this->binary('ffmpeg'), '-y', '-nostdin',
            '-ss', $this->seconds($start),
            '-i', $input,
            '-t', $this->seconds($duration),
            '-c:v', 'libx264',
            '-preset', config('screen-recordings.trim.preset', 'veryfast'),
            '-crf', (string) config('screen-recordings.trim.crf', 23),
            '-pix_fmt', 'yuv420p',
            '-c:a', 'aac', '-b:a', '128k',
            // Without this the moov atom lands at the end of the file and the
            // browser has to fetch the whole video before it can start.
            '-movflags', '+faststart',
            $output,
        ];

        $this->run($command, (int) config('screen-recordings.trim.timeout', 3600));
    }

    private function extractPoster(string $video, string $poster): void
    {
        try {
            $this->run([
                $this->binary('ffmpeg'), '-y', '-nostdin',
                '-ss', '1', '-i', $video,
                '-frames:v', '1', '-vf', 'scale=640:-2',
                $poster,
            ], 120);
        } catch (\Throwable $e) {
            // A missing poster is cosmetic; it must not fail the trim.
            Log::info('Could not extract a poster after trimming', ['error' => $e->getMessage()]);
        }
    }

    private function publish(
        ScreenRecording $recording,
        string $video,
        ?string $poster,
        string $sourceKey,
        float $start,
        float $end,
    ): void {
        $disk = $this->storage->disk($recording);

        // A new key every time. Overwriting in place would leave CDN edges and
        // any signed URL already handed out serving the previous cut.
        $key = preg_replace('/\.[a-z0-9]+$/i', '', $sourceKey).'-trim-'.now()->format('YmdHis').'.mp4';

        $stream = fopen($video, 'rb');

        if ($stream === false) {
            throw new RuntimeException('Could not read the trimmed file.');
        }

        try {
            $disk->writeStream($key, $stream, ['ContentType' => 'video/mp4']);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $previous     = $recording->path;
        $posterKey    = $recording->thumbnail_path;
        $replacedOnly = $recording->hasOriginal();

        if ($poster) {
            $posterKey = rtrim(config('screen-recordings.thumbnail_path'), '/')
                .'/'.$recording->uuid.'-'.now()->format('YmdHis').'.jpg';

            $disk->put($posterKey, file_get_contents($poster), ['ContentType' => 'image/jpeg']);
        }

        $recording->forceFill([
            'path'             => $key,
            'original_path'    => $recording->original_path ?: $previous,
            // Captured once, on the first trim, so Revert can restore the real
            // length rather than the trimmed one.
            'original_duration_seconds' => $recording->original_duration_seconds ?: $recording->duration_seconds,
            'thumbnail_path'   => $posterKey,
            'mime'             => 'video/mp4',
            'size_bytes'       => (int) filesize($video),
            'duration_seconds' => (int) round($end - $start),
            'trim_start'       => $start,
            'trim_end'         => $end,
            'trim_status'      => null,
            'trim_error'       => null,
        ])->save();

        // Clean up the file we just replaced — but never the original, which is
        // the only copy of what was actually recorded.
        if ($replacedOnly && $previous && $previous !== $recording->original_path) {
            try {
                $disk->delete($previous);
            } catch (\Throwable $e) {
                Log::warning('Could not delete a superseded trim', [
                    'recording' => $recording->uuid,
                    'key'       => $previous,
                    'error'     => $e->getMessage(),
                ]);
            }
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function run(array $command, int $timeout): void
    {
        // The droplet has two cores and also serves the site. Running the
        // encode at a low priority keeps a long trim from showing up as
        // latency for everyone browsing.
        if ($nice = config('screen-recordings.trim.nice')) {
            $command = ['nice', '-n', (string) $nice, ...$command];
        }

        $process = new Process($command, timeout: $timeout);

        try {
            $process->run();
        } catch (ProcessTimedOutException $e) {
            throw new RuntimeException('The trim took too long and was stopped.');
        }

        if (! $process->isSuccessful()) {
            $error = trim($process->getErrorOutput()) ?: trim($process->getOutput());

            throw new RuntimeException('ffmpeg failed: '.$this->tail($error));
        }
    }

    /** ffmpeg's stderr runs to hundreds of lines; only the tail says what broke. */
    private function tail(string $value): string
    {
        $lines = array_slice(array_filter(array_map('trim', explode("\n", $value))), -4);

        return mb_substr(implode(' | ', $lines), -500);
    }

    private function seconds(float $value): string
    {
        return number_format($value, 3, '.', '');
    }

    private function binary(string $name): string
    {
        return (string) config('screen-recordings.trim.'.$name, '/usr/bin/'.$name);
    }

    private function workDirectory(): string
    {
        $directory = storage_path('app/'.trim(config('screen-recordings.trim.work_path', 'recording-trims'), '/'));

        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        return $directory;
    }
}
