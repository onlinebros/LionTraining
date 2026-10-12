<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ChangeRequest;
use App\Services\RequestAssistant\ChangeRequestService;
use App\Services\RequestAssistant\RequestRefused;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Change requests, on the server that keeps them (the live site). Super
 * admins only: they start requests, talk them through with the assistant and
 * submit the plan. Every super admin sees every request; only its author can
 * continue it. Decisions are shown here but made on dev (ReviewRequestController).
 */
class ChangeRequestController extends Controller
{
    public function __construct(private ChangeRequestService $service) {}

    public function index(Request $request)
    {
        $status = (string) $request->get('status', 'all');

        $requests = ChangeRequest::with('user')
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->latest('updated_at')
            ->paginate(25)
            ->withQueryString();

        $counts = ChangeRequest::selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        return view('admin.change-requests.index', compact('requests', 'status', 'counts'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'title' => 'required|string|max:200',
            'body' => 'required|string|max:10000',
            'source_url' => 'nullable|url|max:2048',
            'source_title' => 'nullable|string|max:255',
        ]);
        $source = filled($data['source_url'] ?? null) ? ['url' => $data['source_url'], 'title' => $data['source_title'] ?? null] : null;

        try {
            $changeRequest = $this->service->start($request->user(), $data['title'], $data['body'], $source);
        } catch (RequestRefused $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.change-requests.show', $changeRequest);
    }

    public function show(ChangeRequest $changeRequest)
    {
        $changeRequest->load('messages.user', 'user', 'reviewer');

        return view('admin.change-requests.show', ['changeRequest' => $changeRequest]);
    }

    public function message(Request $request, ChangeRequest $changeRequest): RedirectResponse
    {
        $data = $request->validate(['body' => 'required|string|max:10000']);

        return $this->act($changeRequest, fn () => $this->service->message($request->user(), $changeRequest, $data['body']), keepInput: true);
    }

    public function draftPlan(Request $request, ChangeRequest $changeRequest): RedirectResponse
    {
        return $this->act($changeRequest, fn () => $this->service->draftPlan($request->user(), $changeRequest));
    }

    public function submit(Request $request, ChangeRequest $changeRequest): RedirectResponse
    {
        return $this->act($changeRequest, fn () => $this->service->submit($request->user(), $changeRequest));
    }

    // Polled by the conversation page while the assistant is writing.
    public function status(ChangeRequest $changeRequest): JsonResponse
    {
        return response()->json([
            'busy' => $changeRequest->isAssistantBusy(),
            'messages' => $changeRequest->messages()->count(),
        ]);
    }

    private function act(ChangeRequest $changeRequest, \Closure $action, bool $keepInput = false): RedirectResponse
    {
        $redirect = redirect()->route('admin.change-requests.show', $changeRequest);

        try {
            $result = $action();
        } catch (RequestRefused $e) {
            return ($keepInput ? $redirect->withInput() : $redirect)->with('error', $e->getMessage());
        }

        return is_string($result) ? $redirect->with('success', $result) : $redirect;
    }
}
