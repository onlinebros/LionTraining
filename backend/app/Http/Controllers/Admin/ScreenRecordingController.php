<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\ScreenRecording;
use App\Models\TrainingCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use App\Jobs\ComposeScreenRecordingJob;
use App\Jobs\TrimScreenRecordingJob;
use App\Services\ScreenRecording\MediaInspector;
use App\Services\ScreenRecording\RecordingStorage;
use App\Services\ScreenRecording\VideoComposer;
use App\Services\ScreenRecording\VideoTrimmer;

/**
 * The recording studio and the library control panel behind it.
 *
 * Capture happens entirely in the browser (see assets/js/screen-recorder.js);
 * this controller owns the upload session and the catalogue metadata that
 * presentations and funnels build on.
 */
class ScreenRecordingController extends Controller
{
    public function __construct(private readonly RecordingStorage $storage)
    {
    }

    // ── Library ───────────────────────────────────────────────────────────────

    public function index(Request $request)
    {
        $query = ScreenRecording::with(['author', 'category', 'requiredRole'])
            ->withCount('presentations')
            ->latest();

        if ($search = trim((string) $request->get('q'))) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->get('status'));
        }

        if ($request->filled('category')) {
            $query->where('category_id', $request->integer('category'));
        }

        if ($request->get('state') === 'published') {
            $query->where('is_published', true);
        } elseif ($request->get('state') === 'draft') {
            $query->where('is_published', false);
        }

        return view('admin.screen-recordings.index', [
            'recordings' => $query->paginate(20)->withQueryString(),
            'categories' => TrainingCategory::orderBy('name')->get(),
            'stats'      => [
                'total'     => ScreenRecording::count(),
                'published' => ScreenRecording::where('is_published', true)->count(),
                'bytes'     => (int) ScreenRecording::ready()->sum('size_bytes'),
                'disk'      => config('screen-recordings.disk'),
            ],
        ]);
    }

    /** The capture studio itself. */
    public function studio()
    {
        return view('admin.screen-recordings.studio', [
            'categories'  => TrainingCategory::orderBy('name')->get(),
            'chunkBytes'  => $this->storage->chunkBytes(),
            'maxDuration' => (int) config('screen-recordings.max_duration_seconds'),
            'maxBytes'    => (int) config('screen-recordings.max_bytes'),
            'corners'     => ScreenRecording::CORNERS,
        ]);
    }

    /** Bring in a video recorded somewhere else. */
    public function uploadForm()
    {
        return view('admin.screen-recordings.upload', [
            'categories' => TrainingCategory::orderBy('name')->get(),
            'chunkBytes' => $this->storage->chunkBytes(),
            'maxBytes'   => (int) config('screen-recordings.max_bytes'),
            'inspector'  => app(MediaInspector::class)->available(),
        ]);
    }

    public function show(ScreenRecording $recording)
    {
        $recording->load(['author', 'category', 'requiredRole', 'presentations']);

        return view('admin.screen-recordings.show', [
            'recording'  => $recording,
            'categories' => TrainingCategory::orderBy('name')->get(),
            'roles'      => Role::orderBy('level')->get(),
            'playback'   => $this->storage->temporaryUrl($recording) ?? $recording->streamUrl(),
        ]);
    }

    // ── Upload session ────────────────────────────────────────────────────────

    /**
     * Open an upload session. Called the moment recording starts, so chunks can
     * stream up alongside the capture instead of waiting for the stop button.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title'            => 'nullable|string|max:200',
            'source'           => ['required', Rule::in(['screen', 'screen+camera', 'camera', ScreenRecording::SOURCE_UPLOAD])],
            'webcam_position'  => ['nullable', Rule::in(array_keys(ScreenRecording::CORNERS))],
            'has_mic_audio'    => 'boolean',
            'has_system_audio' => 'boolean',
            'category_id'      => 'nullable|exists:training_categories,id',
        ]);

        $recording = ScreenRecording::create([
            'user_id'          => $request->user()->id,
            'title'            => ($data['title'] ?? null) ?: 'Recording '.now()->format('M j, Y g:ia'),
            'category_id'      => $data['category_id'] ?? null,
            'source'           => $data['source'],
            'webcam_position'  => $data['source'] === 'screen+camera' ? ($data['webcam_position'] ?? 'bottom-right') : null,
            'has_mic_audio'    => $request->boolean('has_mic_audio'),
            'has_system_audio' => $request->boolean('has_system_audio'),
            'status'           => ScreenRecording::STATUS_UPLOADING,
        ]);

        return response()->json([
            'uuid'         => $recording->uuid,
            'chunk_bytes'  => $this->storage->chunkBytes(),
            'chunk_url'    => route('admin.screen-recordings.chunk', $recording),
            'finalize_url' => route('admin.screen-recordings.finalize', $recording),
            'abort_url'    => route('admin.screen-recordings.abort', $recording),
        ], 201);
    }

    public function chunk(Request $request, ScreenRecording $recording): JsonResponse
    {
        $this->authorizeUpload($request, $recording);

        $request->validate([
            'offset' => 'required|integer|min:0',
            'chunk'  => 'required|file',
        ]);

        try {
            $received = $this->storage->appendChunk(
                $recording,
                $request->file('chunk'),
                $request->integer('offset'),
            );
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['bytes_received' => $received]);
    }

    /** Close the session: push the assembled file to storage and catalogue it. */
    public function finalize(Request $request, ScreenRecording $recording): JsonResponse
    {
        $this->authorizeUpload($request, $recording);

        $data = $request->validate([
            'title'            => 'nullable|string|max:200',
            'description'      => 'nullable|string|max:5000',
            'category_id'      => 'nullable|exists:training_categories,id',
            'mime'             => 'nullable|string|max:100',
            // A hint only — for uploads ffprobe overrides it, and for studio
            // captures the recorder stops itself. Capping this at the studio's
            // limit would reject a long uploaded file before it was measured.
            'duration_seconds' => 'nullable|integer|min:0|max:86400',
            'width'            => 'nullable|integer|min:1|max:10000',
            'height'           => 'nullable|integer|min:1|max:10000',
            'poster'           => 'nullable|image|mimes:jpeg,jpg,png|max:4096',
        ]);

        $meta = [
            'mime'             => $data['mime'] ?? 'video/webm',
            'duration_seconds' => $data['duration_seconds'] ?? null,
            'width'            => $data['width'] ?? null,
            'height'           => $data['height'] ?? null,
        ];

        // An uploaded file is whatever bytes the browser sent, so measure it
        // here rather than believing what it claimed. This is also the only
        // point at which we can refuse something that is not a video at all,
        // before it reaches the library and somebody schedules it.
        $poster = null;

        if ($recording->source === ScreenRecording::SOURCE_UPLOAD) {
            $inspector = app(MediaInspector::class);
            $part      = $this->storage->tempPath($recording);

            if ($inspector->available()) {
                $probe = $inspector->inspect($part);

                if (! $probe['ok']) {
                    $this->storage->discardUpload($recording);
                    $recording->forceDelete();

                    return response()->json([
                        'message' => 'That file does not look like a playable video. MP4 works best.',
                    ], 422);
                }

                $meta['duration_seconds'] = $probe['duration'];
                $meta['width']            = $probe['width'];
                $meta['height']           = $probe['height'];
                $meta['mime']             = $this->mimeFor($probe['format'], $data['mime'] ?? null);

                $poster = $part.'-poster.jpg';

                if (! $inspector->extractPoster($part, $poster)) {
                    $poster = null;
                }
            }
        }

        try {
            $this->storage->finalize($recording, $meta);

            if ($poster && is_file($poster)) {
                $this->storage->storeThumbnail(
                    $recording,
                    new \Illuminate\Http\UploadedFile($poster, 'poster.jpg', 'image/jpeg', null, true),
                );
                @unlink($poster);
            }

            if ($request->hasFile('poster')) {
                $this->storage->storeThumbnail($recording, $request->file('poster'));
            }
        } catch (\Throwable $e) {
            Log::error('Screen recording finalize failed', [
                'recording' => $recording->uuid,
                'error'     => $e->getMessage(),
            ]);

            // Keep the part-file: the capture is irreplaceable, and a retry of
            // finalize can still place it once the storage problem is fixed.
            $recording->forceFill([
                'status'       => ScreenRecording::STATUS_FAILED,
                'upload_error' => $e->getMessage(),
            ])->save();

            return response()->json([
                'message' => 'The recording could not be stored: '.$e->getMessage(),
            ], 500);
        }

        $recording->forceFill(array_filter([
            'title'       => $data['title'] ?? null,
            'description' => $data['description'] ?? null,
            'category_id' => $data['category_id'] ?? null,
        ], fn ($value) => $value !== null))->save();

        return response()->json([
            'uuid'         => $recording->uuid,
            'redirect_url' => route('admin.screen-recordings.show', $recording),
        ]);
    }

    /** Cancel mid-capture: throw away the partial file and the catalogue row. */
    public function abort(Request $request, ScreenRecording $recording): JsonResponse
    {
        $this->authorizeUpload($request, $recording);

        $this->storage->discardUpload($recording);
        $recording->forceDelete();

        return response()->json(['ok' => true]);
    }

    // ── Catalogue management ──────────────────────────────────────────────────

    public function update(Request $request, ScreenRecording $recording): RedirectResponse
    {
        $data = $request->validate([
            'title'            => 'required|string|max:200',
            'description'      => 'nullable|string|max:5000',
            'category_id'      => 'nullable|exists:training_categories,id',
            'visibility'       => ['required', Rule::in(array_keys(ScreenRecording::VISIBILITIES))],
            'required_role_id' => 'nullable|exists:roles,id',
        ]);

        $data['member_schedulable'] = $request->boolean('member_schedulable');

        // A role gate with no role selected would silently let everyone in,
        // which is the opposite of what picking it means.
        if ($data['visibility'] !== ScreenRecording::VISIBILITY_ROLE) {
            $data['required_role_id'] = null;
        } elseif (empty($data['required_role_id'])) {
            return back()->withInput()
                ->withErrors(['required_role_id' => 'Choose the role level that unlocks this recording.']);
        }

        $recording->update($data);

        return redirect()->route('admin.screen-recordings.show', $recording)
            ->with('success', 'Recording updated.');
    }

    /**
     * Retry placing a capture whose upload to storage failed.
     *
     * finalize() deliberately keeps the assembled part-file when the storage
     * disk rejects it — the recording cannot be made again — so once the Space
     * is reachable the same bytes can still be placed.
     */
    public function retryStore(ScreenRecording $recording): RedirectResponse
    {
        if ($recording->isReady()) {
            return back()->with('error', 'This recording is already stored.');
        }

        try {
            $this->storage->finalize($recording, ['mime' => $recording->mime ?: 'video/webm']);
        } catch (\Throwable $e) {
            $recording->forceFill([
                'status'       => ScreenRecording::STATUS_FAILED,
                'upload_error' => $e->getMessage(),
            ])->save();

            return back()->with('error', 'Still could not store it: '.$e->getMessage());
        }

        return back()->with('success', 'Recording stored.');
    }

    // ── Combining ─────────────────────────────────────────────────────────────

    /** The picker: choose recordings and put them in order. */
    public function composeForm()
    {
        return view('admin.screen-recordings.compose', [
            'recordings' => ScreenRecording::ready()
                ->whereNull('deleted_at')
                ->with('author')
                ->latest()
                ->get(),
            'maxClips'  => (int) config('screen-recordings.compose.max_clips'),
            'available' => app(VideoComposer::class)->available(),
        ]);
    }

    /**
     * Create the combined recording and queue the build.
     *
     * The composition is an ordinary recording row from the moment it is
     * created — it just has no file yet. That means it appears in the library
     * straight away with a "Building" badge, rather than the admin pressing a
     * button and seeing nothing happen for four minutes.
     */
    public function compose(Request $request): RedirectResponse
    {
        $max = (int) config('screen-recordings.compose.max_clips');

        $data = $request->validate([
            'title'       => 'required|string|max:200',
            'category_id' => 'nullable|exists:training_categories,id',
            'clips'       => "required|array|min:2|max:{$max}",
            'clips.*'     => 'integer|exists:screen_recordings,id',
        ], [
            'clips.min' => 'Pick at least two recordings to combine.',
            'clips.max' => "You can combine up to {$max} recordings at once.",
        ]);

        if (! app(VideoComposer::class)->available()) {
            return back()->withInput()
                ->with('error', 'Combining needs ffmpeg, which is not installed on this server.');
        }

        $sources = ScreenRecording::ready()->findMany($data['clips']);

        if ($sources->count() < 2) {
            return back()->withInput()
                ->with('error', 'Those recordings are not all finished uploading yet.');
        }

        $composition = ScreenRecording::create([
            'user_id'     => $request->user()->id,
            'title'       => $data['title'],
            'category_id' => $data['category_id'] ?? null,
            'source'      => ScreenRecording::SOURCE_COMPOSITION,
            'status'      => ScreenRecording::STATUS_RENDERING,
        ]);

        // Keyed by id so the clip order follows what was submitted, not the
        // order the database happened to return the sources in.
        $byId = $sources->keyBy('id');

        foreach ($data['clips'] as $index => $id) {
            if (! $source = $byId->get($id)) {
                continue;
            }

            $composition->clips()->create([
                'source_recording_id' => $source->id,
                'source_title'        => $source->title,
                'sort_order'          => ($index + 1) * 10,
            ]);
        }

        ComposeScreenRecordingJob::dispatch($composition->id);

        return redirect()->route('admin.screen-recordings.show', $composition)
            ->with('success', 'Building your combined video — this page updates itself when it is ready.');
    }

    /** Rebuild after the clips or their sources have changed. */
    public function rebuild(ScreenRecording $recording): RedirectResponse
    {
        if (! $recording->isComposition()) {
            return back()->with('error', 'This recording was not built from clips.');
        }

        if ($recording->isRendering()) {
            return back()->with('error', 'It is already being built.');
        }

        $recording->forceFill([
            'status'       => $recording->isReady() ? $recording->status : ScreenRecording::STATUS_RENDERING,
            'upload_error' => null,
        ])->save();

        ComposeScreenRecordingJob::dispatch($recording->id);

        return back()->with('success', 'Rebuilding — the current version keeps playing until the new one is ready.');
    }

    /**
     * Queue a trim to [start, end].
     *
     * Bounds come from the ORIGINAL length, not the current one: after a first
     * trim the file is shorter, but every trim re-cuts the pristine capture, so
     * a range can always be widened back out again.
     */
    public function trim(Request $request, ScreenRecording $recording): RedirectResponse
    {
        if (! $recording->isReady()) {
            return back()->with('error', 'This recording is not stored yet.');
        }

        if ($recording->isTrimming()) {
            return back()->with('error', 'A trim is already running on this recording.');
        }

        if (! app(VideoTrimmer::class)->available()) {
            return back()->with('error', 'Trimming needs ffmpeg, which is not installed on this server.');
        }

        $full = (int) ($recording->original_duration_seconds ?: $recording->duration_seconds);

        $data = $request->validate([
            'trim_start' => 'required|numeric|min:0',
            'trim_end'   => 'required|numeric|gt:trim_start',
        ]);

        $start = round((float) $data['trim_start'], 2);
        $end   = round((float) $data['trim_end'], 2);

        if ($end - $start < 0.5) {
            return back()->withInput()
                ->withErrors(['trim_end' => 'The kept section must be at least half a second long.']);
        }

        // Allow a second of slack: a player's reported duration and the
        // container's own can disagree slightly, and rejecting the end point
        // the admin just scrubbed to would be baffling.
        if ($full > 0 && $start > $full) {
            return back()->withInput()
                ->withErrors(['trim_start' => "The recording is only {$full} seconds long."]);
        }

        $recording->forceFill([
            'trim_status' => ScreenRecording::TRIM_QUEUED,
            'trim_error'  => null,
        ])->save();

        TrimScreenRecordingJob::dispatch($recording->id, $start, $end);

        return back()->with('success', 'Trimming — the recording stays playable until the new version is ready.');
    }

    /** Put the untouched capture back. */
    public function revertTrim(ScreenRecording $recording): RedirectResponse
    {
        if ($recording->isTrimming()) {
            return back()->with('error', 'Wait for the running trim to finish first.');
        }

        try {
            app(VideoTrimmer::class)->revert($recording);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'The original recording has been restored.');
    }

    public function publish(Request $request, ScreenRecording $recording): RedirectResponse
    {
        if (! $recording->isReady()) {
            return back()->with('error', 'This recording is not stored yet, so it cannot be published.');
        }

        $publish = $request->boolean('publish');

        $recording->update([
            'is_published' => $publish,
            'published_at' => $publish ? ($recording->published_at ?? now()) : null,
        ]);

        return back()->with('success', $publish ? 'Recording published.' : 'Recording unpublished.');
    }

    public function destroy(ScreenRecording $recording): RedirectResponse
    {
        $title = $recording->title;

        // presentations.recording_id restricts deletes, soft-deleted showings
        // included. Refuse before touching storage: failing at the row delete
        // would leave a catalogue entry whose file is already gone.
        if ($recording->presentations()->withTrashed()->exists()) {
            return back()->with('error', "\"{$title}\" is used by a presentation. Delete the presentation first.");
        }

        $this->storage->delete($recording);
        $recording->forceDelete();

        return redirect()->route('admin.screen-recordings.index')
            ->with('success', "\"{$title}\" deleted from storage.");
    }

    // ── Internals ─────────────────────────────────────────────────────────────

    /**
     * Trust ffprobe's container over the browser's guess.
     *
     * Browsers routinely report an empty type, or application/octet-stream, for
     * a file dragged in from a phone. What is stored decides whether it plays.
     */
    private function mimeFor(?string $format, ?string $claimed): string
    {
        $format = (string) $format;

        return match (true) {
            str_contains($format, 'mp4'), str_contains($format, 'mov') => 'video/mp4',
            str_contains($format, 'webm')                              => 'video/webm',
            str_contains($format, 'matroska')                          => 'video/x-matroska',
            default                                                    => $claimed ?: 'video/mp4',
        };
    }

    /**
     * An in-progress upload belongs to whoever started it. Another admin has no
     * business appending bytes to someone else's capture, even though they can
     * manage the finished recording.
     */
    private function authorizeUpload(Request $request, ScreenRecording $recording): void
    {
        abort_unless($recording->user_id === $request->user()->id, 403, 'This upload belongs to another admin.');
        abort_if($recording->status === ScreenRecording::STATUS_READY, 409, 'This recording is already stored.');
    }
}
