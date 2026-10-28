<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Admin\ProposalController;
use App\Models\OfferingDocument;
use App\Models\PortalEvent;
use App\Models\PortalInvite;
use App\Models\Prospect;
use App\Services\ProposalStudio\PortalService;
use App\Services\ProposalStudio\StudioRefused;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A prospect's portal, /proposal/{ref}: their published proposal, and the
 * forms to tell us more, ask questions and take the next step. Only people
 * invited by email can open it (owner decision D3); the sign-in link puts
 * the invite in their session. See memory-bank/proposal-studio.md.
 */
class PortalController extends Controller
{
    public function __construct(private PortalService $portal) {}

    public function show(Request $request, string $ref)
    {
        $prospect = $this->prospect($ref);
        $invite = $this->portal->current($request, $prospect);
        $proposal = $prospect->publishedProposal;

        if ($invite === null || $proposal === null) {
            return response()->view('portal.locked', ['prospect' => $prospect, 'ref' => $ref, 'gone' => $proposal === null], $invite === null ? 200 : 404);
        }

        $invite->forceFill(['last_seen_at' => now()])->save();
        PortalEvent::record($prospect, $invite, 'viewed', ['version' => $proposal->version]);

        return view('portal.show', ProposalController::portalView($prospect, $proposal) + [
            'invite' => $invite,
            'questions' => $prospect->submissions()->where('kind', 'question')->where('portal_invite_id', $invite->id)->orderBy('id')->get(),
            'preview' => false,
        ]);
    }

    public function enter(Request $request, string $ref, string $token): RedirectResponse
    {
        $prospect = $this->prospect($ref);
        if ($this->portal->signIn($request, $prospect, $token) === null) {
            return redirect()->route('portal.show', $ref)->with('error', 'That link has expired or is no longer valid. Ask for a new one below.');
        }
        $request->session()->regenerate();

        return redirect()->route('portal.show', $ref);
    }

    public function requestLink(Request $request, string $ref): RedirectResponse
    {
        $data = $request->validate(['email' => 'required|email|max:255']);
        $this->portal->requestLink($this->prospect($ref), $data['email']);

        return back()->with('success', 'If that address was invited, a new link is on its way. Check your inbox.');
    }

    public function submit(Request $request, string $ref): RedirectResponse
    {
        [$prospect, $invite] = $this->signedIn($request, $ref);
        $limits = config('proposal-studio.portal');
        $data = $request->validate([
            'kind' => 'required|in:details,correction,quote_request,survey_request',
            'body' => 'nullable|string|max:10000',
            'fields' => 'nullable|array|max:20',
            'fields.*' => 'nullable|string|max:2000',
            'files' => 'nullable|array|max:'.$limits['upload_max_files'],
            'files.*' => 'file|max:'.$limits['upload_max_kb'].'|mimes:'.implode(',', $limits['upload_mimes']),
        ]);
        if (blank($data['body'] ?? null) && ! array_filter($data['fields'] ?? []) && empty($data['files']) && in_array($data['kind'], ['details', 'correction'], true)) {
            return back()->with('error', 'There was nothing to send. Fill in at least one field.');
        }

        try {
            $this->portal->submit($prospect, $invite, $data['kind'], $data['body'] ?? null, $data['fields'] ?? [], $request->file('files', []));
        } catch (StudioRefused $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->to(route('portal.show', $ref).'#next')->with('success', match ($data['kind']) {
            'quote_request' => 'Thank you. We will prepare a formal quote and be in touch.',
            'survey_request' => 'Thank you. We will be in touch to arrange a site survey.',
            default => 'Thank you. That has come straight to us, and we will update your proposal.',
        });
    }

    public function ask(Request $request, string $ref): RedirectResponse
    {
        [$prospect, $invite] = $this->signedIn($request, $ref);
        $data = $request->validate(['question' => 'required|string|max:3000']);

        try {
            $submission = $this->portal->ask($prospect, $invite, $data['question']);
        } catch (StudioRefused $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->to(route('portal.show', $ref).'#questions')->with('success', $submission->answer !== null
            ? 'Answered below.'
            : 'Thank you. We will answer by email shortly, and the answer will appear here.');
    }

    public function download(Request $request, string $ref, OfferingDocument $document): StreamedResponse
    {
        [$prospect, $invite] = $this->signedIn($request, $ref);
        abort_unless($document->offering_id === $prospect->offering_id && $document->audience === 'shareable' && $document->file_path, 404);
        PortalEvent::record($prospect, $invite, 'downloaded', ['document' => $document->id, 'title' => $document->title]);

        return Storage::disk('local')->download($document->file_path, $document->original_name ?: basename($document->file_path));
    }

    public function signOut(Request $request, string $ref): RedirectResponse
    {
        $request->session()->forget(PortalService::sessionKey($this->prospect($ref)));

        return redirect()->route('portal.show', $ref);
    }

    private function prospect(string $ref): Prospect
    {
        abort_unless(config('proposal-studio.enabled'), 404);

        return Prospect::with('offering')->where('portal_ref', $ref)->firstOrFail();
    }

    /** @return array{0: Prospect, 1: PortalInvite} */
    private function signedIn(Request $request, string $ref): array
    {
        $prospect = $this->prospect($ref);
        $invite = $this->portal->current($request, $prospect);
        abort_if($invite === null || $prospect->publishedProposal === null, 403);

        return [$prospect, $invite];
    }
}
