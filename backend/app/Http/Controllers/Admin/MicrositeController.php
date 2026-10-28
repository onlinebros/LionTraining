<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\MicrositeController as ClientMicrositeController;
use App\Models\BusinessAccount;
use App\Models\MicrositeComment;
use App\Models\MicrositeMember;
use App\Models\MicrositeSection;
use App\Services\ProposalStudio\AccountService;
use App\Services\ProposalStudio\MicrositeService;
use App\Services\ProposalStudio\StudioRefused;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Proposal Studio → an account's microsite builder: its sections (drafted by
 * the assistant, edited and published here), its look, and who may open it.
 */
class MicrositeController extends Controller
{
    public function __construct(private MicrositeService $sites, private AccountService $accounts) {}

    public function edit(BusinessAccount $account)
    {
        $account->load('opportunities.offering', 'opportunities.publishedProposal', 'members.requestedBy', 'sections.comments.member', 'sections.opportunity.offering');
        $sections = $account->sections;

        return view('admin.proposal-studio.accounts.site', [
            'account' => $account,
            'sections' => $sections,
            'busy' => $sections->filter->isAssistantBusy()->pluck('id')->all(),
            'claimsByOffering' => $account->opportunities->pluck('offering')->unique('id')
                ->mapWithKeys(fn ($o) => [$o->id => $o->approvedClaims()->with('document')->get()]),
        ]);
    }

    // Polled while the assistant is writing.
    public function status(BusinessAccount $account): JsonResponse
    {
        return response()->json(['busy' => $account->sections()->get()->contains(fn ($s) => $s->isAssistantBusy())]);
    }

    public function preview(BusinessAccount $account)
    {
        return view('microsite.show', ClientMicrositeController::siteView($account, null) + ['preview' => true]);
    }

    public function settings(Request $request, BusinessAccount $account): RedirectResponse
    {
        $limits = config('proposal-studio.microsite');
        $data = $request->validate([
            'site_title' => 'nullable|string|max:255',
            'site_intro' => 'nullable|string|max:5000',
            'brand_color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'logo' => 'nullable|image|max:'.$limits['logo_max_kb'].'|mimes:'.implode(',', $limits['logo_mimes']),
        ]);
        $account->forceFill([
            'site_title' => $data['site_title'] ?? null,
            'site_intro' => $data['site_intro'] ?? null,
            'site_show_progress' => $request->boolean('site_show_progress'),
            'brand_color' => $request->boolean('clear_color') ? null : (isset($data['brand_color']) ? strtolower($data['brand_color']) : $account->brand_color),
        ])->save();
        if ($request->hasFile('logo')) {
            $this->accounts->storeLogo($account, $request->file('logo'));
        } elseif ($request->boolean('remove_logo')) {
            $this->accounts->removeLogo($account);
        }

        return back()->with('success', 'Saved.');
    }

    public function starter(BusinessAccount $account): RedirectResponse
    {
        $this->sites->starterSections($account);

        return back()->with('success', 'Added the usual sections. Draft them with the assistant, then publish each one when it is right.');
    }

    public function draftEmpty(Request $request, BusinessAccount $account): RedirectResponse
    {
        $sent = $this->sites->draftEmpty($account, $request->user());

        return back()->with($sent ? 'success' : 'error', $sent
            ? "The assistant is drafting {$sent} section".($sent === 1 ? '' : 's').'. They appear here as they finish.'
            : 'There was no empty section the assistant could draft. Link sections to an opportunity first.');
    }

