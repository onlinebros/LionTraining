<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\KartraImport;
use App\Models\TrainingCategory;
use App\Models\TrainingLesson;
use App\Services\KartraImportService;
use Illuminate\Http\Request;

class KartraImportController extends Controller
{
    public function index()
    {
        $topLevel = KartraImport::with('children.children', 'videoAsset', 'localCategory', 'localLesson')
            ->whereNull('parent_id')
            ->orderBy('kartra_order')
            ->paginate(25);

        $stats = [
            'total'       => KartraImport::count(),
            'discovered'  => KartraImport::where('status', 'discovered')->count(),
            'downloaded'  => KartraImport::where('status', 'downloaded')->count(),
            'mapped'      => KartraImport::where('status', 'mapped')->count(),
            'failed'      => KartraImport::where('status', 'failed')->count(),
            'videos'      => KartraImport::whereNotNull('kartra_video_url')->count(),
        ];

        return view('admin.kartra.index', compact('topLevel', 'stats'));
    }

    public function show(KartraImport $kartraImport)
    {
        $kartraImport->load('children.children', 'videoAsset', 'localCategory', 'localLesson', 'localContentBlock', 'parent');
        $categories = TrainingCategory::orderBy('name')->get();
        $lessons    = TrainingLesson::with('category')->orderBy('title')->get();

        return view('admin.kartra.show', compact('kartraImport', 'categories', 'lessons'));
    }

    public function map(Request $request, KartraImport $kartraImport)
    {
        $data = $request->validate([
            'local_category_id'    => 'nullable|exists:training_categories,id',
            'local_lesson_id'      => 'nullable|exists:training_lessons,id',
            'status'               => 'nullable|in:discovered,downloading,downloaded,mapped,skipped,failed',
        ]);

        $data['status'] = $data['status'] ?? 'mapped';
        $kartraImport->update($data);

        return back()->with('success', 'Import record updated.');
    }

    public function downloadVideos(Request $request)
    {
        $service = new KartraImportService(
            $request->input('url', 'https://besafe.kartra.com/portal/Lion'),
            $request->input('email', 'john@ihub.global'),
            $request->input('password', 'peZMDgQs')
        );

        $result = $service->downloadPendingVideos();

        $msg = "Downloaded {$result['count']} video(s).";
        if (!empty($result['errors'])) {
            $msg .= ' ' . count($result['errors']) . ' error(s) — check logs.';
        }

        return back()->with('success', $msg);
    }

    public function importJson(Request $request)
    {
        $request->validate(['json_file' => 'required|file|mimes:json,txt|max:10240']);

        $content = file_get_contents($request->file('json_file')->getRealPath());
        $data    = json_decode($content, true);

        if (!is_array($data)) {
            return back()->with('error', 'Invalid JSON: must be an array of module/lesson objects.');
        }

        $service = new KartraImportService('', '', '');
        $count   = $service->importFromJson($data);

        return back()->with('success', "Imported $count items from JSON file.");
    }

    public function destroy(KartraImport $kartraImport)
    {
        $kartraImport->delete();
        return redirect()->route('admin.kartra.index')->with('success', 'Import record deleted.');
    }
}
