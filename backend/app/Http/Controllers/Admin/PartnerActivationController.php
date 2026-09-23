<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PartnerCompany;
use App\Models\User;
use App\Services\Partner\ActivationSalesReport;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Activations & Sales: who came through a partner's claim page, and what they
 * have sold since.
 *
 * Admin-only, alongside the rest of the partner screens. The numbers here name
 * individual members and their revenue, which is not something a partner may
 * see about anybody but themselves.
 */
class PartnerActivationController extends Controller
{
    public function __construct(private ActivationSalesReport $report) {}

    public function index(Request $request)
    {
        $companies = PartnerCompany::orderBy('name')->get();
        $companyId = $this->companyId($request);

        $filters = $this->filters($request);

        $rows = $this->report->query($companyId, $filters)
            // paginate(), not the spots board's simplePaginate(): this set is
            // the people who claimed, not the million positions, so counting it
            // to number the pages costs nothing worth avoiding.
            ->paginate(50)
            ->withQueryString();

        return view('admin.partners.activations', [
            'rows'      => $rows,
            'companies' => $companies,
            'companyId' => $companyId,
            'filters'   => $filters,
            'summary'   => $this->report->summary($companyId),
            'report'    => $this->report,
        ]);
    }

    /**
     * The same report as a file, for the partner review it usually ends up in.
     *
     * Streamed and chunked rather than collected: the filtered set is small
     * today, but this is a screen somebody will point at a whole company the
     * week after a big claim push.
     */
    public function export(Request $request): StreamedResponse
    {
        $companyId = $this->companyId($request);
        $query     = $this->report->query($companyId, $this->filters($request));
        $report    = $this->report;

        $company  = $companyId ? PartnerCompany::find($companyId) : null;
        $filename = 'activations-'.($company?->slug ?? 'all').'-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($query, $report) {
            $out = fopen('php://output', 'wb');

            fputcsv($out, [
                'partner_company', 'partner_user_id', 'name', 'email', 'referral_code',
                'claimed_at', 'position_state', 'merged_into',
                'customer_orders', 'customer_revenue', 'own_orders', 'own_revenue',
                'membership',
            ]);

            $query->chunk(500, function ($chunk) use ($out, $report) {
                foreach ($chunk as $row) {
                    fputcsv($out, [
                        $row->partnerCompany?->name,
                        $row->external_user_id,
                        $row->name,
                        $row->email,
                        $row->referral_code,
                        optional($row->claimed_at)->toDateTimeString(),
                        $row->account_status === User::ACCOUNT_MERGED ? 'merged' : 'activated',
                        $row->mergedInto?->email,
                        (int) $row->customer_orders,
                        number_format($row->customer_revenue / 100, 2, '.', ''),
                        (int) $row->own_orders,
                        number_format($row->own_revenue / 100, 2, '.', ''),
                        $report->membershipLabel($row)['label'],
                    ]);
                }
            });

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /**
     * Which company the report is about.
     *
     * No company chosen means the biggest import rather than all of them: this
     * report is read one partner at a time, and "everybody who ever claimed
     * anything" is not a question anyone asks. `?company=all` is the way to see
     * the lot.
     */
    private function companyId(Request $request): ?int
    {
        if ($request->query('company') === 'all') {
            return null;
        }

        return $request->integer('company')
            ?: $this->report->defaultCompany()?->id;
    }

    /** @return array{state:string, sort:string, q:string} */
    private function filters(Request $request): array
    {
        $states = [
            ActivationSalesReport::STATE_ACTIVATED,
            ActivationSalesReport::STATE_MERGED,
            ActivationSalesReport::STATE_ALL,
        ];

        $sorts = [
            ActivationSalesReport::SORT_REVENUE,
            ActivationSalesReport::SORT_ORDERS,
            ActivationSalesReport::SORT_RECENT,
        ];

        $state = (string) $request->query('state', ActivationSalesReport::STATE_ACTIVATED);
        $sort  = (string) $request->query('sort', ActivationSalesReport::SORT_REVENUE);

        return [
            'state' => in_array($state, $states, true) ? $state : ActivationSalesReport::STATE_ACTIVATED,
            'sort'  => in_array($sort, $sorts, true) ? $sort : ActivationSalesReport::SORT_REVENUE,
            'q'     => trim((string) $request->query('q', '')),
        ];
    }
}
