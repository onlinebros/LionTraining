<?php

namespace App\Services\ScreenRecording;

use App\Models\ScreenRecording;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Joins several recordings into one file, in order.
 *
 * The hard part is not the joining, it is that the clips never match. A screen
 * capture is 1920x1080 at 30fps with system audio; a webcam announcement is
 * 1280x720 and may be silent. ffmpeg cannot concatenate streams that disagree
 * about resolution, frame rate, codec or channel layout.
 *
 * So this runs in two passes:
 *
 *   1. Normalise every clip to one shared shape — the same resolution (letter-
 *      boxed, never cropped), frame rate, pixel format and audio layout, with
 *      silence synthesised for clips that have no sound. This is the expensive
 *      part and costs one re-encode per clip.
 *   2. Concatenate the normalised clips with a stream copy, which is instant
 *      because by then every input is identical in every respect that matters.
 *
 * Doing it the other way round — one giant filter_complex — works too, but it
 * fails as a single unit: one awkward clip and the whole export dies with an
 * ffmpeg error nobody can read. Per-clip passes fail per clip, and say which.
 */
class VideoComposer
{
    /** Everything is squared up to this shape unless the clips are smaller. */
    private const MAX_WIDTH  = 1920;
    private const MAX_HEIGHT = 1080;
    private const FPS        = 30;

    public function __construct(private readonly RecordingStorage $storage)
    {
    }

    public function available(): bool
    {
        return is_executable($this->binary('ffmpeg')) && is_executable($this->binary('ffprobe'));
    }

    /**
     * Render $composition from its clips and mark it ready.
     */
    public function compose(ScreenRecording $composition): ScreenRecording
    {
        if (! $this->available()) {
            throw new RuntimeException('ffmpeg is not installed on this server.');
        }

        $clips = $composition->clips()->with('source')->get();

        if ($clips->count() < 2) {
            throw new RuntimeException('A combined video needs at least two recordings.');
        }

        foreach ($clips as $clip) {
            if (! $clip->isPlayable()) {
                throw new RuntimeException(
                    "\"{$clip->label()}\" is no longer available, so this video cannot be rebuilt."
                );
            }
        }

        $workDir = $this->workDirectory().'/'.$composition->uuid;
        $this->resetDirectory($workDir);

        try {
            // 1. Pull every clip down and find out what shape it really is.
            $inputs = [];

            foreach ($clips as $index => $clip) {
                $local = $workDir.'/src-'.$index;
                $this->download($clip->source, $local);
                $inputs[] = ['path' => $local, 'probe' => $this->probe($local), 'label' => $clip->label()];
            }

            [$width, $height] = $this->targetSize($inputs);

            // 2. Normalise each to that shape.
            $normalised = [];

            foreach ($inputs as $index => $input) {
                $out = $workDir.'/norm-'.$index.'.mp4';
                $this->normalise($input, $out, $width, $height);
                $normalised[] = $out;
            }

            // 3. Join them. By now this is a stream copy and takes no time.
            $output = $workDir.'/combined.mp4';
            $this->concatenate($normalised, $workDir.'/list.txt', $output);

            $poster = $workDir.'/poster.jpg';
            $this->extractPoster($output, $poster);

            $this->publish($composition, $output, is_file($poster) ? $poster : null, $width, $height);
        } finally {
            $this->resetDirectory($workDir, remove: true);
        }

        return $composition->refresh();
    }

    // ── Steps ─────────────────────────────────────────────────────────────────

    private function download(ScreenRecording $recording, string $to): void
    {
        $disk   = $this->storage->disk($recording);
        $source = $disk->readStream($recording->path);

        if ($source === null) {
            throw new RuntimeException("Could not read \"{$recording->title}\" from storage.");
        }

        $target = fopen($to, 'wb');

        if ($target === false) {
            fclose($source);
            throw new RuntimeException('Could not open a working file.');
        }

        try {
            stream_copy_to_stream($source, $target);
        } finally {
            fclose($source);
            fclose($target);
        }
    }

