<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChangeRequest;
use App\Models\ChangeRequestMessage;
use App\Services\RequestAssistant\Payload;
use App\Services\RequestAssistant\TurnService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The API dev calls on the server that keeps the change requests. Token-
 * guarded (RequireRequestAssistantToken). Two jobs:
 *
 *  - relay: dev collects a waiting turn, runs it on its own Claude CLI, and
 *    sends back the answer or the failure.
 *  - review: dev's review screens read every request and record decisions
 *    and comments. Decisions are made only there.
 */
class RequestAssistantController extends Controller
{
    public function __construct(private TurnService $turns) {}

    // ── Relay ─────────────────────────────────────────────────────────────

    public function claim(Request $request): JsonResponse
    {
        $data = $request->validate(['commit' => ['required', 'regex:/^[0-9a-f]{40}$/']]);

        return response()->json(['turn' => $this->turns->claim($data['commit'])]);
    }

    public function complete(Request $request, ChangeRequest $changeRequest): JsonResponse
    {
        $data = $request->validate([
            'mode' => 'required|in:'.TurnService::MODE_REPLY.','.TurnService::MODE_PLAN,
            'text' => 'required|string|max:200000',
            'session_id' => 'nullable|uuid',
            'meta' => 'nullable|array',
            'commit' => ['required', 'regex:/^[0-9a-f]{40}$/'],
        ]);

        // Answered already, or the requester has moved on: drop it.
        if ($changeRequest->assistant_pending !== $data['mode'] || $changeRequest->assistant_claimed_at === null) {
            return response()->json(['message' => 'That turn is no longer waiting.'], 409);
        }

        $this->turns->complete($changeRequest, $data['mode'], [
            'text' => $data['text'],
            'session_id' => $data['session_id'] ?? null,
            'meta' => $data['meta'] ?? [],
        ], $data['commit']);

        return response()->json(['ok' => true]);
    }

    public function fail(Request $request, ChangeRequest $changeRequest): JsonResponse
    {
        $data = $request->validate(['error' => 'required|string|max:5000']);

        if ($changeRequest->assistant_pending === null) {
            return response()->json(['message' => 'That turn is no longer waiting.'], 409);
        }

        $this->turns->fail($changeRequest, $data['error']);

        return response()->json(['ok' => true]);
    }

    // ── Review ────────────────────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $status = (string) $request->query('status', 'submitted');

        $requests = ChangeRequest::with('user')
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->latest('updated_at')
            ->limit(200)
            ->get();

        return response()->json([
            'requests' => $requests->map(fn ($r) => Payload::summary($r))->values(),
            'counts' => ChangeRequest::selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status'),
        ]);
    }

    public function show(ChangeRequest $changeRequest): JsonResponse
    {
        return response()->json(['request' => Payload::full($changeRequest->load('user', 'reviewer', 'messages.user'))]);
    }

    public function review(Request $request, ChangeRequest $changeRequest): JsonResponse
    {
        $data = $request->validate([
            'status' => 'required|in:'.implode(',', ChangeRequest::REVIEW_STATUSES),
            'review_notes' => 'nullable|string|max:10000',
            'reviewer_name' => 'required|string|max:255',
        ]);

        $changeRequest->forceFill([
            'status' => $data['status'],
            'review_notes' => $data['review_notes'] ?? null,
            'reviewer_name' => $data['reviewer_name'],
            'reviewed_by' => null,
            'reviewed_at' => now(),
        ])->save();

        return response()->json(['ok' => true]);
    }

    public function comment(Request $request, ChangeRequest $changeRequest): JsonResponse
    {
        $data = $request->validate([
            'body' => 'required|string|max:10000',
            'author_name' => 'required|string|max:255',
        ]);

        $changeRequest->messages()->create([
            'role' => ChangeRequestMessage::ROLE_REVIEWER,
            'author_name' => $data['author_name'],
            'body' => $data['body'],
        ]);
        $changeRequest->touch();

        return response()->json(['ok' => true]);
    }
}
