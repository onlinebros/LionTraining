<?php

namespace App\Services\ScreenRecording;

use Symfony\Component\Process\Process;

/**
 * Asks ffprobe what a file actually is.
 *
 * Needed because an uploaded file is whatever bytes the browser sent. The
 * client can report a duration and a resolution, but it reports them from its
 * own decoder — which may be wrong, may be absent for the codec, and can be
 * edited by anyone who cares to. Anything that ends up in the library or on a
 * schedule is measured here instead.
 */
class MediaInspector
{
    public function available(): bool
    {
        return is_executable($this->binary());
    }

    /**
     * @return array{ok:bool, has_video:bool, has_audio:bool, duration:?int, width:?int, height:?int, format:?string}
     */
    public function inspect(string $file): array
    {
        $blank = [
            'ok' => false, 'has_video' => false, 'has_audio' => false,
            'duration' => null, 'width' => null, 'height' => null, 'format' => null,
        ];

        if (! is_file($file) || filesize($file) === 0 || ! $this->available()) {
            return $blank;
        }

        $process = new Process([
            $this->binary(), '-v', 'error',
            '-show_entries', 'stream=codec_type,width,height:format=duration,format_name',
            '-of', 'json',
            $file,
        ], timeout: 120);

        $process->run();

        if (! $process->isSuccessful()) {
            return $blank;
        }

        $data = json_decode($process->getOutput(), true);

        if (! is_array($data)) {
            return $blank;
        }

        $result = $blank;
        $result['format'] = $data['format']['format_name'] ?? null;

        $duration = $data['format']['duration'] ?? null;
        if (is_numeric($duration) && $duration > 0) {
            $result['duration'] = (int) round((float) $duration);
        }

        foreach ($data['streams'] ?? [] as $stream) {
            if (($stream['codec_type'] ?? null) === 'video') {
                $result['has_video'] = true;
                // The first video stream wins; a cover-art JPEG can also appear
                // as a video stream, but it carries no duration and the file
                // would fail the duration check anyway.
                $result['width']  ??= (int) ($stream['width'] ?? 0) ?: null;
                $result['height'] ??= (int) ($stream['height'] ?? 0) ?: null;
            }

            if (($stream['codec_type'] ?? null) === 'audio') {
                $result['has_audio'] = true;
            }
        }

        $result['ok'] = $result['has_video'] && $result['duration'] !== null;

        return $result;
    }

    /** Grab a frame to use as the poster, so an upload looks like a recording. */
    public function extractPoster(string $video, string $poster, int $atSecond = 1): bool
    {
        if (! is_executable($this->binary('ffmpeg'))) {
            return false;
        }

        $process = new Process([
            $this->binary('ffmpeg'), '-y', '-nostdin',
            '-ss', (string) $atSecond, '-i', $video,
            '-frames:v', '1', '-vf', 'scale=640:-2',
            $poster,
        ], timeout: 120);

        $process->run();

        return $process->isSuccessful() && is_file($poster) && filesize($poster) > 0;
    }

    private function binary(string $name = 'ffprobe'): string
    {
        return (string) config('screen-recordings.trim.'.$name, '/usr/bin/'.$name);
    }
}
