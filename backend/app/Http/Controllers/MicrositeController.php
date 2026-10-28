<?php

namespace App\Http\Controllers;

use App\Models\BusinessAccount;
use App\Models\MicrositeMember;
use App\Models\MicrositeSection;
use App\Models\OfferingDocument;
use App\Services\ProposalStudio\MicrositeService;
use App\Services\ProposalStudio\StudioRefused;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A business account's private microsite, /client/{ref}: the sections we
 * have published for them, co-branded with their logo. Only invited people
 * can open it; anyone invited may ask for a colleague to be added, and an
 * admin approves (owner, 2026-10-28). See memory-bank/b2b-accounts.md.
 */
class MicrositeController extends Controller
{
    public function __construct(private MicrositeService $sites) {}

    public function show(Request $request, string $ref)
    {
        $account = $this->account($ref);
        $member = $this->sites->current($request, $account);

        if ($member === null) {
            return view('microsite.locked', ['account' => $account, 'ref' => $ref]);
        }
        $this->sites->recordVisit($member);

        return view('microsite.show', self::siteView($account, $member) + ['preview' => false]);
    }

    public function enter(Request $request, string $ref, string $token): RedirectResponse
    {
        $account = $this->account($ref);
        if ($this->sites->signIn($request, $account, $token) === null) {
            return redirect()->route('microsite.show', $ref)->with('error', 'That link has expired or is no longer valid. Ask for a new one below.');
        }
        $request->session()->regenerate();

        return redirect()->route('microsite.show', $ref);
    }

    public function requestLink(Request $request, string $ref): RedirectResponse
    {
        $data = $request->validate(['email' => 'required|email|max:255']);
        $this->sites->requestLink($this->account($ref), $data['email']);

        return back()->with('success', 'If that address has access, a new link is on its way. Check your inbox.');
    }

    public function requestAccess(Request $request, string $ref): RedirectResponse
    {
        [, $member] = $this->signedIn($request, $ref);
        $data = $request->validate([
            'email' => 'required|email|max:255',
            'name' => 'nullable|string|max:255',
            'note' => 'nullable|string|max:2000',
        ]);

        try {
            $this->sites->requestAccess($member, $data['email'], $data['name'] ?? null, $data['note'] ?? null);
        } catch (StudioRefused $e) {
            return redirect()->to(route('microsite.show', $ref).'#colleagues')->withInput()->with('error', $e->getMessage());
        }

        return redirect()->to(route('microsite.show', $ref).'#colleagues')
            ->with('success', 'Thank you. We will send them a link once we have approved it.');
    }

    public function comment(Request $request, string $ref, MicrositeSection $section): RedirectResponse
    {
        [$account, $member] = $this->signedIn($request, $ref);
        abort_unless($section->business_account_id === $account->id && $section->isPublished(), 404);
        $data = $request->validate(['body' => 'required|string|max:5000']);

        try {
            $this->sites->comment($section, $member, $data['body']);
        } catch (StudioRefused $e) {
            return redirect()->to(route('microsite.show', $ref).'#s-'.$section->id)->with('error', $e->getMessage());
        }

        return redirect()->to(route('microsite.show', $ref).'#s-'.$section->id)->with('success', 'Thank you. That has come straight to us.');
    }

    public function download(Request $request, string $ref, OfferingDocument $document): StreamedResponse
    {
        [$account, $member] = $this->signedIn($request, $ref);
        $offerings = self::siteView($account, $member)['sections']->map(fn ($s) => $s->opportunity?->offering_id)->filter()->unique();
        abort_unless($document->audience === 'shareable' && $document->file_path && $offerings->contains($document->offering_id), 404);
        $account->log('client', "{$member->label()} downloaded \"{$document->title}\".", null, ['member' => $member->id, 'document' => $document->id]);

        return Storage::disk('local')->download($document->file_path, $document->original_name ?: basename($document->file_path));
    }

    // Shown on the sign-in page too, so the client recognises the site.
    public function logo(string $ref): StreamedResponse
    {
        $account = $this->account($ref);
        abort_unless($account->logo_path, 404);

        return Storage::disk('local')->response($account->logo_path, null, ['Cache-Control' => 'private, max-age=3600']);
    }

    public function signOut(Request $request, string $ref): RedirectResponse
    {
        $request->session()->forget(MicrositeService::sessionKey($this->account($ref)));

        return redirect()->route('microsite.show', $ref);
    }

    /**
     * What a member sees: the published sections they may see, and the titles
     * of those still being prepared. With no member (the admin's preview),
     * every section, drafts included, marked as such.
     *
     * @return array<string, mixed>
     */
    public static function siteView(BusinessAccount $account, ?MicrositeMember $member): array
    {
        $account->loadMissing('sections.opportunity.offering', 'sections.opportunity.publishedProposal', 'sections.comments.member');
        $preview = $member === null;
        $pricing = $preview || $member->can_see_pricing;

        $visible = $account->sections->filter(fn (MicrositeSection $s) => ($preview || $s->isPublished()) && ($pricing || ! $s->pricing_only));
        $progress = ! $preview && $account->site_show_progress
            ? $account->sections->reject->isPublished()->reject(fn ($s) => $s->pricing_only && ! $pricing)->pluck('title')
            : collect();

        return [
            'account' => $account,
            'member' => $member,
            'sections' => $visible->values(),
            'progress' => $progress,
            'canSeePricing' => $pricing,
        ];
    }

    private function account(string $ref): BusinessAccount
    {
        abort_unless(config('proposal-studio.enabled'), 404);

        return BusinessAccount::where('site_ref', $ref)->firstOrFail();
    }

    /** @return array{0: BusinessAccount, 1: MicrositeMember} */
    private function signedIn(Request $request, string $ref): array
    {
        $account = $this->account($ref);
        $member = $this->sites->current($request, $account);
        abort_if($member === null, 403);

        return [$account, $member];
    }
}
