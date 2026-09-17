<?php

namespace App\Http\Controllers;

use App\Models\PartnerCompany;
use App\Models\User;
use App\Services\Partner\SpotAlreadyClaimed;
use App\Services\Partner\SpotClaimService;
use App\Services\Partner\SpotMergeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * The co-branded door a partner company's members come in through.
 *
 * Two steps, deliberately. First they prove the position is theirs with the id
 * and code their own company gave them; only then are they asked for a name,
 * an email and a password. Asking for everything on one screen would mean
 * collecting personal details from people whose code turns out to be wrong,
 * and it would put a registration form on a public URL that anybody can load —
 * which is how you end up with accounts that were never anybody's position.
 *
 * Between the steps the verified spot id is held in the session, not in a
 * hidden field. A hidden field is a claim of "I already proved this", supplied
 * by the person making the claim.
 */
class PartnerClaimController extends Controller
{
    public function __construct(
        private SpotClaimService $claims,
        private SpotMergeService $merges,
    ) {}

    /** Step one: the partner's logo, and the two fields. */
    public function show(Request $request, string $slug)
    {
        $company = $this->activeCompany($slug);

        // A link from the partner's own system can carry both credentials, so
        // their member lands on a filled-in form instead of copying a code off
        // a letter. Worth real onboarding percentage points, and the cost is
        // that a live credential is briefly in a URL — see prefillFromQuery().
        if (($prefill = $this->prefillFromQuery($request)) !== null) {
            $request->session()->put($this->prefillKey($company), $prefill);

            // Straight back to the clean URL. The code is now out of the
            // address bar, out of anything they screenshot or paste to a
            // friend, and out of the Referer we would otherwise hand to every
            // asset on the page.
            return redirect()->route('partner.claim', $company->slug);
        }

        return $this->withoutReferrer(view('public.partner.verify', [
            'company' => $company,
            'counts'  => $company->spotCounts(),
            'prefill' => $request->session()->get($this->prefillKey($company), []),
        ]));
    }

    /** Step one, submitted. */
    public function verify(Request $request, string $slug)
    {
        $company = $this->activeCompany($slug);

        $data = $request->validate([
            'external_user_id' => 'required|string|max:64',
            'activation_code'  => 'required|string|max:128',
        ], [], [
            'external_user_id' => $company->identifier_label,
            'activation_code'  => $company->activation_label,
        ]);

        $outcome = $this->claims->verify($company, $data['external_user_id'], $data['activation_code']);

        if ($outcome['result'] === SpotClaimService::RESULT_ALREADY) {
            return back()
                ->withInput($request->only('external_user_id'))
                ->withErrors(['external_user_id' =>
                    'This position has already been claimed. If it was you, sign in instead — '
                    . 'use "forgot password" if you need to.']);
        }

        if ($outcome['result'] === SpotClaimService::RESULT_LOCKED) {
            $minutes = max(1, (int) ceil(now()->diffInMinutes($outcome['locked_until'], absolute: true)));

            return back()
                ->withInput($request->only('external_user_id'))
                ->withErrors(['activation_code' =>
                    "Too many incorrect codes for this position. Try again in {$minutes} minute(s), "
                    . 'or contact support to have a new code issued.']);
        }

        if ($outcome['result'] !== SpotClaimService::RESULT_OK) {
            // One message for "no such id" and "wrong code" on purpose. Telling
            // the two apart hands an attacker a way to sift the real ids out of
            // a guessed list before spending any guesses on codes.
            return back()
                ->withInput($request->only('external_user_id'))
                ->withErrors(['activation_code' =>
                    "That {$company->identifier_label} and {$company->activation_label} do not match "
                    . 'our records. Check both against the letter or email from '
                    . $company->name . '.']);
        }

        $request->session()->put($this->sessionKey($company), [
            'spot_id'     => $outcome['spot']->id,
            'verified_at' => now()->timestamp,
        ]);

        // The code has done its job. Holding it in the session past this point
        // buys nothing and keeps a live credential alive in the session store.
        $request->session()->forget($this->prefillKey($company));

        return redirect()->route('partner.claim.details', $company->slug);
    }

    /** Step two: who they are. */
    public function details(Request $request, string $slug)
    {
        $company = $this->activeCompany($slug);
        $spot    = $this->verifiedSpot($request, $company);

        if ($spot === null) {
            return redirect()->route('partner.claim', $company->slug);
        }

        // Nothing is pre-filled, because we hold nothing to pre-fill it with:
        // the import carries identifiers and structure, never personal data.
        // The form is the first time this person tells us anything about
        // themselves, which is the whole point.
        return $this->withoutReferrer(view('public.partner.details', [
            'company'  => $company,
            'spot'     => $spot,
            // Somebody who is already a Quantum partner — a founder, usually —
            // should end up with one account and one team, not two accounts
            // and a support ticket.
            'existing' => $this->mergeableAccount($request, $spot),
        ]));
    }

