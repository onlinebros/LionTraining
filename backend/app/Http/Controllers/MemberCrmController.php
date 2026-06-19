<?php

namespace App\Http\Controllers;

use App\Models\CrmContact;
use App\Models\CrmFollowup;
use App\Models\CrmNote;
use App\Models\CrmTag;
use App\Services\CrmService;
use Illuminate\Http\Request;

class MemberCrmController extends Controller
{
    public function __construct(private CrmService $crm) {}

    // ── Dashboard ─────────────────────────────────────────────────────────────

    public function dashboard()
    {
        $user  = auth()->user();
        $stats = $this->crm->dashboardStats($user->id);

        $followupsToday = CrmFollowup::with('contact')
            ->forUser($user->id)
            ->dueToday()
            ->orderBy('due_at')
            ->limit(8)
            ->get();

        $overdueFollowups = CrmFollowup::with('contact')
            ->forUser($user->id)
            ->overdue()
            ->orderBy('due_at')
            ->limit(8)
            ->get();

        $recentContacts = CrmContact::with('tags')
            ->ownedBy($user->id)
            ->latest()
            ->limit(6)
            ->get();

        $byStatus = CrmContact::selectRaw('status, count(*) as total')
            ->where('owner_id', $user->id)
            ->groupBy('status')
            ->pluck('total', 'status');

        return view('member.crm.dashboard', compact(
            'stats', 'followupsToday', 'overdueFollowups', 'recentContacts', 'byStatus'
        ));
    }

    // ── Contacts ──────────────────────────────────────────────────────────────

