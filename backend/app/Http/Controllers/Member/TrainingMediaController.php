<?php

namespace App\Http\Controllers\Member;

use App\Http\Controllers\Controller;
use App\Models\TrainingContentBlock;
use App\Services\Training\TrainingStorage;
use App\Support\TrainingAccess;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves the bytes of the training library: lesson videos and worksheets.
 *
 * Every request re-runs the full access check. That is the point of routing
 * media through the application at all — the library is the product, and a URL
 * that keeps working after someone cancels, or that a member can paste to a
 * non-member, gives it away.
 *
 * The checks, in order:
 *
 *   1. signed in                          (auth middleware)
 *   2. the library is open to them        (training.visible middleware)
 *   3. membership is live                 (subscribed middleware)
 *   4. not parked on commission hold      (training.unlocked middleware)
 *   5. this lesson has released to them    (below — role and drip)
 *
 * 5 is here rather than in middleware because it is per-lesson: it is the
 * difference between "you are a member" and "this module has opened for you".
 */
class TrainingMediaController extends Controller
{
    public function __construct(private readonly TrainingStorage $storage)
    {
    }

    /** Stream a lesson video. Range-aware, so the scrub bar works. */
    public function video(Request $request, TrainingContentBlock $block): Response
    {
        $this->authorizeBlock($request, $block);

        $asset = $block->videoAsset;

        abort_unless($asset !== null, 404);

        $path = $asset->mediaPath();

        abort_unless(filled($path), 404);

        return $this->storage->stream(
            $request,
            $asset->mediaDisk(),
            $path,
            $asset->mime_type ?: 'video/mp4',
        );
    }

    /** The poster frame, under exactly the same rules as the video. */
    public function poster(Request $request, TrainingContentBlock $block): Response
    {
        $this->authorizeBlock($request, $block);

        $asset = $block->videoAsset;

        abort_unless($asset !== null && filled($asset->thumbnail_path), 404);

        $disk = $asset->mediaDisk();

        abort_unless($this->storage->exists($disk, $asset->thumbnail_path), 404);

        if ($url = $this->storage->temporaryUrl($disk, $asset->thumbnail_path)) {
            return redirect()->away($url);
        }

        // Same cache rules as the video. A poster frame is a still from the
        // lesson, and Cloudflare caching one means serving it without the
        // access check to anyone holding the URL.
        return $this->storage->disk($disk)->response($asset->thumbnail_path, null, [
            'Cache-Control'          => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** Download a worksheet. */
    public function download(Request $request, TrainingContentBlock $block): Response
    {
        $this->authorizeBlock($request, $block);

        abort_unless(filled($block->file_path), 404);

        $disk     = $block->fileDiskName();
        $filename = $block->file_name ?: basename($block->file_path);

        // Written before the disk column existed, or seeded straight from the
        // Kartra download: fall back to whichever local disk holds it.
        if (! $this->storage->exists($disk, $block->file_path)) {
            foreach (['local', 'public'] as $fallback) {
                if ($this->storage->exists($fallback, $block->file_path)) {
                    $disk = $fallback;
                    break;
                }
            }
        }

        abort_unless($this->storage->exists($disk, $block->file_path), 404);

        if ($url = $this->storage->temporaryUrl($disk, $block->file_path, $filename)) {
            return redirect()->away($url);
        }

        return $this->storage->disk($disk)->download($block->file_path, $filename, [
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }

    /**
     * Refuse anything the viewer has not earned access to.
     *
     * 404 rather than 403 throughout: a lesson that has not released yet should
     * not be enumerable, and neither should an inactive block.
     */
    private function authorizeBlock(Request $request, TrainingContentBlock $block): void
    {
        $user = $request->user();

        abort_unless(TrainingAccess::visibleTo($user), 404);

        // Admins preview the library as built, ahead of any drip date.
        if ($user->isAdmin()) {
            return;
        }

        abort_unless($block->is_active, 404);

        $lesson = $block->lesson()->with('category')->first();

        abort_unless($lesson !== null && $lesson->is_published, 404);
        abort_unless($lesson->category?->is_active, 404);
        abort_unless($lesson->userCanAccess($user->loadMissing('role')), 404);

        // A lesson can sit in an open category whose PARENT is still locked.
        // userCanAccess() only looks at the lesson's own category, so walk up.
        $ancestor = $lesson->category->parent;

        while ($ancestor) {
            abort_unless($ancestor->is_active && $ancestor->userCanAccess($user), 404);
            $ancestor = $ancestor->parent;
        }
    }
}