    public function storeSection(Request $request, BusinessAccount $account): RedirectResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(array_keys(MicrositeSection::KINDS))],
            'prospect_id' => 'nullable|integer',
        ]);
        $opportunity = isset($data['prospect_id']) ? $account->opportunities()->find($data['prospect_id']) : $account->opportunities()->first();

        return $this->act(fn () => $this->sites->addSection($account, $data['kind'], $opportunity), 'Section added.');
    }

    public function updateSection(Request $request, BusinessAccount $account, MicrositeSection $section): RedirectResponse
    {
        $this->belongs($account, $section);
        $data = $request->validate([
            'title' => 'required|string|max:255',
            'brief' => 'nullable|string|max:5000',
            'body' => 'nullable|string|max:30000',
            'prospect_id' => 'nullable|integer',
            'claim_ids' => 'nullable|array',
            'claim_ids.*' => 'integer',
        ]);
        $data['claim_ids'] ??= [];
        $data['pricing_only'] = $request->boolean('pricing_only');

        try {
            $this->sites->updateSection($section, $data);
        } catch (StudioRefused $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        // "Save and redraft": the assistant writes from the brief just saved.
        return $request->input('then') === 'draft'
            ? $this->draftSection($request, $account, $section->fresh())
            : back()->with('success', 'Saved.');
    }

    public function destroySection(BusinessAccount $account, MicrositeSection $section): RedirectResponse
    {
        $this->belongs($account, $section);
        $section->delete();

        return back()->with('success', "\"{$section->title}\" deleted.");
    }

    public function draftSection(Request $request, BusinessAccount $account, MicrositeSection $section): RedirectResponse
    {
        $this->belongs($account, $section);

        return $this->act(fn () => $this->sites->draft($section, $request->user()), 'The assistant is drafting it. This page refreshes when it is done.');
    }

    public function publishSection(Request $request, BusinessAccount $account, MicrositeSection $section): RedirectResponse
    {
        $this->belongs($account, $section);

        return $this->act(fn () => $this->sites->publish($section, $request->user()), "\"{$section->title}\" is on the site.");
    }

    public function unpublishSection(Request $request, BusinessAccount $account, MicrositeSection $section): RedirectResponse
    {
        $this->belongs($account, $section);
        $this->sites->unpublish($section, $request->user());

        return back()->with('success', "\"{$section->title}\" is off the site.");
    }

    public function moveSection(Request $request, BusinessAccount $account, MicrositeSection $section): RedirectResponse
    {
        $this->belongs($account, $section);
        $this->sites->move($section, $request->input('direction') === 'up' ? -1 : 1);

        return back();
    }

    public function invite(Request $request, BusinessAccount $account): RedirectResponse
    {
        $data = $request->validate([
            'email' => 'required|email|max:255',
            'name' => 'nullable|string|max:255',
            'role' => ['required', Rule::in(array_keys(MicrositeMember::ROLES))],
        ]);

        return $this->act(
            fn () => $this->sites->invite($account, $data['email'], $data['name'] ?? null, $data['role'], $request->boolean('can_see_pricing'), $request->user()),
            "Sent {$data['email']} a sign-in link.",
        );
    }

    public function updateMember(Request $request, BusinessAccount $account, MicrositeMember $member): RedirectResponse
    {
        $this->owns($account, $member);
        $data = $request->validate(['role' => ['required', Rule::in(array_keys(MicrositeMember::ROLES))]]);
        $this->sites->updateAccess($member, $data['role'], $request->boolean('can_see_pricing'), $request->user());

        return back()->with('success', "Updated {$member->label()}'s access.");
    }

    public function resend(BusinessAccount $account, MicrositeMember $member): RedirectResponse
    {
        $this->owns($account, $member);
        abort_unless($member->isActive(), 404);
        $this->sites->sendLink($member);

        return back()->with('success', "Sent {$member->email} a new link.");
    }

    public function revoke(Request $request, BusinessAccount $account, MicrositeMember $member): RedirectResponse
    {
        $this->owns($account, $member);
        $this->sites->revoke($member, $request->user());

        return back()->with('success', "{$member->label()} can no longer open the site.");
    }

    public function approve(Request $request, BusinessAccount $account, MicrositeMember $member): RedirectResponse
    {
        $this->owns($account, $member);
        $data = $request->validate(['role' => ['required', Rule::in(array_keys(MicrositeMember::ROLES))]]);

        return $this->act(fn () => $this->sites->approveRequest($member, $data['role'], $request->boolean('can_see_pricing'), $request->user()), "Approved. {$member->email} has been sent a link.");
    }

    public function decline(Request $request, BusinessAccount $account, MicrositeMember $member): RedirectResponse
    {
        $this->owns($account, $member);

        return $this->act(fn () => $this->sites->declineRequest($member, $request->user()), 'Declined. Nothing was sent.');
    }

    public function reply(Request $request, BusinessAccount $account, MicrositeComment $comment): RedirectResponse
    {
        abort_unless($comment->section->business_account_id === $account->id, 404);
        $data = $request->validate(['reply' => 'required|string|max:10000']);
        $this->sites->reply($comment, $data['reply'], $request->user());

        return back()->with('success', 'Replied. They have been emailed.');
    }

    private function belongs(BusinessAccount $account, MicrositeSection $section): void
    {
        abort_unless($section->business_account_id === $account->id, 404);
    }

    private function owns(BusinessAccount $account, MicrositeMember $member): void
    {
        abort_unless($member->business_account_id === $account->id, 404);
    }

    private function act(\Closure $action, string $success): RedirectResponse
    {
        try {
            $action();
        } catch (StudioRefused $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('success', $success);
    }
}
