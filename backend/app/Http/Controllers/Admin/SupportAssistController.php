<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SupportTicket;
use App\Services\RequestAssistant\RequestRefused;
use App\Services\SupportAssistant\SupportAssistService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The Q3-AI Assistant panel on a support ticket: ask it for a draft reply and
 * whether the ticket needs something not built yet, and hand that finding to
 * the Request Assistant as a change request. See memory-bank/request-assistant.md.
 */
class SupportAssistController extends Controller
{
    public function __construct(private SupportAssistService $service) {}

    public function ask(Request $request, SupportTicket $ticket): RedirectResponse
    {
        $data = $request->validate(['note' => 'nullable|string|max:5000']);
        $redirect = redirect()->to(route('admin.support.show', $ticket).'#q3-ai');

        try {
            $this->service->ask($request->user(), $ticket, $data['note'] ?? null);
        } catch (RequestRefused $e) {
            return $redirect->withInput()->with('error', $e->getMessage());
        }

        return $redirect;
    }

    // Polled by the ticket page while Q3-AI is writing.
    public function status(SupportTicket $ticket): JsonResponse
    {
        $assist = $ticket->assist;

        return response()->json([
            'busy' => (bool) $assist?->isBusy(),
            'messages' => $assist ? $assist->messages()->count() : 0,
        ]);
    }

    public function changeRequest(Request $request, SupportTicket $ticket): RedirectResponse
    {
        try {
            $changeRequest = $this->service->startChangeRequest($request->user(), $ticket);
        } catch (RequestRefused $e) {
            return redirect()->to(route('admin.support.show', $ticket).'#q3-ai')->with('error', $e->getMessage());
        }

        return redirect()->route('admin.change-requests.show', $changeRequest);
    }
}
