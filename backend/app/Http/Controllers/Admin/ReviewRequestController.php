<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ChangeRequest;
use App\Services\RequestAssistant\Payload;
use App\Services\RequestAssistant\Upstream;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Dev's review screens: the change requests kept on the live site, read and
 * decided from here. The only place a request is approved. Exists only on a
 * server connected upstream (request-assistant:connect); super admins only.
 * A decision is a record, not a build: hand the downloaded plan to whoever
 * implements it.
 */
class ReviewRequestController extends Controller
{
    public function __construct(private Upstream $upstream) {}

    public function index(Request $request)
    {
        $status = (string) $request->get('status', 'submitted');

        try {
            $data = $this->upstream->call('get', 'review/requests', ['status' => $status]);
            $requests = collect($data['requests'] ?? [])->map(fn (array $r) => Payload::hydrate($r));
            $counts = collect($data['counts'] ?? []);
            $unavailable = null;
        } catch (RuntimeException $e) {
            [$requests, $counts, $unavailable] = [collect(), collect(), $e->getMessage()];
        }

        return view('admin.review-requests.index', [
            'requests' => $requests, 'counts' => $counts, 'status' => $status,
            'unavailable' => $unavailable, 'upstream' => $this->upstream->url(),
        ]);
    }

    public function show(int $changeRequest)
    {
        try {
            $request = $this->fetch($changeRequest);
        } catch (RuntimeException $e) {
            return redirect()->route('admin.review-requests.index')->with('error', $e->getMessage());
        }

        $cost = $request->messages->sum(fn ($m) => (float) ($m->meta['cost_usd'] ?? 0));

        return view('admin.review-requests.show', [
            'changeRequest' => $request, 'cost' => $cost, 'upstream' => $this->upstream->url(),
        ]);
    }

    public function review(Request $request, int $changeRequest): RedirectResponse
    {
        $data = $request->validate([
            'status' => 'required|in:'.implode(',', ChangeRequest::REVIEW_STATUSES),
            'review_notes' => 'nullable|string|max:10000',
        ]);

        return $this->send($changeRequest, 'patch', "review/requests/{$changeRequest}", $data + ['reviewer_name' => $request->user()->name],
            'Decision saved on the live site: '.ChangeRequest::STATUSES[$data['status']].'.');
    }

    public function comment(Request $request, int $changeRequest): RedirectResponse
    {
        $data = $request->validate(['body' => 'required|string|max:10000']);

        return $this->send($changeRequest, 'post', "review/requests/{$changeRequest}/comment", $data + ['author_name' => $request->user()->name],
            'Comment added to the conversation.');
    }

    public function export(int $changeRequest): Response
    {
        $request = $this->fetch($changeRequest);

        return response($request->toMarkdown(), 200, [
            'Content-Type' => 'text/markdown; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$request->number.'.md"',
        ]);
    }

    private function fetch(int $id): ChangeRequest
    {
        return Payload::hydrate($this->upstream->call('get', "review/requests/{$id}")['request']);
    }

    /** @param array<string, mixed> $data */
    private function send(int $id, string $method, string $path, array $data, string $done): RedirectResponse
    {
        try {
            $this->upstream->call($method, $path, $data);
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.review-requests.show', $id)->with('success', $done);
    }
}
