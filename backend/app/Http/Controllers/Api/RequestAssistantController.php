<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChangeRequest;
use App\Models\ChangeRequestMessage;
use App\Models\SupportAssist;
use App\Services\RequestAssistant\Payload;
use App\Services\RequestAssistant\TurnService;
use App\Services\SupportAssistant\TurnService as SupportTurnService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The API dev calls on the server that keeps the change requests. Token-
 * guarded (RequireRequestAssistantToken). Two jobs:
 *
 *  - relay: dev collects a waiting turn, runs it on its own Claude CLI, and
 *    sends back the answer or the failure. Turns are change requests, and
 *    Q3-AI questions on support tickets for a relay that asks for them.
 *  - review: dev's review screens read every request and record decisions
 *    and comments. Decisions are made only there.
 */
class RequestAssistantController extends Controller
{
    public function __construct(private TurnService $turns, private SupportTurnService $supportTurns) {}

    // ── Relay ─────────────────────────────────────────────────────────────

    public function claim(Request $request): JsonResponse
    {
        $data = $request->validate([
            'commit' => ['required', 'regex:/^[0-9a-f]{40}$/'],
            'kinds' => 'nullable|array',
            'kinds.*' => 'string',
        ]);

        $turn = $this->turns->claim($data['commit']);
        // Only a relay that says it answers support turns gets one: an older
        // relay would post the answer to a change request with the same id.
        if ($turn === null && in_array(SupportTurnService::KIND, $data['kinds'] ?? [], true)) {
            $turn = $this->supportTurns->claim($data['commit']);
        }

        return response()->json(['turn' => $turn]);
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

    public function completeSupport(Request $request, SupportAssist $supportAssist): JsonResponse
    {
        $data = $request->validate([
            'mode' => 'required|in:'.SupportTurnService::MODE_REPLY,
            'text' => 'required|string|max:200000',
            'session_id' => 'nullable|uuid',
            'meta' => 'nullable|array',
            'commit' => ['required', 'regex:/^[0-9a-f]{40}$/'],
        ]);

        if ($supportAssist->pending !== $data['mode'] || $supportAssist->claimed_at === null) {
            return response()->json(['message' => 'That turn is no longer waiting.'], 409);
        }

        $this->supportTurns->complete($supportAssist, [
            'text' => $data['text'],
            'session_id' => $data['session_id'] ?? null,
            'meta' => $data['meta'] ?? [],
        ], $data['commit']);

        return response()->json(['ok' => true]);
    }

    public function failSupport(Request $request, SupportAssist $supportAssist): JsonResponse
    {
        $data = $request->validate(['error' => 'required|string|max:5000']);

        if ($supportAssist->pending === null) {
            return response()->json(['message' => 'That turn is no longer waiting.'], 409);
        }

        $this->supportTurns->fail($supportAssist, $data['error']);

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