    public function index(Request $request)
    {
        $user  = auth()->user();
        $query = CrmContact::with(['tags', 'assignee'])
            ->withCount(['notes', 'followups'])
            ->ownedBy($user->id);

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('first_name', 'like', "%{$s}%")
                  ->orWhere('last_name', 'like', "%{$s}%")
                  ->orWhere('email', 'like', "%{$s}%")
                  ->orWhere('phone', 'like', "%{$s}%")
                  ->orWhere('company', 'like', "%{$s}%");
            });
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('contact_type')) {
            $query->where('contact_type', $request->contact_type);
        }
        if ($request->filled('tag')) {
            $query->whereHas('tags', fn($q) => $q->where('crm_tags.id', $request->tag));
        }
        if ($request->filled('overdue')) {
            $query->overdue();
        }

        $contacts = $query->latest()->paginate(20)->withQueryString();
        $tags     = CrmTag::visibleTo($user->id)->orderBy('name')->get();

        return view('member.crm.contacts.index', compact('contacts', 'tags'));
    }

    public function create()
    {
        $tags = CrmTag::visibleTo(auth()->id())->orderBy('name')->get();
        return view('member.crm.contacts.create', compact('tags'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'first_name'       => 'required|string|max:100',
            'last_name'        => 'nullable|string|max:100',
            'email'            => 'nullable|email|max:255',
            'phone'            => 'nullable|string|max:30',
            'company'          => 'nullable|string|max:150',
            'contact_type'     => 'required|in:' . implode(',', array_keys(CrmContact::$contactTypes)),
            'status'           => 'required|in:' . implode(',', array_keys(CrmContact::$statuses)),
            'lead_source'      => 'nullable|in:' . implode(',', array_keys(CrmContact::$leadSources)),
            'address_line1'    => 'nullable|string|max:255',
            'city'             => 'nullable|string|max:100',
            'state'            => 'nullable|string|max:100',
            'country'          => 'nullable|string|max:80',
            'quick_note'       => 'nullable|string|max:2000',
            'tags'             => 'nullable|array',
            'tags.*'           => 'exists:crm_tags,id',
            'next_followup_at' => 'nullable|date',
        ]);

        $contact = $this->crm->createContact($data, auth()->id());

        return redirect()->route('member.crm.contacts.show', $contact)
            ->with('success', "Contact {$contact->full_name} added to your CRM.");
    }

    public function show(CrmContact $contact)
    {
        // Members can only see their own contacts
        abort_unless($contact->owner_id === auth()->id(), 403);

        $contact->load([
            'tags', 'notes.user', 'followups.assignee', 'activities.user',
        ]);

        $tags = CrmTag::visibleTo(auth()->id())->orderBy('name')->get();

        return view('member.crm.contacts.show', compact('contact', 'tags'));
    }

    public function edit(CrmContact $contact)
    {
        abort_unless($contact->owner_id === auth()->id(), 403);

        $tags         = CrmTag::visibleTo(auth()->id())->orderBy('name')->get();
        $selectedTags = $contact->tags->pluck('id')->toArray();

        return view('member.crm.contacts.edit', compact('contact', 'tags', 'selectedTags'));
    }

    public function update(Request $request, CrmContact $contact)
    {
        abort_unless($contact->owner_id === auth()->id(), 403);

        $data = $request->validate([
            'first_name'       => 'required|string|max:100',
            'last_name'        => 'nullable|string|max:100',
            'email'            => 'nullable|email|max:255',
            'phone'            => 'nullable|string|max:30',
            'company'          => 'nullable|string|max:150',
            'contact_type'     => 'required|in:' . implode(',', array_keys(CrmContact::$contactTypes)),
            'status'           => 'required|in:' . implode(',', array_keys(CrmContact::$statuses)),
            'lead_source'      => 'nullable|in:' . implode(',', array_keys(CrmContact::$leadSources)),
            'address_line1'    => 'nullable|string|max:255',
            'city'             => 'nullable|string|max:100',
            'state'            => 'nullable|string|max:100',
            'country'          => 'nullable|string|max:80',
            'quick_note'       => 'nullable|string|max:2000',
            'tags'             => 'nullable|array',
            'tags.*'           => 'exists:crm_tags,id',
            'next_followup_at' => 'nullable|date',
        ]);

        $this->crm->updateContact($contact, $data);

        return redirect()->route('member.crm.contacts.show', $contact)
            ->with('success', 'Contact updated.');
    }

    public function destroy(CrmContact $contact)
    {
        abort_unless($contact->owner_id === auth()->id(), 403);

        $name = $contact->full_name;
        $contact->delete();

        return redirect()->route('member.crm.contacts.index')
            ->with('success', "{$name} deleted.");
    }

    // ── Notes ─────────────────────────────────────────────────────────────────

    public function storeNote(Request $request, CrmContact $contact)
    {
        abort_unless($contact->owner_id === auth()->id(), 403);

        $data = $request->validate([
            'type'        => 'required|in:' . implode(',', array_keys(CrmNote::$types)),
            'title'       => 'nullable|string|max:200',
            'body'        => 'required|string|max:5000',
            'occurred_at' => 'nullable|date',
        ]);

        $this->crm->addNote($contact, $data);

        return back()->with('success', 'Note logged.');
    }

    public function destroyNote(CrmContact $contact, CrmNote $note)
    {
        abort_unless($contact->owner_id === auth()->id(), 403);
        abort_unless($note->contact_id === $contact->id, 404);

        $note->delete();
        return back()->with('success', 'Note deleted.');
    }

    // ── Follow-ups ────────────────────────────────────────────────────────────

    public function storeFollowup(Request $request, CrmContact $contact)
    {
        abort_unless($contact->owner_id === auth()->id(), 403);

        $data = $request->validate([
            'title'       => 'required|string|max:200',
            'description' => 'nullable|string|max:2000',
            'type'        => 'required|in:' . implode(',', array_keys(CrmFollowup::$types)),
            'priority'    => 'required|in:' . implode(',', array_keys(CrmFollowup::$priorities)),
            'due_at'      => 'required|date',
        ]);

        $data['assigned_to'] = auth()->id();
        $this->crm->createFollowup($contact, $data);

        return back()->with('success', 'Follow-up scheduled.');
    }

    public function completeFollowup(Request $request, CrmFollowup $followup)
    {
        abort_unless($followup->assigned_to === auth()->id() || $followup->contact->owner_id === auth()->id(), 403);

        $data = $request->validate([
            'completion_note' => 'nullable|string|max:2000',
        ]);

        $this->crm->completeFollowup($followup, $data['completion_note'] ?? null);

        return back()->with('success', 'Follow-up marked complete.');
    }

    public function destroyFollowup(CrmFollowup $followup)
    {
        abort_unless($followup->contact->owner_id === auth()->id(), 403);
        $followup->update(['status' => 'cancelled']);

        return back()->with('success', 'Follow-up cancelled.');
    }
}