    /** Resolution and whether there is any audio — both decide the filter graph. */
    private function probe(string $file): array
    {
        $video = $this->runCapture([
            $this->binary('ffprobe'), '-v', 'error',
            '-select_streams', 'v:0',
            '-show_entries', 'stream=width,height',
            '-of', 'csv=p=0:s=x',
            $file,
        ]);

        $audio = $this->runCapture([
            $this->binary('ffprobe'), '-v', 'error',
            '-select_streams', 'a:0',
            '-show_entries', 'stream=codec_type',
            '-of', 'csv=p=0',
            $file,
        ]);

        $parts = explode('x', trim($video));

        return [
            'width'  => (int) $parts[0],
            'height' => (int) ($parts[1] ?? 0),
            'audio'  => str_contains($audio, 'audio'),
        ];
    }

    /**
     * The shared canvas: big enough for the largest clip, capped at 1080p.
     *
     * Everything smaller is letterboxed into it rather than stretched, so a
     * 720p webcam clip sitting between two 1080p screen captures keeps its
     * proportions instead of being smeared sideways.
     */
    private function targetSize(array $inputs): array
    {
        $width  = 0;
        $height = 0;

        foreach ($inputs as $input) {
            $width  = max($width, $input['probe']['width']);
            $height = max($height, $input['probe']['height']);
        }

        if ($width <= 0 || $height <= 0) {
            $width  = 1280;
            $height = 720;
        }

        if ($width > self::MAX_WIDTH) {
            $height = (int) round($height * (self::MAX_WIDTH / $width));
            $width  = self::MAX_WIDTH;
        }

        if ($height > self::MAX_HEIGHT) {
            $width  = (int) round($width * (self::MAX_HEIGHT / $height));
            $height = self::MAX_HEIGHT;
        }

        // H.264 needs even dimensions.
        return [$width - ($width % 2), $height - ($height % 2)];
    }

    private function normalise(array $input, string $output, int $width, int $height): void
    {
        $filter = sprintf(
            'scale=%1$d:%2$d:force_original_aspect_ratio=decrease,'
            .'pad=%1$d:%2$d:(ow-iw)/2:(oh-ih)/2:black,setsar=1,fps=%3$d',
            $width,
            $height,
            self::FPS,
        );

        $command = [$this->binary('ffmpeg'), '-y', '-nostdin'];

        if ($input['probe']['audio']) {
            $command = [...$command, '-i', $input['path'], '-map', '0:v:0', '-map', '0:a:0'];
        } else {
            // A silent clip still has to carry an audio track, or the concat in
            // pass 2 would be joining files with different stream layouts.
            $command = [
                ...$command,
                '-i', $input['path'],
                '-f', 'lavfi', '-i', 'anullsrc=channel_layout=stereo:sample_rate=48000',
                '-map', '0:v:0', '-map', '1:a:0', '-shortest',
            ];
        }

        $command = [
            ...$command,
            '-vf', $filter,
            '-c:v', 'libx264',
            '-preset', config('screen-recordings.trim.preset', 'veryfast'),
            '-crf', (string) config('screen-recordings.trim.crf', 23),
            '-pix_fmt', 'yuv420p',
            '-c:a', 'aac', '-b:a', '128k', '-ar', '48000', '-ac', '2',
            $output,
        ];

        try {
            $this->run($command, (int) config('screen-recordings.compose.clip_timeout', 1800));
        } catch (RuntimeException $e) {
            throw new RuntimeException("Could not prepare \"{$input['label']}\": ".$e->getMessage());
        }
    }

