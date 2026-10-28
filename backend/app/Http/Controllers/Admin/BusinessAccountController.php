<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BusinessAccount;
use App\Models\BusinessActivity;
use App\Models\BusinessContact;
use App\Models\Offering;
use App\Models\Role;
use App\Models\User;
use App\Services\ProposalStudio\AccountService;
use App\Services\ProposalStudio\StudioRefused;
use App\Services\ProposalStudio\StudioService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Proposal Studio → Accounts: the companies we are building B2B
 * relationships with, their people, the timeline, and their opportunities.
 * Super admins only (D2), like the rest of the studio. See
 * memory-bank/b2b-accounts.md.
 */
class BusinessAccountController extends Controller
{
    public function __construct(private AccountService $accounts) {}

    public function index(Request $request)
    {
        $stage = (string) $request->get('stage', 'all');
        $search = trim((string) $request->get('q', ''));

        $accounts = BusinessAccount::with('owner')
            ->withCount(['opportunities', 'members' => fn ($q) => $q->where('status', 'invited')->whereNull('revoked_at')])
            ->withCount(['activities as open_tasks_count' => fn ($q) => $q->where('type', 'task')->whereNull('done_at')])
            ->when($stage !== 'all', fn ($q) => $q->where('stage', $stage))
            ->when($search !== '', fn ($q) => $q->where('name', 'like', '%'.addcslashes($search, '%_\\').'%'))
            ->latest('updated_at')
            ->paginate(25)
            ->withQueryString();

        $counts = BusinessAccount::selectRaw('stage, count(*) as n')->groupBy('stage')->pluck('n', 'stage');
        $tasks = BusinessActivity::with('account')->where('type', 'task')->whereNull('done_at')
            ->orderByRaw('due_on is null')->orderBy('due_on')->limit(10)->get();

        return view('admin.proposal-studio.accounts.index', compact('accounts', 'stage', 'search', 'counts', 'tasks'));
    }

