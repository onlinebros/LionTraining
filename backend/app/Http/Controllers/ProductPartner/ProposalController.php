<?php

namespace App\Http\Controllers\ProductPartner;

use App\Http\Controllers\Admin\ProposalController as AdminProposalController;
use App\Models\Proposal;
use App\Services\ProposalStudio\ProposalService;
use App\Services\ProposalStudio\StudioRefused;
use App\Support\ProductPartner;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Proposals for the vendor's own product line, in the Product Partner
 * portal. Two owner decisions (2026-10-20) shape it: the vendor reviews the
 * first few proposals before they are published (D5), and sees a prospect's
 * details once a proposal is published (D6). That is a deliberate exception
 * to the counts-only Prospects page, which stays as it is for ordinary leads:
 * a published proposal is a deal the vendor has been asked to help close.
 */
class ProposalController extends PortalController
{
    public function __construct(private ProposalService $proposals) {}

    public function index(Request $request)
    {
        $vendor = $this->vendor($request);

        $all = $this->scope($vendor)->with('prospect.offering', 'publisher')
            ->whereIn('status', ['in_review', 'published'])
            ->latest('updated_at')->get();

        return view('product-partner.proposals.index', $this->shell($request, $vendor) + [
            'waiting' => $all->where('status', 'in_review'),
            'published' => $all->where('status', 'published'),
        ]);
    }

    public function show(Request $request, Proposal $proposal)
    {
        $vendor = $this->vendor($request);
        $this->authorizeFor($vendor, $proposal);

        return view('product-partner.proposals.show', $this->shell($request, $vendor)
            + AdminProposalController::portalView($proposal->prospect, $proposal)
            + ['submissions' => $proposal->prospect->submissions()->whereIn('kind', ['quote_request', 'survey_request', 'details'])->get()]);
    }

    public function preview(Request $request, Proposal $proposal)
    {
        $this->authorizeFor($this->vendor($request), $proposal);

        return view('portal.show', AdminProposalController::portalView($proposal->prospect, $proposal) + ['preview' => true]);
    }

    public function review(Request $request, Proposal $proposal): RedirectResponse
    {
        $vendor = $this->vendor($request);
        $this->authorizeFor($vendor, $proposal);
        abort_if(ProductPartner::isViewingAsAnother($request->user()), 403, 'An admin viewing as the vendor cannot review for them.');

        $data = $request->validate([
            'decision' => 'required|in:approve,changes',
            'note' => 'nullable|string|max:5000|required_if:decision,changes',
        ]);

        try {
            $this->proposals->vendorReview($proposal, $request->user(), $data['decision'] === 'approve', $data['note'] ?? null);
        } catch (StudioRefused $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('product-partner.proposals', ['vendor' => $vendor])->with('success', $data['decision'] === 'approve'
            ? 'Approved. The proposal is now published to the prospect.'
            : 'Sent back to Q3 with your notes.');
    }

    /** @return Builder<Proposal> */
    private function scope(string $vendor)
    {
        return Proposal::whereHas('prospect.offering', fn ($q) => $q->where('vendor_key', $vendor));
    }

    private function authorizeFor(string $vendor, Proposal $proposal): void
    {
        abort_unless(
            config('proposal-studio.enabled')
            && in_array($proposal->status, ['in_review', 'published'], true)
            && $this->scope($vendor)->whereKey($proposal->id)->exists(),
            404,
        );
    }
}
