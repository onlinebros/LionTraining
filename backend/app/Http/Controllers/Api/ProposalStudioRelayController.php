<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StudioJob;
use App\Services\ProposalStudio\JobQueue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The live site's half of the Proposal Studio relay: dev's worker takes a
 * waiting job (a prompt), answers it on its own Claude CLI and posts the
 * answer back, which is applied here. Nothing but the prompt leaves.
 */
class ProposalStudioRelayController extends Controller
{
    public function __construct(private JobQueue $queue) {}

    public function claim(): JsonResponse
    {
        $claimed = $this->queue->claim();

        return response()->json(['job' => $claimed === null ? null : [
            'id' => $claimed['job']->id,
            'kind' => $claimed['job']->kind,
            'system' => $claimed['system'],
            'prompt' => $claimed['prompt'],
        ]]);
    }

    public function complete(Request $request, StudioJob $job): JsonResponse
    {
        $data = $request->validate(['text' => 'required|string', 'meta' => 'nullable|array']);
        abort_unless($job->status === StudioJob::CLAIMED, 409, 'This job is not waiting for an answer.');

        $this->queue->complete($job, $data['text'], $data['meta'] ?? []);

        return response()->json(['status' => $job->fresh()?->status]);
    }

    public function fail(Request $request, StudioJob $job): JsonResponse
    {
        $data = $request->validate(['error' => 'required|string|max:5000']);
        abort_unless($job->status === StudioJob::CLAIMED, 409, 'This job is not waiting for an answer.');

        $this->queue->fail($job, $data['error']);

        return response()->json(['status' => StudioJob::FAILED]);
    }
}