    /** Step two, submitted. The position gets an owner. */
    public function store(Request $request, string $slug)
    {
        $company = $this->activeCompany($slug);
        $spot    = $this->verifiedSpot($request, $company);

        if ($spot === null) {
            return redirect()->route('partner.claim', $company->slug)
                ->withErrors(['activation_code' =>
                    'Your session timed out before you finished. Enter your '
                    . $company->identifier_label . ' and ' . $company->activation_label . ' again.']);
        }

        $data = $request->validate([
            'name'          => 'required|string|max:255',
            // Holding spots carry no email at all, so any hit here is a real
            // account — including this person's own, if they are already a
            // Quantum partner and are now claiming a second position. That is
            // the case this check catches, and it is the only place it can be
            // caught, because the import gave us nothing to match them on.
            'email'         => 'required|email|max:255|unique:users,email',
            'password'      => 'required|string|min:8|confirmed',
            'phone'         => 'nullable|string|max:32',
            'address_line1' => 'nullable|string|max:255',
            'address_line2' => 'nullable|string|max:255',
            'city'          => 'nullable|string|max:255',
            'state'         => 'nullable|string|max:64',
            'postal_code'   => 'nullable|string|max:32',
            'country'       => 'nullable|string|max:2',
            'terms'         => 'accepted',
        ], [
            'terms.accepted' => 'Please accept the Terms of Service and Privacy Policy to continue.',
        ]);

        try {
            $user = $this->claims->claim($spot, $data);
        } catch (SpotAlreadyClaimed) {
            $request->session()->forget($this->sessionKey($company));

            throw ValidationException::withMessages([
                'email' => 'This position was claimed while you were filling in the form. '
                    . 'If that was you in another tab, sign in.',
            ]);
        }

        $request->session()->forget($this->sessionKey($company));

        Auth::login($user);
        $request->session()->regenerate();

        // Straight into the same enrollment choice every other partner makes.
        // An imported position is a position, not a different product, and a
        // second billing path for these members is a second thing to keep
        // correct forever.
        return redirect()->route('member.billing.start')->with(
            'status',
            "Welcome to Quantum 3 Solution, {$user->name}. Your position from {$company->name} is now yours.",
        );
    }

    /**
     * Fold this position into the account the visitor is already signed in as.
     *
     * The founder case, and the reason it is offered here rather than left to
     * an administrator: the person has just proved the position is theirs by
     * typing its activation code, which is better evidence than anything an
     * admin could act on afterwards. Their whole imported organisation moves
     * under the account they already had.
     */
    public function merge(Request $request, string $slug)
    {
        $company = $this->activeCompany($slug);
        $spot    = $this->verifiedSpot($request, $company);
        $into    = $this->mergeableAccount($request, $spot);

        if ($spot === null || $into === null) {
            return redirect()->route('partner.claim', $company->slug)
                ->withErrors(['activation_code' =>
                    'Your session timed out before you finished. Enter your '
                    . $company->identifier_label . ' and ' . $company->activation_label . ' again.']);
        }

        try {
            $moved = $this->merges->merge($spot, $into, ownershipProven: true);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['merge' => $e->getMessage()]);
        }

        $request->session()->forget($this->sessionKey($company));

