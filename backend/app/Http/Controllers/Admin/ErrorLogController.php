<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ErrorLog;
use Illuminate\Http\Request;

class ErrorLogController extends Controller
{
    public function index(Request $request)
    {
        $status = $request->get('status', 'new');

        $logs = ErrorLog::when($status !== 'all', fn($q) => $q->where('status', $status))
            ->latest()
            ->paginate(25)
            ->withQueryString();

        $counts = [
            'new'          => ErrorLog::where('status', 'new')->count(),
            'acknowledged' => ErrorLog::where('status', 'acknowledged')->count(),
            'resolved'     => ErrorLog::where('status', 'resolved')->count(),
            'all'          => ErrorLog::count(),
        ];

        return view('admin.error-logs.index', compact('logs', 'status', 'counts'));
    }

    public function show(ErrorLog $errorLog)
    {
        if ($errorLog->isNew()) {
            $errorLog->update(['status' => 'acknowledged']);
        }

        return view('admin.error-logs.show', compact('errorLog'));
    }

    public function update(Request $request, ErrorLog $errorLog)
    {
        $data = $request->validate([
            'status'           => 'required|in:new,acknowledged,resolved',
            'resolution_notes' => 'nullable|string|max:2000',
        ]);

        if ($data['status'] === 'resolved' && !$errorLog->isResolved()) {
            $data['resolved_at'] = now();
        }

        $errorLog->update($data);

        return back()->with('success', 'Error log updated.');
    }

    public function destroy(ErrorLog $errorLog)
    {
        $errorLog->delete();
        return redirect()->route('admin.error-logs.index')->with('success', 'Error log deleted.');
    }

    public function bulkResolve(Request $request)
    {
        $ids = $request->validate(['ids' => 'required|array', 'ids.*' => 'integer'])['ids'];

        ErrorLog::whereIn('id', $ids)->update([
            'status'      => 'resolved',
            'resolved_at' => now(),
        ]);

        return back()->with('success', count($ids) . ' errors marked as resolved.');
    }
}
