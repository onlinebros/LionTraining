<?php

namespace App\Services\Training;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves training media without ever handing out a durable public URL.
 *
 * There are two shapes of delivery and the difference matters:
 *
 *  - On an object store the bytes never pass through PHP. The controller has
 *    already decided the viewer may watch, and this mints a signed URL that
 *    expires (config: training.link_ttl_minutes). A copied link works until it
 *    expires and then stops; it is not a permanent key to the product.
 *
 *  - On a local disk the application streams the file itself, which means the
 *    access check runs again on every single request — including every seek.
 *
 * Both paths keep the file outside the web root. Nothing here is ever written
 * to the 'public' disk, because the product is the video.
 */
class TrainingStorage
{
    /**
     * The disk a row lives on.
     *
     * A null stored value means the row predates the transfer and still sits
     * wherever config/training.php points. Once `training:publish-media` moves
     * it, the row names its own disk and this setting stops applying to it.
     */
    public function diskName(?string $stored = null): string
    {
        return $stored ?: (string) config('training.disk', 'local');
    }

    public function disk(?string $stored = null): Filesystem
    {
        return Storage::disk($this->diskName($stored));
    }

    public function exists(?string $diskName, ?string $path): bool
    {
        if (! filled($path)) {
            return false;
        }

        return $this->disk($diskName)->exists($path);
    }

    /**
     * A time-limited URL to the object, or null when the disk cannot mint one.
     *
     * Local disks return null by design: their "temporary URL" would be a
     * /storage path that bypasses the access check entirely.
     */
    public function temporaryUrl(?string $diskName, string $path, ?string $downloadAs = null): ?string
    {
        $disk = $this->disk($diskName);

        if (! $disk instanceof FilesystemAdapter || ! $this->isRemote($this->diskName($diskName))) {
            return null;
        }

        if (! $disk->providesTemporaryUrls()) {
            return null;
        }

        $options = [];

        // Makes the browser save the worksheet under its real name rather than
        // the object key, which is a slug with a Kartra id on the end.
        if ($downloadAs !== null) {
            $options['ResponseContentDisposition'] =
                'attachment; filename="' . str_replace('"', '', $downloadAs) . '"';
        }

        return $disk->temporaryUrl(
            $path,
            now()->addMinutes((int) config('training.link_ttl_minutes', 180)),
            $options,
        );
    }

    /**
     * Play a video, honouring HTTP range requests.
     *
     * Without this a browser cannot seek. It asks for `Range: bytes=...` when
     * the viewer drags the scrub bar, and a 200 response carrying the whole
     * file tells it ranges are unsupported — so the player refuses to jump and
     * can only replay from zero. Worse, every seek would re-download a 400 MB
     * file from the start.
     */
    public function stream(Request $request, ?string $diskName, string $path, ?string $mime = null): Response
    {
        $name = $this->diskName($diskName);
        $disk = $this->disk($name);

        abort_unless($disk->exists($path), 404);

        $mime = $mime ?: ($disk->mimeType($path) ?: 'video/mp4');

        // Object storage: hand the browser a signed URL and let the CDN edge
        // serve the ranges. S3 honours Range itself, so seeking still works.
        if ($url = $this->temporaryUrl($name, $path)) {
            return redirect()->away($url);
        }

        if (! $disk instanceof FilesystemAdapter) {
            return $disk->response($path);
        }

        $absolute = $disk->path($path);
        $size     = (int) $disk->size($path);
        $range    = $this->parseRange($request->header('Range'), $size);

        if ($range === false) {
            return response('', Response::HTTP_REQUESTED_RANGE_NOT_SATISFIABLE, [
                'Content-Range'  => "bytes */{$size}",
                'Accept-Ranges'  => 'bytes',
            ]);
        }

        if ($range === null) {
            // No Range header: the whole file, but still advertise that ranges
            // are available so the player enables its scrub bar.
            $response = new BinaryFileResponse($absolute, Response::HTTP_OK, [
                'Content-Type'        => $mime,
                'Accept-Ranges'       => 'bytes',
                'Content-Disposition' => 'inline',
            ]);
            $this->harden($response);

            return $response;
        }

        [$start, $end] = $range;

        return $this->partial($absolute, $start, $end, $size, $mime);
    }

    /**
     * Send one byte range as a 206.
     *
     * Streamed in chunks rather than read into a string: a 400 MB video read
     * whole would exceed PHP's memory limit, and a range request for the last
     * second of a file should not read the first hour of it.
     */
    private function partial(string $absolute, int $start, int $end, int $size, string $mime): StreamedResponse
    {
        $length = $end - $start + 1;
        $chunk  = max(8192, (int) config('training.stream_chunk_bytes', 524288));

        $response = new StreamedResponse(function () use ($absolute, $start, $length, $chunk) {
            $handle = fopen($absolute, 'rb');

            if ($handle === false) {
                return;
            }

            try {
                fseek($handle, $start);
                $remaining = $length;

                while ($remaining > 0 && ! feof($handle)) {
                    $buffer = fread($handle, (int) min($chunk, $remaining));

                    if ($buffer === false || $buffer === '') {
                        break;
                    }

                    echo $buffer;
                    $remaining -= strlen($buffer);

                    // Without this the whole range is buffered before anything
                    // reaches the browser, which defeats the point of chunking.
                    if (connection_aborted()) {
                        break;
                    }

                    flush();
                }
            } finally {
                fclose($handle);
            }
        }, Response::HTTP_PARTIAL_CONTENT, [
            'Content-Type'        => $mime,
            'Content-Length'      => (string) $length,
            'Content-Range'       => "bytes {$start}-{$end}/{$size}",
            'Accept-Ranges'       => 'bytes',
            'Content-Disposition' => 'inline',
        ]);

        $this->harden($response);

        return $response;
    }

    /**
     * Parse a Range header.
     *
     * @return array{0:int,1:int}|null|false
     *   [start, end] for a satisfiable range, null when the client asked for
     *   the whole file, false when the range cannot be satisfied (416).
     */
    private function parseRange(?string $header, int $size): array|null|false
    {
        if (! filled($header) || $size <= 0) {
            return null;
        }

        // Only `bytes` ranges exist in practice, and a multi-range request is
        // answered with the whole file rather than a multipart body — players
        // never send one, and building multipart/byteranges for them would be
        // dead code.
        if (! preg_match('/^bytes=(\d*)-(\d*)$/', trim($header), $m)) {
            return null;
        }

        [$rawStart, $rawEnd] = [$m[1], $m[2]];

        if ($rawStart === '' && $rawEnd === '') {
            return null;
        }

        if ($rawStart === '') {
            // `bytes=-500`: the final 500 bytes. Players use this to read the
            // moov atom of an MP4 that was not prepared for streaming.
            $length = (int) $rawEnd;

            if ($length <= 0) {
                return false;
            }

            $start = max(0, $size - $length);
            $end   = $size - 1;
        } else {
            $start = (int) $rawStart;
            $end   = $rawEnd === '' ? $size - 1 : (int) $rawEnd;
        }

        $end = min($end, $size - 1);

        if ($start > $end || $start >= $size) {
            return false;
        }

        return [$start, $end];
    }

    /**
     * Keep the bytes out of every cache between us and the viewer.
     *
     * Cloudflare sits in front of this application and will happily cache a
     * 200 with a video content type. A cached lesson video is served without
     * the access check ever running again, to anybody who has the URL.
     */
    private function harden(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Accel-Buffering', 'no');

        return $response;
    }

    private function isRemote(string $diskName): bool
    {
        return in_array(config("filesystems.disks.{$diskName}.driver"), ['s3'], true);
    }
}
