<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PartnerCompany;
use App\Models\User;
use App\Services\Partner\SpotClaimService;
use App\Services\Partner\SpotImportValidator;
use App\Services\Partner\SpotMergeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The claimed/unclaimed board.
 *
 * This is the only admin screen that lists holding spots, and it is why the
 * rest of the admin does not have to: the user list, the sponsor screens and
 * every count stay about members, and everything about positions waiting on an
 * owner lives here.
 */
class HoldingSpotController extends Controller
{
    public function __construct(
        private SpotClaimService $claims,
        private SpotMergeService $merges,
        private SpotImportValidator $validator,
    ) {}

    public function index(Request $request)
    {
        $companies = PartnerCompany::orderBy('name')->get();
        $companyId = $request->integer('company') ?: null;

        $spots = User::query()
            ->whereNotNull('partner_company_id')
            ->when($companyId, fn ($q) => $q->where('partner_company_id', $companyId))
            ->when($request->get('state', 'unclaimed') === 'unclaimed', fn ($q) => $q->holding())
            ->when($request->get('state') === 'claimed', fn ($q) => $q->activated())
            ->when($request->get('state') === 'merged', fn ($q) => $q->merged())
            ->when($request->filled('q'), function ($q) use ($request) {
                $term = trim((string) $request->get('q'));

                // An exact partner id first, and on its own. That is what an
                // admin types when a member is on the phone quoting their iHub
                // number, and it is an index lookup rather than a scan of a
                // million rows for a substring.
                $q->where(fn ($w) => $w
                    ->where('external_user_id', $term)
                    ->orWhere('name', 'ilike', "%{$term}%")
                    ->orWhere('email', 'ilike', "%{$term}%"));
            })
            ->with('partnerCompany:id,name,slug', 'placementParent:id,name,account_status')
            ->orderByDesc('id')
            // simplePaginate: paginate() would COUNT the whole filtered set to
            // number the pages, which on an unfiltered million-row board is the
            // most expensive thing on the screen. The totals above are cached
            // and already say how many there are.
            ->simplePaginate(50)
            ->withQueryString();

        return view('admin.partners.spots', [
            'spots'     => $spots,
            'companies' => $companies,
            'companyId' => $companyId,
            'state'     => $request->get('state', 'unclaimed'),
            'totals'    => $this->totals($companyId),
            'byCompany' => $this->byCompany(),
        ]);
    }

    /**
     * Issue a replacement code for one spot.
     *
     * Shown once, in a flash message, and never stored in readable form. An
     * admin reads it to the member on the call they are already on.
     */
    public function reissue(User $spot)
    {
        abort_unless($spot->isImported(), 404);

        if (! $spot->isHolding()) {
            return back()->withErrors(['spot' => 'That position has already been claimed — '
                . 'send its owner a password reset instead.']);
        }

        $code = $this->claims->reissueCode($spot);

        return back()->with('code_issued', [
            'spot'  => $spot->external_user_id,
            'code'  => $code,
        ]);
    }

    /**
     * Fold a claimed position into another account, downline and all.
     *
     * The founder path, for the case the claim flow could not catch: they had
     * already claimed their imported position as a separate account before
     * anybody thought about merging. Restricted to positions that have been
     * claimed — see SpotMergeService for why an unclaimed one is off limits.
     */
    public function merge(Request $request, User $spot)
    {
        abort_unless($spot->isImported(), 404);

        $data = $request->validate(['into' => 'required|string|max:255']);

        $into = $this->validator->resolveExistingUser($data['into']);

        if ($into === null) {
            return back()->withErrors(['into' =>
                "No active Quantum account matches '{$data['into']}'. Use the account's email "
                . 'address or its numeric user ID.']);
        }

        if (($reason = $this->merges->reasonItCannotMerge($spot, $into)) !== null) {
            return back()->withErrors(['into' => $reason]);
        }

        $moved = $this->merges->merge($spot, $into);

        return back()->with('success', sprintf(
            '%s merged into %s. %s position(s) moved with it, and %s is out of the structure.',
            $spot->external_user_id,
            $into->name,
            number_format($moved),
            $spot->external_user_id,
        ));
    }

    /**
     * The totals across companies, or one of them.
     *
     * Summed from the maintained counters. Aggregating the positions
     * themselves is the query these columns exist to avoid — fifteen seconds
     * for iHub alone — and this is the top of a page an admin refreshes all day.
     *
     * @return array{claimed:int, unclaimed:int, total:int}
     */
    private function totals(?int $companyId): array
    {
        $row = PartnerCompany::query()
            ->when($companyId, fn ($q) => $q->whereKey($companyId))
            ->selectRaw('coalesce(sum(total_spots), 0) AS total')
            ->selectRaw('coalesce(sum(unclaimed_spots), 0) AS unclaimed')
            ->first();

        $total     = (int) ($row->total ?? 0);
        $unclaimed = (int) ($row->unclaimed ?? 0);

        return ['claimed' => max(0, $total - $unclaimed), 'unclaimed' => $unclaimed, 'total' => $total];
    }

    /**
     * The split per company, from the same counters.
     *
     * @return array<int, array{id:int, name:string, slug:string, total:int, unclaimed:int}>
     */
    private function byCompany(): array
    {
        return PartnerCompany::query()
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'total_spots', 'unclaimed_spots'])
            ->map(fn (PartnerCompany $c) => [
                'id'        => $c->id,
                'name'      => $c->name,
                'slug'      => $c->slug,
                'total'     => (int) $c->total_spots,
                'unclaimed' => (int) $c->unclaimed_spots,
            ])
            ->all();
    }
}
