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
use App\Models\Offering;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Proposal Studio → an account's microsite builder: its sections (drafted by
 * the assistant, edited and published here), its look, and who may open it.
 */
class MicrositeController extends Controller
{
    public function __construct(private MicrositeService $sites, private AccountService $accounts) {}

    public function edit(Request $request, BusinessAccount $account)
    {
        $account->load('offering', 'opportunities.offering', 'opportunities.publishedProposal', 'members.requestedBy', 'sections.comments.member', 'sections.opportunity.offering');
        $account->sections->each->setRelation('account', $account);
        $sections = $account->sections;
        $offerings = $sections->map->offering()->push($account->offering)->filter()->unique('id');

        // Reading time per section and per person, from the site's beacon.
        $reading = DB::table('microsite_section_views')
            ->whereIn('microsite_section_id', $sections->pluck('id'))
            ->selectRaw('microsite_section_id, microsite_member_id, sum(seconds) as seconds, max(updated_at) as last')
            ->groupBy('microsite_section_id', 'microsite_member_id')->get();

        return view('admin.proposal-studio.accounts.site', [
            'account' => $account,
            'sections' => $sections,
            'selected' => $sections->firstWhere('id', (int) $request->query('section')),
            'tab' => in_array($request->query('tab'), ['build', 'design', 'access', 'insights'], true) ? $request->query('tab') : 'build',
            'busy' => $sections->filter->isAssistantBusy()->pluck('id')->all(),
            'claimsByOffering' => $offerings->mapWithKeys(fn ($o) => [$o->id => $o->approvedClaims()->with('document')->get()]),
            'library' => $account->offering ? [
                'documents' => $account->offering->documents()->count(),
                'claims' => $account->offering->claims()->count(),
                'approved' => $account->offering->approvedClaims()->count(),
            ] : null,
            'allOfferings' => Offering::where('is_active', true)->orderBy('name')->get(),
            'reading' => $reading,
        ]);
    }

    // Polled while the assistant is writing.
    public function status(BusinessAccount $account): JsonResponse
    {
        $sections = $account->sections()->get();

        return response()->json([
            'busy' => $sections->filter->isAssistantBusy()->pluck('id')->values(),
            'stamp' => $sections->max(fn ($s) => $s->updated_at?->timestamp),
        ]);
    }

    public function preview(BusinessAccount $account)
    {
        return view('microsite.show', ClientMicrositeController::siteView($account, null) + ['preview' => true]);
    }

    /** "Build the site": the outline if there is none, then the assistant drafts it. */
    public function build(Request $request, BusinessAccount $account): RedirectResponse
    {
        $data = $request->validate(['goal' => 'nullable|string|max:5000']);
        try {
            $sent = $this->sites->build($account, $request->user(), $data['goal'] ?? $account->site_goal, $request->boolean('rewrite'));
        } catch (StudioRefused $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with($sent ? 'success' : 'error', $sent
            ? "The assistant is writing {$sent} section".($sent === 1 ? '' : 's').'. Watch them appear in the preview.'
            : 'Every section already has content. Tick "Rewrite sections already written" to redraft them all.');
    }

    public function reorder(Request $request, BusinessAccount $account): JsonResponse
    {
        $data = $request->validate(['ids' => 'required|array|max:100', 'ids.*' => 'integer']);
        $this->sites->reorder($account, $data['ids']);

        return response()->json(['ok' => true]);
    }

    public function planStep(Request $request, BusinessAccount $account, MicrositeSection $section, int $index): RedirectResponse
    {
        $this->belongs($account, $section);

        return $this->act(fn () => $this->sites->setPlanStep($section, $index, $request->boolean('done'), null, $request->user()), 'Updated the plan.', $section);
    }

    public function settings(Request $request, BusinessAccount $account): RedirectResponse
    {
        $limits = config('proposal-studio.microsite');
        $data = $request->validate([
            'site_title' => 'nullable|string|max:255',
            'site_intro' => 'nullable|string|max:5000',
            'brand_color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'site_theme' => ['nullable', Rule::in(array_keys(BusinessAccount::THEMES))],
            'site_goal' => 'nullable|string|max:5000',
            'offering_id' => 'nullable|exists:offerings,id',
            'logo' => 'nullable|image|max:'.$limits['logo_max_kb'].'|mimes:'.implode(',', $limits['logo_mimes']),
        ]);
        $account->forceFill([
            'site_title' => $data['site_title'] ?? null,
            'site_intro' => $data['site_intro'] ?? null,
            'site_show_progress' => $request->boolean('site_show_progress'),
            'site_theme' => $data['site_theme'] ?? $account->site_theme,
            'site_goal' => array_key_exists('site_goal', $data) ? $data['site_goal'] : $account->site_goal,
            'offering_id' => $request->has('offering_id') ? ($data['offering_id'] ?? null) : $account->offering_id,
            'brand_color' => $request->boolean('clear_color') ? null : (isset($data['brand_color']) ? strtolower($data['brand_color']) : $account->brand_color),
        ])->save();
        if ($request->hasFile('logo')) {
            $this->accounts->storeLogo($account, $request->file('logo'));
        } elseif ($request->boolean('remove_logo')) {
            $this->accounts->removeLogo($account);
        }

        return redirect()->route('admin.proposal-studio.accounts.site', [$account, 'tab' => 'design'])->with('success', 'Saved.');
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
            'after' => 'nullable|integer',
        ]);
        $opportunity = isset($data['prospect_id']) ? $account->opportunities()->find($data['prospect_id']) : $account->opportunities()->first();

