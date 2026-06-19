<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\TrainingContentBlock;
use App\Models\TrainingLesson;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class TrainingContentBlockController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'lesson_id'      => 'required|exists:training_lessons,id',
            'type'           => 'required|in:video,text,download',
            'title'          => 'nullable|string|max:255',
            'video_url'      => 'nullable|string|max:1000',
            'video_provider' => 'nullable|in:youtube,vimeo,file',
            'body'           => 'nullable|string',
            'sort_order'     => 'nullable|integer',
            'is_active'      => 'boolean',
            'upload_file'    => 'nullable|file|max:102400', // 100 MB
        ]);

        $data['is_active'] = $request->boolean('is_active', true);

        if ($request->hasFile('upload_file')) {
            $file              = $request->file('upload_file');
            $path              = $file->store('training/downloads', 'public');
            $data['file_path'] = $path;
            $data['file_name'] = $file->getClientOriginalName();
            $data['file_size'] = $file->getSize();
            $data['file_mime'] = $file->getMimeType();
        }

        $lesson = TrainingLesson::find($data['lesson_id']);
        $data['sort_order'] = $data['sort_order'] ?? ($lesson->allContentBlocks()->max('sort_order') + 10);

        TrainingContentBlock::create($data);

        return redirect()->route('admin.training.lessons.edit', $data['lesson_id'])->with('success', 'Block added.');
    }

    public function update(Request $request, TrainingContentBlock $block)
    {
        $data = $request->validate([
            'title'          => 'nullable|string|max:255',
            'video_url'      => 'nullable|string|max:1000',
            'video_provider' => 'nullable|in:youtube,vimeo,file',
            'body'           => 'nullable|string',
            'sort_order'     => 'nullable|integer',
            'is_active'      => 'boolean',
            'upload_file'    => 'nullable|file|max:102400',
        ]);

        $data['is_active'] = $request->boolean('is_active');

        if ($request->hasFile('upload_file')) {
            if ($block->file_path) Storage::disk('public')->delete($block->file_path);
            $file              = $request->file('upload_file');
            $path              = $file->store('training/downloads', 'public');
            $data['file_path'] = $path;
            $data['file_name'] = $file->getClientOriginalName();
            $data['file_size'] = $file->getSize();
            $data['file_mime'] = $file->getMimeType();
        }

        $block->update($data);

        return redirect()->route('admin.training.lessons.edit', $block->lesson_id)->with('success', 'Block updated.');
    }

    public function destroy(TrainingContentBlock $block)
    {
        $lessonId = $block->lesson_id;
        if ($block->file_path) Storage::disk('public')->delete($block->file_path);
        $block->delete();
        return redirect()->route('admin.training.lessons.edit', $lessonId)->with('success', 'Block removed.');
    }

    public function reorder(Request $request)
    {
        $request->validate(['order' => 'required|array', 'order.*' => 'integer']);

        foreach ($request->order as $i => $id) {
            TrainingContentBlock::where('id', $id)->update(['sort_order' => ($i + 1) * 10]);
        }

        return response()->json(['ok' => true]);
    }
}
