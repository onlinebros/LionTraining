<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Offering;
use App\Models\Prospect;
use App\Services\ProposalStudio\AccountService;
use App\Services\ProposalStudio\PortalAssistant;
use App\Services\ProposalStudio\StudioRefused;
use App\Services\ProposalStudio\StudioService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Proposal Studio → Prospects: a workspace per prospect company, where an
 * admin talks the prospect through with the assistant and gets a plan and
 * emails back. Super admins only (owner, decision D2, 2026-10-20). Every
 * super admin can see and continue every prospect.
 */
class ProspectController extends Controller
{
    public function __construct(private StudioService $studio) {}

    public function index(Request $request)
    {
        $status = (string) $request->get('status', 'all');

        $prospects = Prospect::with('offering', 'creator')
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->latest('updated_at')
            ->paginate(25)
            ->withQueryString();

        $counts = Prospect::selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        return view('admin.proposal-studio.prospects.index', compact('prospects', 'status', 'counts'));
    }

    public function create()
    {
        $offerings = Offering::where('is_active', true)->orderBy('name')->get();

        return view('admin.proposal-studio.prospects.create', compact('offerings'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate($this->rules() + [
            'intake' => 'required|string|max:20000',
        ]);
        $intake = $data['intake'];
        unset($data['intake']);

        try {
            $prospect = $this->studio->start($request->user(), $data, $intake);
        } catch (StudioRefused $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.proposal-studio.prospects.show', $prospect);
    }

    public function show(Prospect $prospect)
    {
        $prospect->load('account', 'messages.user', 'offering', 'creator', 'proposals.vendorReviewer', 'invites', 'submissions.invite', 'publishedProposal');

        return view('admin.proposal-studio.prospects.show', [
            'prospect' => $prospect,
            'offerings' => Offering::orderBy('name')->get(),
            'job' => $prospect->openJob(),
            'events' => $prospect->events()->with('invite')->limit(25)->get(),
            'portalAssistant' => PortalAssistant::available(),
        ]);
    }

    public function update(Request $request, Prospect $prospect): RedirectResponse
    {
        $data = $request->validate($this->rules() + [
            'status' => ['required', Rule::in(array_keys(Prospect::STATUSES))],
            'outcome_note' => 'nullable|string|max:5000',
        ]);
        $wasClosed = in_array($prospect->status, ['won', 'lost'], true);
        $prospect->update($data);
        if (! $wasClosed) {
            app(AccountService::class)->opportunityClosed($prospect, $request->user());
        }

        return back()->with('success', 'Saved.');
    }

    public function message(Request $request, Prospect $prospect): RedirectResponse
    {
        $data = $request->validate(['body' => 'required|string|max:20000']);

        return $this->act($prospect, fn () => $this->studio->message($request->user(), $prospect, $data['body']), keepInput: true);
    }

    public function draft(Request $request, Prospect $prospect): RedirectResponse
    {
        return $this->act($prospect, fn () => $this->studio->draft($request->user(), $prospect));
    }

    // Polled by the workspace page while the assistant is writing.
    public function status(Prospect $prospect): JsonResponse
    {
        return response()->json([
            'busy' => $prospect->isAssistantBusy(),
            'messages' => $prospect->messages()->count(),
        ]);
    }

    /** @return array<string, mixed> */
    private function rules(): array
    {
        return [
            'offering_id' => 'required|exists:offerings,id',
            'company_name' => 'required|string|max:255',
            'website' => 'nullable|url|max:255',
            'industry' => 'nullable|string|max:255',
            'contact_name' => 'nullable|string|max:255',
            'contact_email' => 'nullable|email|max:255',
            'contact_phone' => 'nullable|string|max:40',
        ];
    }

    private function act(Prospect $prospect, \Closure $action, bool $keepInput = false): RedirectResponse
    {
        $redirect = redirect()->route('admin.proposal-studio.prospects.show', $prospect);

        try {
            $action();
        } catch (StudioRefused $e) {
            return ($keepInput ? $redirect->withInput() : $redirect)->with('error', $e->getMessage());
        }

        return $redirect;
    }
}
