<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CrmContact;
use App\Models\CrmFollowup;
use App\Models\CrmNote;
use App\Services\CrmService;
use Illuminate\Http\Request;

class CrmActivityController extends Controller
{
    public function __construct(private CrmService $crm) {}

    // ── Notes ─────────────────────────────────────────────────────────────────

    public function storeNote(Request $request, CrmContact $crmContact)
    {
        $data = $request->validate([
            'type'        => 'required|in:' . implode(',', array_keys(CrmNote::$types)),
            'title'       => 'nullable|string|max:200',
            'body'        => 'required|string|max:5000',
            'occurred_at' => 'nullable|date',
        ]);

        $this->crm->addNote($crmContact, $data);

        return back()->with('success', 'Note logged.');
    }

    public function destroyNote(CrmContact $crmContact, CrmNote $note)
    {
        abort_unless($note->contact_id === $crmContact->id, 404);
        $note->delete();

        return back()->with('success', 'Note deleted.');
    }

    // ── Follow-ups ────────────────────────────────────────────────────────────

    public function storeFollowup(Request $request, CrmContact $crmContact)
    {
        $data = $request->validate([
            'title'       => 'required|string|max:200',
            'description' => 'nullable|string|max:2000',
            'type'        => 'required|in:' . implode(',', array_keys(CrmFollowup::$types)),
            'priority'    => 'required|in:' . implode(',', array_keys(CrmFollowup::$priorities)),
            'due_at'      => 'required|date',
            'assigned_to' => 'nullable|exists:users,id',
        ]);

        $this->crm->createFollowup($crmContact, $data);

        return back()->with('success', 'Follow-up scheduled.');
    }

    public function completeFollowup(Request $request, CrmFollowup $followup)
    {
        $data = $request->validate([
            'completion_note' => 'nullable|string|max:2000',
        ]);

        $this->crm->completeFollowup($followup, $data['completion_note'] ?? null);

        return back()->with('success', 'Follow-up marked complete.');
    }

    public function snoozeFollowup(Request $request, CrmFollowup $followup)
    {
        $data = $request->validate([
            'snoozed_until' => 'required|date|after:now',
        ]);

        $this->crm->snoozeFollowup($followup, $data['snoozed_until']);

        return back()->with('success', 'Follow-up snoozed.');
    }

    public function destroyFollowup(CrmFollowup $followup)
    {
        $followup->update(['status' => 'cancelled']);
        return back()->with('success', 'Follow-up cancelled.');
    }
}
