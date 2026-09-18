<?php

namespace App\Services\ScreenRecording;

use App\Models\ScreenRecording;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Moves a browser capture onto object storage.
 *
 * The browser cannot POST a 400 MB video in one request — PHP's
 * upload_max_filesize and nginx's client_max_body_size both stand in the way,
 * and a single failed request would throw away the whole session. So the studio
 * uploads the capture in chunks *while it is still recording*; each chunk is
 * appended to one part-file on the droplet, and the finished file is streamed
 * to the Space in a single pass at the end.
 *
 * The part-file is the only thing that ever touches local disk, and it is
 * unlinked as soon as the object is placed.
 */
class RecordingStorage
{
    /**
     * How large a chunk the browser may send.
     *
     * Whatever the config asks for, PHP will silently discard a request body
     * over its own limits — so the request the studio is told to make is
     * clamped to what this server will actually accept, with headroom for the
     * multipart envelope.
     */
    public function chunkBytes(): int
    {
        $configured = (int) config('screen-recordings.chunk_bytes');

        $limits = array_filter([
            $this->iniBytes('upload_max_filesize'),
            $this->iniBytes('post_max_size'),
        ]);

        $ceiling = $limits === [] ? $configured : (int) (min($limits) * 0.8);

        // Never below 256 KB: past that the request overhead dominates and a
        // long recording turns into thousands of round trips.
        return max(256 * 1024, min($configured, $ceiling));
    }

    /**
     * Append one chunk to the part-file.
     *
     * `$offset` is where the browser believes this chunk belongs. Comparing it
     * against the bytes we already hold makes a retried chunk a no-op instead
     * of a corrupted video, which matters because the studio retries on any
     * network blip.
     *
     * @return int total bytes now held for this recording
     */
    public function appendChunk(ScreenRecording $recording, UploadedFile $chunk, int $offset): int
    {
        $path     = $this->tempPath($recording);
        $existing = is_file($path) ? (int) filesize($path) : 0;

        if ($offset < $existing) {
            return $existing; // already have it — a retry of an acknowledged chunk
        }

        if ($offset > $existing) {
            throw new RuntimeException("Chunk out of order: expected offset {$existing}, got {$offset}.");
        }

        $max = (int) config('screen-recordings.max_bytes');
        if ($existing + $chunk->getSize() > $max) {
            throw new RuntimeException('Recording exceeds the maximum allowed size.');
        }

        $this->ensureTempDirectory();

        $source = fopen($chunk->getRealPath(), 'rb');
        $target = fopen($path, 'ab');

        if ($source === false || $target === false) {
            throw new RuntimeException('Could not open the upload buffer for writing.');
        }

        try {
            // Exclusive lock: two chunks racing on the same part-file would
            // interleave their writes and produce an unplayable file.
            if (! flock($target, LOCK_EX)) {
                throw new RuntimeException('Could not lock the upload buffer.');
            }

            stream_copy_to_stream($source, $target);
            fflush($target);
            flock($target, LOCK_UN);
        } finally {
            fclose($source);
            fclose($target);
        }

        clearstatcache(true, $path);
        $total = (int) filesize($path);

        $recording->forceFill(['bytes_received' => $total])->save();

        return $total;
    }

