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
                $term = '%' . $request->get('q') . '%';
                $q->where(fn ($w) => $w->where('external_user_id', 'ilike', $term)
                    ->orWhere('name', 'ilike', $term)
                    ->orWhere('email', 'ilike', $term));
            })
            ->with('partnerCompany:id,name,slug', 'placementParent:id,name,account_status')
            ->orderByDesc('id')
            ->paginate(50)
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

    /** @return array{claimed:int, unclaimed:int, total:int} */
    private function totals(?int $companyId): array
    {
        $row = User::query()
            ->whereNotNull('partner_company_id')
            ->when($companyId, fn ($q) => $q->where('partner_company_id', $companyId))
            ->selectRaw('count(*) AS total')
            ->selectRaw('count(*) FILTER (WHERE account_status = ?) AS unclaimed', [User::ACCOUNT_HOLDING])
            ->first();

        $total     = (int) ($row->total ?? 0);
        $unclaimed = (int) ($row->unclaimed ?? 0);

        return ['claimed' => $total - $unclaimed, 'unclaimed' => $unclaimed, 'total' => $total];
    }

    /**
     * The split per company, in one query.
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function byCompany()
    {
        return DB::table('users')
            ->join('partner_companies', 'partner_companies.id', '=', 'users.partner_company_id')
            ->groupBy('partner_companies.id', 'partner_companies.name', 'partner_companies.slug')
            ->selectRaw('partner_companies.id, partner_companies.name, partner_companies.slug')
            ->selectRaw('count(*) AS total')
            ->selectRaw('count(*) FILTER (WHERE users.account_status = ?) AS unclaimed', [User::ACCOUNT_HOLDING])
            ->orderBy('partner_companies.name')
            ->get();
    }
}
