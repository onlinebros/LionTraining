<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\OwnerStatus\OwnerStatusReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The read-only owner-status API, read by the owner's assistant (see
 * memory-bank/owner-status-api.md). Token-guarded (RequireOwnerStatusToken).
 * GET only; nothing here writes or calls out.
 */
class OwnerStatusController extends Controller
{
    public function __construct(private OwnerStatusReport $report) {}

    public function summary(): JsonResponse
    {
        return response()->json($this->report->summary());
    }

    public function sales(Request $request): JsonResponse
    {
        $limit = is_numeric($request->query('limit')) ? max(1, min(500, (int) $request->query('limit'))) : 100;

        return response()->json($this->report->sales($this->days($request, 30, 365), $limit));
    }

    public function tickets(): JsonResponse
    {
        return response()->json($this->report->tickets());
    }

    public function ticket(int $ticket): JsonResponse
    {
        $data = $this->report->ticket($ticket);
        abort_if($data === null, 404);

        return response()->json($data);
    }

    public function errors(Request $request): JsonResponse
    {
        return response()->json($this->report->errors($this->days($request, 7, 90)));
    }

    public function approvals(): JsonResponse
    {
        return response()->json($this->report->approvals());
    }

    /** The ?days window, clamped to 1..$max; anything not a number is the default. */
    private function days(Request $request, int $default, int $max): int
    {
        $days = $request->query('days');

        return is_numeric($days) ? max(1, min($max, (int) $days)) : $default;
    }
}