        try {
            $section = $this->sites->addSection($account, $data['kind'], $opportunity, $data['after'] ?? null);
            if ($section->isWritten() && $request->boolean('draft')) {
                $this->sites->draft($section, $request->user());
            }
        } catch (StudioRefused $e) {
            return back()->with('error', $e->getMessage());
        }

        return $this->toSection($account, $section, 'Section added.');
    }

    public function updateSection(Request $request, BusinessAccount $account, MicrositeSection $section): RedirectResponse
    {
        $this->belongs($account, $section);
        $data = $request->validate([
            'title' => 'required|string|max:255',
            'layout' => ['nullable', Rule::in(array_keys(MicrositeSection::LAYOUTS))],
            'brief' => 'nullable|string|max:5000',
            'body' => 'nullable|string|max:30000',
            'content' => 'nullable|array',
            'prospect_id' => 'nullable|integer',
            'claim_ids' => 'nullable|array',
            'claim_ids.*' => 'integer',
            'instruction' => 'nullable|string|max:2000',
        ]);
        $data['claim_ids'] ??= [];
        $instruction = $data['instruction'] ?? null;
        unset($data['instruction']);
        if (($data['layout'] ?? $section->layout) !== $section->layout) {
            // A new layout starts from the assistant, or from nothing.
            $data['content'] = [];
        } elseif (! $request->has('content')) {
            unset($data['content']);
        }
        $data['pricing_only'] = $request->boolean('pricing_only');

        try {
            $this->sites->updateSection($section, $data);
            // "Save and redraft": the assistant writes from what was just saved.
            if ($request->input('then') === 'draft') {
                $this->sites->draft($section->fresh(), $request->user(), $instruction);

                return $this->toSection($account, $section, 'Saved. The assistant is rewriting it.');
            }
        } catch (StudioRefused $e) {
            return $this->toSection($account, $section, null, $e->getMessage())->withInput();
        }

        return $this->toSection($account, $section, 'Saved.');
    }

    public function destroySection(BusinessAccount $account, MicrositeSection $section): RedirectResponse
    {
        $this->belongs($account, $section);
        $section->delete();

        return redirect()->route('admin.proposal-studio.accounts.site', $account)->with('success', "\"{$section->title}\" deleted.");
    }

    public function draftSection(Request $request, BusinessAccount $account, MicrositeSection $section): RedirectResponse
    {
        $this->belongs($account, $section);

        $instruction = $request->validate(['instruction' => 'nullable|string|max:2000'])['instruction'] ?? null;

        return $this->act(fn () => $this->sites->draft($section, $request->user(), $instruction), 'The assistant is writing it. The preview updates when it is done.', $section);
    }

    public function publishSection(Request $request, BusinessAccount $account, MicrositeSection $section): RedirectResponse
    {
        $this->belongs($account, $section);

        return $this->act(fn () => $this->sites->publish($section, $request->user()), "\"{$section->title}\" is on the site.", $section);
    }

    public function unpublishSection(Request $request, BusinessAccount $account, MicrositeSection $section): RedirectResponse
    {
        $this->belongs($account, $section);
        $this->sites->unpublish($section, $request->user());

        return $this->toSection($account, $section, "\"{$section->title}\" is off the site.");
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

    private function act(\Closure $action, string $success, ?MicrositeSection $section = null): RedirectResponse
    {
        try {
            $action();
        } catch (StudioRefused $e) {
            return ($section ? $this->toSection($section->account, $section, null, $e->getMessage()) : back()->with('error', $e->getMessage()))->withInput();
        }

        return $section ? $this->toSection($section->account, $section, $success) : back()->with('success', $success);
    }

    /** Back to the builder with the section open. */
    private function toSection(BusinessAccount $account, MicrositeSection $section, ?string $success, ?string $error = null): RedirectResponse
    {
        return redirect()->route('admin.proposal-studio.accounts.site', [$account, 'section' => $section->id])
            ->with($error ? 'error' : 'success', $error ?? $success);
    }
}
