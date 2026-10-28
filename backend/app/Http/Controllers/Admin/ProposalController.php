<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PortalInvite;
use App\Models\PortalSubmission;
use App\Models\Proposal;
use App\Models\Prospect;
use App\Services\ProposalStudio\PortalService;
use App\Services\ProposalStudio\ProposalService;
use App\Services\ProposalStudio\StudioRefused;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Proposal Studio → a prospect's proposal page and portal: drafting and
 * editing versions, publishing (through the vendor's review while it
 * applies), inviting people to the portal, and answering their questions.
 */
class ProposalController extends Controller
{
    public function __construct(private ProposalService $proposals, private PortalService $portal) {}

    public function draft(Request $request, Prospect $prospect): RedirectResponse
    {
        return $this->act($prospect, fn () => $this->proposals->draft($prospect, $request->user()));
    }

    public function edit(Prospect $prospect, Proposal $proposal)
    {
        $this->belongs($prospect, $proposal);
        $claims = $prospect->offering->approvedClaims()->with('document')->get();

        return view('admin.proposal-studio.proposals.edit', compact('prospect', 'proposal', 'claims'));
    }

    public function update(Request $request, Prospect $prospect, Proposal $proposal): RedirectResponse
    {
        $this->belongs($prospect, $proposal);
        $data = $request->validate([
            'headline' => 'nullable|string|max:300',
            'intro' => 'nullable|string|max:5000',
            'situation' => 'nullable|string|max:10000',
            'plan' => 'nullable|array|max:30',
            'plan.*.site' => 'nullable|string|max:255',
            'plan.*.product' => 'nullable|string|max:255',
            'plan.*.quantity' => 'nullable|string|max:50',
            'plan.*.rationale' => 'nullable|string|max:3000',
            'how_it_works' => 'nullable|string|max:10000',
            'benefits' => 'nullable|array|max:12',
            'benefits.*.title' => 'nullable|string|max:255',
            'benefits.*.text' => 'nullable|string|max:3000',
            'claim_ids' => 'nullable|array',
            'claim_ids.*' => 'integer',
            'pricing' => 'nullable|string|max:5000',
            'next_steps' => 'nullable|string|max:5000',
        ]);
        // Rows left empty in the form are dropped.
        $data['plan'] = array_values(array_filter($data['plan'] ?? [], fn ($row) => filled($row['site'] ?? null) || filled($row['product'] ?? null)));
        $data['benefits'] = array_values(array_filter($data['benefits'] ?? [], fn ($row) => filled($row['title'] ?? null) || filled($row['text'] ?? null)));

        try {
            $this->proposals->update($proposal, $data);
        } catch (StudioRefused $e) {
            return back()->with('error', $e->getMessage());
        }

        return $request->boolean('then_publish')
            ? $this->publish($request, $prospect, $proposal)
            : back()->with('success', 'Saved.');
    }

    public function preview(Prospect $prospect, Proposal $proposal)
    {
        $this->belongs($prospect, $proposal);

        return view('portal.show', $this->portalView($prospect, $proposal) + ['preview' => true]);
    }

    public function publish(Request $request, Prospect $prospect, Proposal $proposal): RedirectResponse
    {
        $this->belongs($prospect, $proposal);

        try {
            $message = $this->proposals->publish($proposal, $request->user());
        } catch (StudioRefused $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.proposal-studio.prospects.show', $prospect)->with('success', $message);
    }

    public function newVersion(Request $request, Prospect $prospect, Proposal $proposal): RedirectResponse
    {
        $this->belongs($prospect, $proposal);
        $draft = $this->proposals->newVersion($proposal, $request->user());

        return redirect()->route('admin.proposal-studio.proposals.edit', [$prospect, $draft]);
    }

    public function invite(Request $request, Prospect $prospect): RedirectResponse
    {
        $data = $request->validate(['email' => 'required|email|max:255', 'name' => 'nullable|string|max:255']);

        try {
            $invite = $this->portal->invite($prospect, $data['email'], $data['name'] ?? null, $request->user());
        } catch (StudioRefused $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('success', "Sent {$invite->email} a sign-in link to the portal.");
    }

    public function resend(Prospect $prospect, PortalInvite $invite): RedirectResponse
    {
        abort_unless($invite->prospect_id === $prospect->id && $invite->isActive(), 404);
        $this->portal->sendLink($invite);

        return back()->with('success', "Sent {$invite->email} a new link.");
    }

    public function revoke(Prospect $prospect, PortalInvite $invite): RedirectResponse
    {
        abort_unless($invite->prospect_id === $prospect->id, 404);
        $this->portal->revoke($invite);

        return back()->with('success', "{$invite->email} can no longer open the portal.");
    }

    public function answer(Request $request, Prospect $prospect, PortalSubmission $submission): RedirectResponse
    {
        abort_unless($submission->prospect_id === $prospect->id && $submission->kind === 'question', 404);
        $data = $request->validate(['answer' => 'required|string|max:10000']);
        $this->portal->answer($submission, $data['answer'], $request->user());

        return back()->with('success', 'Answered. The prospect has been emailed and can see it in their portal.');
    }

    public function file(Prospect $prospect, PortalSubmission $submission, int $index): StreamedResponse
    {
        abort_unless($submission->prospect_id === $prospect->id, 404);
        $file = $submission->files[$index] ?? abort(404);

        return Storage::disk('local')->download($file['path'], $file['name']);
    }

    /** @return array<string, mixed> */
    public static function portalView(Prospect $prospect, Proposal $proposal): array
    {
        $prospect->loadMissing('offering');

        return [
            'prospect' => $prospect,
            'offering' => $prospect->offering,
            'proposal' => $proposal,
            'claims' => $proposal->claims(),
            'documents' => $prospect->offering->documents()->where('audience', 'shareable')->whereNotNull('file_path')->get(),
        ];
    }

    private function belongs(Prospect $prospect, Proposal $proposal): void
    {
        abort_unless($proposal->prospect_id === $prospect->id, 404);
    }

    private function act(Prospect $prospect, \Closure $action): RedirectResponse
    {
        $redirect = redirect()->route('admin.proposal-studio.prospects.show', $prospect);
        try {
            $action();
        } catch (StudioRefused $e) {
            return $redirect->with('error', $e->getMessage());
        }

        return $redirect;
    }
}