    private function concatenate(array $files, string $listFile, string $output): void
    {
        $lines = array_map(
            // The concat demuxer takes the path literally; single quotes in a
            // filename would end the argument early.
            fn (string $file) => "file '".str_replace("'", "'\\''", $file)."'",
            $files,
        );

        file_put_contents($listFile, implode("\n", $lines)."\n");

        $this->run([
            $this->binary('ffmpeg'), '-y', '-nostdin',
            '-f', 'concat', '-safe', '0', '-i', $listFile,
            '-c', 'copy',
            '-movflags', '+faststart',
            $output,
        ], 900);

        if (! is_file($output) || filesize($output) === 0) {
            throw new RuntimeException('The clips could not be joined.');
        }
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
            Log::info('Could not extract a poster for a combined recording', ['error' => $e->getMessage()]);
        }
    }

    private function publish(
        ScreenRecording $composition,
        string $video,
        ?string $poster,
        int $width,
        int $height,
    ): void {
        $disk = $this->storage->disk($composition);

        $key = rtrim(config('screen-recordings.path'), '/')
            .'/'.now()->format('Y/m')
            .'/'.$composition->uuid.'-combined-'.now()->format('YmdHis').'.mp4';

        $stream = fopen($video, 'rb');

        if ($stream === false) {
            throw new RuntimeException('Could not read the combined file.');
        }

        try {
            $disk->writeStream($key, $stream, ['ContentType' => 'video/mp4']);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $previous  = $composition->path;
        $posterKey = $composition->thumbnail_path;

        if ($poster) {
            $posterKey = rtrim(config('screen-recordings.thumbnail_path'), '/')
                .'/'.$composition->uuid.'-'.now()->format('YmdHis').'.jpg';

            $disk->put($posterKey, file_get_contents($poster), ['ContentType' => 'image/jpeg']);
        }

        $composition->forceFill([
            'path'             => $key,
            'thumbnail_path'   => $posterKey,
            'mime'             => 'video/mp4',
            'size_bytes'       => (int) filesize($video),
            'duration_seconds' => $this->durationOf($video),
            'width'            => $width,
            'height'           => $height,
            'status'           => ScreenRecording::STATUS_READY,
            'upload_error'     => null,
        ])->save();

        // Rebuilding replaces the previous render; there is no "original" to
        // preserve here because the clips themselves are the source of truth.
        if ($previous && $previous !== $key) {
            try {
                $disk->delete($previous);
            } catch (\Throwable $e) {
                Log::warning('Could not delete a superseded render', [
                    'recording' => $composition->uuid,
                    'key'       => $previous,
                    'error'     => $e->getMessage(),
                ]);
            }
        }
    }

    private function durationOf(string $file): ?int
    {
        $seconds = trim($this->runCapture([
            $this->binary('ffprobe'), '-v', 'error',
            '-show_entries', 'format=duration',
            '-of', 'csv=p=0',
            $file,
        ]));

        return is_numeric($seconds) ? (int) round((float) $seconds) : null;
    }

    // ── Process helpers ───────────────────────────────────────────────────────

    private function run(array $command, int $timeout): void
    {
        if ($nice = config('screen-recordings.trim.nice')) {
            $command = ['nice', '-n', (string) $nice, ...$command];
        }

        $process = new Process($command, timeout: $timeout);

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            throw new RuntimeException('It took too long and was stopped.');
        }

        if (! $process->isSuccessful()) {
            throw new RuntimeException($this->tail(
                trim($process->getErrorOutput()) ?: trim($process->getOutput())
            ));
        }
    }

    private function runCapture(array $command): string
    {
        $process = new Process($command, timeout: 120);
        $process->run();

        return $process->getOutput();
    }

    private function tail(string $value): string
    {
        $lines = array_slice(array_filter(array_map('trim', explode("\n", $value))), -3);

        return mb_substr(implode(' | ', $lines), -400);
    }

    private function binary(string $name): string
    {
        return (string) config('screen-recordings.trim.'.$name, '/usr/bin/'.$name);
    }

    private function workDirectory(): string
    {
        return storage_path('app/'.trim(config('screen-recordings.compose.work_path', 'recording-composes'), '/'));
    }

    private function resetDirectory(string $directory, bool $remove = false): void
    {
        if (is_dir($directory)) {
            foreach (glob($directory.'/*') ?: [] as $file) {
                @unlink($file);
            }

            if ($remove) {
                @rmdir($directory);

                return;
            }
        }

        if (! $remove && ! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }
    }
}