    /**
     * Stream the assembled part-file onto the storage disk and mark it ready.
     */
    public function finalize(ScreenRecording $recording, array $meta = []): ScreenRecording
    {
        $part = $this->tempPath($recording);

        if (! is_file($part) || filesize($part) === 0) {
            throw new RuntimeException('No uploaded data was found for this recording.');
        }

        $mime      = $meta['mime'] ?? 'video/webm';
        $key       = $this->objectKey($recording, $mime);
        $stream    = fopen($part, 'rb');
        $disk      = $this->disk($recording);

        if ($stream === false) {
            throw new RuntimeException('Could not read the assembled recording.');
        }

        try {
            $written = $disk->writeStream($key, $stream, ['ContentType' => $mime]);

            if ($written === false) {
                throw new RuntimeException('The storage disk rejected the recording.');
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $size = (int) filesize($part);
        @unlink($part);

        // Optional metadata is filtered so a retry that has none does not wipe
        // what the first attempt recorded. upload_error is set outside that
        // filter precisely because clearing it *is* the point of succeeding.
        $recording->forceFill(array_filter([
            'duration_seconds' => $meta['duration_seconds'] ?? null,
            'width'            => $meta['width'] ?? null,
            'height'           => $meta['height'] ?? null,
        ], fn ($value) => $value !== null) + [
            'path'           => $key,
            'mime'           => $mime,
            'size_bytes'     => $size,
            'status'         => ScreenRecording::STATUS_READY,
            'bytes_received' => $size,
            'upload_error'   => null,
        ])->save();

        return $recording;
    }

    /** Store the poster frame the studio grabbed off the compositing canvas. */
    public function storeThumbnail(ScreenRecording $recording, UploadedFile $image): string
    {
        $key = rtrim(config('screen-recordings.thumbnail_path'), '/')
            .'/'.$recording->uuid.'.jpg';

        $this->disk($recording)->put($key, file_get_contents($image->getRealPath()), [
            'ContentType' => 'image/jpeg',
        ]);

        $recording->forceFill(['thumbnail_path' => $key])->save();

        return $key;
    }

    /**
     * A short-lived URL the <video> element can play from.
     *
     * On S3-compatible disks this is a signed object URL, so bytes come
     * straight from the Space's CDN edge and never through PHP. Local disks
     * have no such thing, so the caller falls back to streaming the file.
     */
    public function temporaryUrl(ScreenRecording $recording): ?string
    {
        return $recording->isReady() ? $this->signedUrl($recording, $recording->path) : null;
    }

    public function temporaryThumbnailUrl(ScreenRecording $recording): ?string
    {
        return filled($recording->thumbnail_path)
            ? $this->signedUrl($recording, $recording->thumbnail_path)
            : null;
    }

    private function signedUrl(ScreenRecording $recording, string $key): ?string
    {
        $name = $this->diskName($recording);
        $disk = $this->disk($recording);

        // Only object storage. A local disk can technically mint a signed
        // "serve" URL, but that route belongs to the built-in disks — pointing
        // a video element at it for an arbitrary disk yields a 404. Local
        // playback is streamed by the controller instead.
        if (config("filesystems.disks.{$name}.driver") !== 's3') {
            return null;
        }

        if (! $disk instanceof FilesystemAdapter || ! $disk->providesTemporaryUrls()) {
            return null;
        }

        try {
            return $disk->temporaryUrl(
                $key,
                now()->addMinutes((int) config('screen-recordings.link_ttl_minutes')),
            );
        } catch (\Throwable $e) {
            Log::warning('Could not mint a temporary recording URL', [
                'recording' => $recording->uuid,
                'key'       => $key,
                'error'     => $e->getMessage(),
            ]);

            return null;
        }
    }

    /** Remove the object, its poster, and any half-uploaded part-file. */
    public function delete(ScreenRecording $recording): void
    {
        $disk = $this->disk($recording);

        foreach (array_filter([$recording->path, $recording->thumbnail_path]) as $key) {
            try {
                $disk->delete($key);
            } catch (\Throwable $e) {
                // A missing object is not a reason to block the row's deletion;
                // it just means storage and the catalogue already agreed.
                Log::warning('Could not delete a recording object', [
                    'recording' => $recording->uuid,
                    'key'       => $key,
                    'error'     => $e->getMessage(),
                ]);
            }
        }

        $this->discardUpload($recording);
    }

    public function discardUpload(ScreenRecording $recording): void
    {
        $part = $this->tempPath($recording);

        if (is_file($part)) {
            @unlink($part);
        }
    }

    public function tempPath(ScreenRecording $recording): string
    {
        return $this->tempDirectory().'/'.$recording->uuid.'.part';
    }

    public function tempDirectory(): string
    {
        return storage_path('app/'.trim(config('screen-recordings.temp_path'), '/'));
    }

    public function disk(ScreenRecording $recording): Filesystem
    {
        return Storage::disk($this->diskName($recording));
    }

    public function diskName(ScreenRecording $recording): string
    {
        return $recording->disk ?: config('screen-recordings.disk');
    }

    // ── Internals ─────────────────────────────────────────────────────────────

    private function objectKey(ScreenRecording $recording, string $mime): string
    {
        $extension = match (true) {
            str_contains($mime, 'mp4')      => 'mp4',
            str_contains($mime, 'matroska') => 'mkv',
            default                         => 'webm',
        };

        return rtrim(config('screen-recordings.path'), '/')
            .'/'.$recording->created_at?->format('Y/m')
            .'/'.$recording->uuid.'.'.$extension;
    }

    private function ensureTempDirectory(): void
    {
        $directory = $this->tempDirectory();

        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }
    }

    /** Turn "8M" / "512K" / "1G" into bytes. Returns 0 for an unlimited setting. */
    private function iniBytes(string $key): int
    {
        $value = trim((string) ini_get($key));

        if ($value === '' || $value === '-1' || $value === '0') {
            return 0;
        }

        $unit   = strtolower(substr($value, -1));
        $number = (int) $value;

        return match ($unit) {
            'g'     => $number * 1024 * 1024 * 1024,
            'm'     => $number * 1024 * 1024,
            'k'     => $number * 1024,
            default => $number,
        };
    }
}