        return redirect()->route('member.network')->with(
            'status',
            "Your {$company->name} position has been added to this account. "
            . number_format($moved) . ' position(s) came with it.',
        );
    }

    /**
     * The signed-in account this position could be folded into, if any.
     *
     * Only ever the visitor's own account — there is no way to name somebody
     * else's — and only when the merge would actually be legal, so the page
     * never offers a button that is going to refuse.
     */
    private function mergeableAccount(Request $request, ?User $spot): ?User
    {
        $user = $request->user();

        if ($spot === null || $user === null || ! $user->isActivated()) {
            return null;
        }

        return $this->merges->reasonItCannotMerge($spot, $user, ownershipProven: true) === null
            ? $user
            : null;
    }

    // ── Deep links ────────────────────────────────────────────────────────────

    /**
     * Credentials handed to us in the query string by the partner's system.
     *
     * Matching is deliberately forgiving, and matched on a normalised name
     * rather than an exact one: case, underscores, hyphens and spaces are all
     * stripped before comparison, so `activation_code`, `activate_code`,
     * `activationCode` and `ACTIVATE-CODE` are the same parameter.
     *
     * This is the same approach SpotImportTemplate takes to CSV headers, and
     * for the same reason. The link is generated by the partner's code, not
     * ours. iHub sends `activate_code`; the first version of this listed the
     * spellings by hand, did not happen to include that one, and the boxes
     * silently came up empty — a bug with no error message, found only because
     * somebody clicked their own link. Listing spellings by hand loses that bet
     * eventually; normalising wins it for every partner at once.
     *
     * ── What this costs, honestly ────────────────────────────────────────────
     *
     * The activation code travels in a URL. That puts it in the partner's
     * mailer, in the member's browser history, and in our web server's access
     * log. Three things narrow it:
     *
     *   - the immediate redirect in show(), which takes it out of the address
     *     bar before the page is ever rendered, so it is not in what they
     *     screenshot, bookmark or forward;
     *   - a Referrer-Policy on the page, so the URL cannot travel sideways in
     *     a Referer header;
     *   - the code being single-use and the position locking after five wrong
     *     attempts, so a code recovered from a log is worthless the moment its
     *     owner has claimed.
     *
     * What it does not fix is the access log on the first request. If that
     * matters more than the onboarding lift, the answer is for the partner to
     * POST the pair, or to hand out a short-lived opaque token instead of the
     * code itself — neither of which they need to do to ship this.
     *
     * @return array{external_user_id: string, activation_code: ?string}|null
     */
    private function prefillFromQuery(Request $request): ?array
    {
        $id = $this->firstOf($request, [
            'uid', 'userid', 'user', 'externaluserid', 'id',
            'memberid', 'membernumber', 'distributorid', 'iboid',
        ]);

        $code = $this->firstOf($request, [
            'code', 'activationcode', 'activatecode', 'activation', 'activate',
            'accesscode', 'claimcode', 'ac', 'pin',
        ]);

        if ($id === null && $code === null) {
            return null;
        }

        return [
            'external_user_id' => $id ?? '',
            'activation_code'  => $code,
        ];
    }

    /**
     * The first query parameter whose normalised name is one of $names.
     *
     * $names are given already normalised — lowercase, letters and digits only.
     *
     * @param  list<string>  $names
     */
    private function firstOf(Request $request, array $names): ?string
    {
        $wanted = array_flip($names);

        // Ordered by the caller's preference rather than the order the partner
        // happened to put them in the URL, so a link carrying both `uid` and
        // `id` resolves the same way every time.
        foreach ($names as $name) {
            foreach ($request->query() as $key => $value) {
                if (! is_string($value) || ! isset($wanted[$this->normaliseParam($key)])) {
                    continue;
                }

                if ($this->normaliseParam($key) !== $name) {
                    continue;
                }

                $value = trim($value);

                if ($value !== '') {
                    return mb_substr($value, 0, 128);
                }
            }
        }

        return null;
    }

    /** Strip everything a partner's link generator might vary on. */
    private function normaliseParam(string $key): string
    {
        return strtolower(preg_replace('/[^a-z0-9]/i', '', $key) ?? '');
    }

    private function prefillKey(PartnerCompany $company): string
    {
        return "partner_prefill.{$company->id}";
    }

    /**
     * Keep the claim pages out of anybody else's Referer header.
     *
     * Belt and braces next to the redirect: these pages can arrive with a
     * credential in the query string, and a stray third-party script or image
     * is the classic way that ends up somewhere it should not be.
     *
     * Note that production's nginx already sends
     * `Referrer-Policy: strict-origin-when-cross-origin` for every response,
     * and where two of these headers are present the browser takes the last —
     * so this one is usually overridden. That is fine: strict-origin sends only
     * the scheme and host cross-origin, never the path or query, so the code
     * cannot leak that way either. This header is here for the environments
     * that have no global policy, not as the thing the design relies on. The
     * redirect in show() is that.
     */
    private function withoutReferrer(\Illuminate\Contracts\View\View $view): \Illuminate\Http\Response
    {
        return response($view)->header('Referrer-Policy', 'no-referrer');
    }

    // ── Internals ─────────────────────────────────────────────────────────────

    private function activeCompany(string $slug): PartnerCompany
    {
        return PartnerCompany::where('slug', $slug)->where('is_active', true)->firstOrFail();
    }

    /**
     * The spot this session proved it owns, re-checked against the database.
     *
     * Re-checked rather than trusted: the session says a code was accepted at
     * some point, and between then and now the spot may have been claimed in
     * another tab or had its code reissued by support.
     */
    private function verifiedSpot(Request $request, PartnerCompany $company): ?User
    {
        $held = $request->session()->get($this->sessionKey($company));

        if (! is_array($held) || ! isset($held['spot_id'])) {
            return null;
        }

        $spot = User::query()
            ->where('partner_company_id', $company->id)
            ->find($held['spot_id']);

        return $spot?->isHolding() === true ? $spot : null;
    }

    /** Scoped per company so verifying at one partner is not a pass at another. */
    private function sessionKey(PartnerCompany $company): string
    {
        return "partner_claim.{$company->id}";
    }
}