    public function create()
    {
        return view('admin.proposal-studio.accounts.create', ['admins' => $this->admins()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate($this->rules() + [
            'contact.name' => 'nullable|string|max:255',
            'contact.title' => 'nullable|string|max:255',
            'contact.email' => 'nullable|email|max:255',
            'contact.phone' => 'nullable|string|max:40',
            'contact.role' => ['nullable', Rule::in(array_keys(BusinessContact::ROLES))],
        ]);
        $contact = $data['contact'] ?? null;
        unset($data['contact']);
        if ($contact) {
            $contact['role'] = $contact['role'] ?? 'other';
        }

        $account = $this->accounts->create($request->user(), $data + ['owner_user_id' => $request->user()->id], $contact);

        return redirect()->route('admin.proposal-studio.accounts.show', $account)->with('success', 'Account created.');
    }

    public function show(BusinessAccount $account)
    {
        $account->load('owner', 'contacts', 'opportunities.offering', 'opportunities.publishedProposal');

        return view('admin.proposal-studio.accounts.show', [
            'account' => $account,
            'activities' => $account->activities()->with('user')->limit(60)->get(),
            'openTasks' => $account->activities()->where('type', 'task')->whereNull('done_at')->orderByRaw('due_on is null')->orderBy('due_on')->get(),
            'offerings' => Offering::where('is_active', true)->orderBy('name')->get(),
            'admins' => $this->admins(),
            'site' => [
                'published' => $account->sections()->where('status', 'published')->count(),
                'sections' => $account->sections()->count(),
                'members' => $account->members()->where('status', 'invited')->whereNull('revoked_at')->count(),
                'requests' => $account->members()->where('status', 'requested')->whereNull('revoked_at')->count(),
            ],
        ]);
    }

    public function update(Request $request, BusinessAccount $account): RedirectResponse
    {
        $data = $request->validate($this->rules() + [
            'stage' => ['required', Rule::in(array_keys(BusinessAccount::STAGES))],
        ]);
        $this->accounts->update($account, $data, $request->user());

        return back()->with('success', 'Saved.');
    }

    public function storeContact(Request $request, BusinessAccount $account): RedirectResponse
    {
        $this->accounts->saveContact($account, $this->contactData($request));

        return back()->with('success', 'Contact added.');
    }

    public function updateContact(Request $request, BusinessAccount $account, BusinessContact $contact): RedirectResponse
    {
        abort_unless($contact->business_account_id === $account->id, 404);
        $this->accounts->saveContact($account, $this->contactData($request), $contact);

        return back()->with('success', 'Contact saved.');
    }

    public function destroyContact(BusinessAccount $account, BusinessContact $contact): RedirectResponse
    {
        abort_unless($contact->business_account_id === $account->id, 404);
        $contact->delete();

        return back()->with('success', "{$contact->name} removed.");
    }

    public function storeActivity(Request $request, BusinessAccount $account): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(array_keys(BusinessActivity::TYPES))],
            'body' => 'required|string|max:10000',
            'due_on' => 'nullable|date',
        ]);
        $this->accounts->addActivity($account, $request->user(), $data['type'], $data['body'], $data['due_on'] ?? null);
        $account->touch();

        return back()->with('success', $data['type'] === 'task' ? 'Task added.' : 'Added to the timeline.');
    }

    public function completeTask(Request $request, BusinessAccount $account, BusinessActivity $activity): RedirectResponse
    {
        abort_unless($activity->business_account_id === $account->id, 404);
        $this->accounts->completeTask($activity, $request->user());

        return back()->with('success', 'Task done.');
    }

    /** A new opportunity: a Proposal Studio prospect for one offering, carrying the account's details. */
    public function storeOpportunity(Request $request, BusinessAccount $account, StudioService $studio): RedirectResponse
    {
        $data = $request->validate([
            'offering_id' => 'required|exists:offerings,id',
            'intake' => 'required|string|max:20000',
        ]);
        $primary = $account->contacts()->first();

        try {
            $prospect = $studio->start($request->user(), [
                'offering_id' => $data['offering_id'],
                'business_account_id' => $account->id,
                'company_name' => $account->name,
                'website' => $account->website,
                'industry' => $account->industry,
                'contact_name' => $primary?->name,
                'contact_email' => $primary?->email,
                'contact_phone' => $primary?->phone,
            ], $data['intake']);
        } catch (StudioRefused $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
        $account->log('system', "Opportunity started for {$prospect->offering->name}.", $request->user(), ['prospect' => $prospect->id]);
        if ($account->stage === 'target') {
            $this->accounts->update($account, ['stage' => 'engaged'], $request->user());
        }

        return redirect()->route('admin.proposal-studio.prospects.show', $prospect);
    }

    public function logo(BusinessAccount $account): StreamedResponse
    {
        abort_unless($account->logo_path, 404);

        return Storage::disk('local')->response($account->logo_path);
    }

    /** @return array<string, mixed> */
    private function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'website' => 'nullable|url|max:255',
            'industry' => 'nullable|string|max:255',
            'size' => ['nullable', Rule::in(BusinessAccount::SIZES)],
            'headquarters' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:20000',
            'next_step' => 'nullable|string|max:255',
            'next_step_due' => 'nullable|date',
            'owner_user_id' => ['nullable', Rule::in($this->admins()->pluck('id')->all())],
        ];
    }

    /** @return array<string, mixed> */
    private function contactData(Request $request): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'title' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:40',
            'role' => ['required', Rule::in(array_keys(BusinessContact::ROLES))],
            'notes' => 'nullable|string|max:5000',
        ]);

        return $data + ['is_primary' => $request->boolean('is_primary')];
    }

    /** Who can own an account: the super admins, who are the studio's users (D2). */
    private function admins()
    {
        return User::whereHas('role', fn ($q) => $q->where('name', Role::SUPER_ADMIN))->orderBy('name')->get(['id', 'name']);
    }
}
