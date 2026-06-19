<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\TrainingContentBlock;
use App\Models\VideoAsset;
use App\Services\VimeoUploadService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class VideoAssetController extends Controller
{
    public function index(Request $request)
    {
        $query = VideoAsset::latest();

        if ($status = $request->get('status')) {
            $query->where('vimeo_status', $status);
        }
        if ($source = $request->get('source')) {
            $query->where('source', $source);
        }

        $assets = $query->paginate(25)->withQueryString();

        $statusCounts = VideoAsset::selectRaw('vimeo_status, count(*) as total')
            ->groupBy('vimeo_status')
            ->pluck('total', 'vimeo_status');

        return view('admin.video-assets.index', compact('assets', 'statusCounts'));
    }

    public function show(VideoAsset $videoAsset)
    {
        $videoAsset->load('contentBlock.lesson', 'kartraImports');
        $contentBlocks = TrainingContentBlock::with('lesson')->whereNull('video_asset_id')->get();

        return view('admin.video-assets.show', compact('videoAsset', 'contentBlocks'));
    }

    public function update(Request $request, VideoAsset $videoAsset)
    {
        $data = $request->validate([
            'title'          => 'required|string|max:255',
            'description'    => 'nullable|string',
            'vimeo_privacy'  => 'nullable|in:disable,anybody,password,nobody',
            'content_block_id' => 'nullable|exists:training_content_blocks,id',
            'notes'          => 'nullable|string',
        ]);

        $videoAsset->update($data);

        return redirect()->route('admin.video-assets.show', $videoAsset)->with('success', 'Video asset updated.');
    }

    public function uploadToVimeo(VideoAsset $videoAsset, VimeoUploadService $vimeo)
    {
        if ($videoAsset->vimeo_status === 'uploaded') {
            return back()->with('info', 'Already uploaded to Vimeo.');
        }

        if (!$videoAsset->isDownloaded()) {
            return back()->with('error', 'Local file not found. Download the video first.');
        }

        $ok = $vimeo->upload($videoAsset);

        if ($ok) {
            $vimeo->syncToContentBlock($videoAsset->fresh());
            return back()->with('success', 'Successfully uploaded to Vimeo: ' . $videoAsset->fresh()->vimeo_url);
        }

        return back()->with('error', 'Vimeo upload failed: ' . $videoAsset->fresh()->vimeo_upload_error);
    }

    public function assignToBlock(Request $request, VideoAsset $videoAsset)
    {
        $data = $request->validate([
            'content_block_id' => 'required|exists:training_content_blocks,id',
        ]);

        $videoAsset->update(['content_block_id' => $data['content_block_id']]);

        $block = TrainingContentBlock::find($data['content_block_id']);
        $block->update(['video_asset_id' => $videoAsset->id]);

        // If already on Vimeo, sync the embed URL
        if ($videoAsset->isOnVimeo()) {
            $block->update([
                'video_url'      => $videoAsset->vimeo_embed_url,
                'video_provider' => 'vimeo',
            ]);
        }

        return back()->with('success', 'Video assigned to content block.');
    }

    public function destroy(VideoAsset $videoAsset)
    {
        if ($videoAsset->local_path) {
            Storage::disk('local')->delete($videoAsset->local_path);
        }
        $videoAsset->delete();

        return redirect()->route('admin.video-assets.index')->with('success', 'Video asset deleted.');
    }
}
