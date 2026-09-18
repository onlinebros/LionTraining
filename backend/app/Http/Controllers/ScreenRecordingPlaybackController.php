<?php

namespace App\Http\Controllers;

use App\Models\ScreenRecording;
use App\Services\ScreenRecording\RecordingStorage;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Playback for stored recordings.
 *
 * Objects on the Space are private, so nothing is ever linked to directly.
 * Every request lands here first, gets checked against the recording's
 * visibility, and is only then handed a signed URL that expires.
 */
class ScreenRecordingPlaybackController extends Controller
{
    public function __construct(private readonly RecordingStorage $storage)
    {
    }

    /** Standalone watch page — what a share link opens. */
    public function watch(Request $request, ScreenRecording $recording)
    {
        $this->authorizeView($request, $recording);

        $recording->recordViewed();

        return view('recordings.watch', [
            'recording' => $recording->load('author'),
            'playback'  => $this->storage->temporaryUrl($recording) ?? $recording->streamUrl(),
        ]);
    }

    /**
     * The video bytes.
     *
     * On object storage this is a redirect to a signed URL so the file streams
     * from the CDN edge rather than through PHP. On a local disk there is no
     * signed URL to hand out, so the file is served here — via BinaryFileResponse,
     * which honours Range requests and keeps seeking working.
     */
    public function stream(Request $request, ScreenRecording $recording): Response
    {
        $this->authorizeView($request, $recording);

        abort_unless($recording->isReady(), 404);

        if ($url = $this->storage->temporaryUrl($recording)) {
            return redirect()->away($url);
        }

        $disk = $this->storage->disk($recording);

        abort_unless($disk->exists($recording->path), 404);

        if ($disk instanceof FilesystemAdapter) {
            return response()->file($disk->path($recording->path), [
                'Content-Type' => $recording->mime ?: 'video/webm',
            ]);
        }

        return $disk->response($recording->path);
    }

    /** Poster frame, subject to the same access rules as the video. */
    public function poster(Request $request, ScreenRecording $recording): Response
    {
        $this->authorizeView($request, $recording);

        abort_unless(filled($recording->thumbnail_path), 404);

        if ($url = $this->storage->temporaryThumbnailUrl($recording)) {
            return redirect()->away($url);
        }

        $disk = $this->storage->disk($recording);

        abort_unless($disk->exists($recording->thumbnail_path), 404);

        return $disk->response($recording->thumbnail_path);
    }

    private function authorizeView(Request $request, ScreenRecording $recording): void
    {
        abort_unless($recording->viewableBy($request->user()), 403, 'This recording is not available to you.');
    }
}
