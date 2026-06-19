<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CrmContact;
use App\Models\CrmTag;
use App\Models\User;
use App\Services\CrmService;
use Illuminate\Http\Request;

class CrmContactController extends Controller
{
    public function __construct(private CrmService $crm) {}

    public function index(Request $request)
    {
        $query = CrmContact::with(['owner', 'assignee', 'tags'])
            ->withCount(['notes', 'followups']);

        // Filters
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
        if ($request->filled('owner')) {
            $query->where('owner_id', $request->owner);
        }
        if ($request->filled('lead_source')) {
            $query->where('lead_source', $request->lead_source);
        }
        if ($request->filled('tag')) {
            $query->whereHas('tags', fn($q) => $q->where('crm_tags.id', $request->tag));
        }
        if ($request->filled('overdue')) {
            $query->overdue();
        }

        $contacts = $query->latest()->paginate(25)->withQueryString();

        $owners = User::orderBy('name')->get(['id', 'name']);
        $tags   = CrmTag::orderBy('name')->get();

        return view('admin.crm.contacts.index', compact('contacts', 'owners', 'tags'));
    }

    public function create()
    {
        $owners = User::orderBy('name')->get(['id', 'name']);
        $tags   = CrmTag::orderBy('name')->get();
        return view('admin.crm.contacts.create', compact('owners', 'tags'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'first_name'         => 'required|string|max:100',
            'last_name'          => 'nullable|string|max:100',
            'email'              => 'nullable|email|max:255',
            'phone'              => 'nullable|string|max:30',
            'company'            => 'nullable|string|max:150',
            'website'            => 'nullable|url|max:255',
            'contact_type'       => 'required|in:' . implode(',', array_keys(CrmContact::$contactTypes)),
            'status'             => 'required|in:' . implode(',', array_keys(CrmContact::$statuses)),
            'lead_source'        => 'nullable|in:' . implode(',', array_keys(CrmContact::$leadSources)),
            'owner_id'           => 'required|exists:users,id',
            'assigned_to'        => 'nullable|exists:users,id',
            'address_line1'      => 'nullable|string|max:255',
            'address_line2'      => 'nullable|string|max:255',
            'city'               => 'nullable|string|max:100',
            'state'              => 'nullable|string|max:100',
            'postal_code'        => 'nullable|string|max:20',
            'country'            => 'nullable|string|max:80',
            'quick_note'         => 'nullable|string|max:2000',
            'tags'               => 'nullable|array',
            'tags.*'             => 'exists:crm_tags,id',
            'next_followup_at'   => 'nullable|date',
        ]);

        $contact = $this->crm->createContact($data, $data['owner_id']);

        return redirect()->route('admin.crm.contacts.show', $contact)
            ->with('success', "Contact {$contact->full_name} created.");
    }

    public function show(CrmContact $crmContact)
    {
        $crmContact->load([
            'owner', 'assignee', 'createdBy', 'linkedUser', 'referredBy',
            'tags', 'notes.user', 'followups.assignee', 'activities.user',
        ]);

        $tags  = CrmTag::orderBy('name')->get();
        $users = User::orderBy('name')->get(['id', 'name']);

        return view('admin.crm.contacts.show', compact('crmContact', 'tags', 'users'));
    }

    public function edit(CrmContact $crmContact)
    {
        $owners = User::orderBy('name')->get(['id', 'name']);
        $tags   = CrmTag::orderBy('name')->get();
        $selectedTags = $crmContact->tags->pluck('id')->toArray();

        return view('admin.crm.contacts.edit', compact('crmContact', 'owners', 'tags', 'selectedTags'));
    }

    public function update(Request $request, CrmContact $crmContact)
    {
        $data = $request->validate([
            'first_name'         => 'required|string|max:100',
            'last_name'          => 'nullable|string|max:100',
            'email'              => 'nullable|email|max:255',
            'phone'              => 'nullable|string|max:30',
            'company'            => 'nullable|string|max:150',
            'website'            => 'nullable|url|max:255',
            'contact_type'       => 'required|in:' . implode(',', array_keys(CrmContact::$contactTypes)),
            'status'             => 'required|in:' . implode(',', array_keys(CrmContact::$statuses)),
            'lead_source'        => 'nullable|in:' . implode(',', array_keys(CrmContact::$leadSources)),
            'owner_id'           => 'required|exists:users,id',
            'assigned_to'        => 'nullable|exists:users,id',
            'address_line1'      => 'nullable|string|max:255',
            'address_line2'      => 'nullable|string|max:255',
            'city'               => 'nullable|string|max:100',
            'state'              => 'nullable|string|max:100',
            'postal_code'        => 'nullable|string|max:20',
            'country'            => 'nullable|string|max:80',
            'quick_note'         => 'nullable|string|max:2000',
            'tags'               => 'nullable|array',
            'tags.*'             => 'exists:crm_tags,id',
            'next_followup_at'   => 'nullable|date',
        ]);

        $this->crm->updateContact($crmContact, $data);

        return redirect()->route('admin.crm.contacts.show', $crmContact)
            ->with('success', 'Contact updated.');
    }

    public function destroy(CrmContact $crmContact)
    {
        $name = $crmContact->full_name;
        $crmContact->delete();

        return redirect()->route('admin.crm.contacts.index')
            ->with('success', "{$name} has been deleted.");
    }
}
