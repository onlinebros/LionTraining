<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\BillingException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\ConnectInformationNeeded;
use App\Services\Stripe\StripeClientFactory;
use App\Services\Stripe\StripeConnectService;
use App\Support\ConnectRequirements;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Payout Accounts: who is missing what for Stripe.
 *
 * Read from `users.connect_requirements`, which every sync and every
 * `account.updated` webhook rewrites, so the list is a local query rather than
 * an API call per partner. Two buckets are kept apart:
 *   - Outstanding: Stripe wants it now; payouts are blocked or about to be.
 *   - Upcoming: eventually-due and future requirements, typically the details
 *     Stripe asks for as payouts approach $3,000.
 */
class ConnectAccountController extends Controller
{
    /** How many accounts one "Re-sync" pass pulls from Stripe. */
    private const SYNC_BATCH = 100;

    public function __construct(
        private readonly StripeConnectService $connect,
        private readonly StripeClientFactory $stripe,
    ) {}

    public function index(Request $request)
    {
        $rows = $this->rows();

        $isUpcomingOnly = fn ($r) => ! $r['has_outstanding'] && $r['upcoming'];
        $isReady = fn ($r) => $r['payouts_enabled'] && ! $r['has_outstanding'] && ! $r['upcoming'];

        $counts = [
            'all'         => $rows->count(),
            'outstanding' => $rows->where('has_outstanding', true)->count(),
            'upcoming'    => $rows->filter($isUpcomingOnly)->count(),
            'documents'   => $rows->where('needs_document', true)->count(),
            'errors'      => $rows->filter(fn ($r) => $r['errors'])->count(),
            'ready'       => $rows->filter($isReady)->count(),
        ];

        $filter = $request->input('filter', 'all');

        $rows = match ($filter) {
            'outstanding' => $rows->where('has_outstanding', true),
            'upcoming'    => $rows->filter($isUpcomingOnly),
            'documents'   => $rows->where('needs_document', true),
            'errors'      => $rows->filter(fn ($r) => $r['errors']),
            'ready'       => $rows->filter($isReady),
            default       => $rows,
        };

        if ($search = trim((string) $request->input('q'))) {
            $needle = strtolower($search);
            $rows = $rows->filter(fn ($r) => str_contains(strtolower($r['user']->name.' '.$r['user']->email), $needle));
        }

        return view('admin.billing.payout-accounts', [
            'rows'          => $this->paginate($rows->values(), $request),
            'counts'        => $counts,
            'filter'        => $filter,
            'search'        => $search,
            // Partners who have never opened Get Paid. Admins are not paid commissions.
            'notStarted'    => User::whereNull('stripe_connect_account_id')
                ->whereDoesntHave('role', fn ($q) => $q->where('is_admin', true))
                ->count(),
            'connectEnabled' => $this->connect->enabled(),
            'testMode'      => $this->stripe->isConfigured() && $this->stripe->isTestMode(),
        ]);
    }

    /** One row per connected account, worst first. */
    private function rows(): Collection
    {
        return User::whereNotNull('stripe_connect_account_id')
            ->orderBy('name')
            ->get()
            ->map(function (User $user) {
                $outstanding = $user->connectOutstandingRequirements();
                $upcoming    = $user->connectUpcomingRequirements();

                return [
                    'user'              => $user,
                    'account_id'        => $user->stripe_connect_account_id,
                    'payouts_enabled'   => (bool) $user->connect_payouts_enabled,
                    'details_submitted' => (bool) $user->connect_details_submitted,
                    'tax_status'        => $user->connect_tax_reporting_status,
                    'synced_at'         => $user->connect_synced_at,
                    'deadline'          => $user->connectRequirementDeadline(),
                    'disabled_reason'   => $user->connect_requirements['disabled_reason'] ?? null,
                    'has_outstanding'   => $outstanding !== [],
                    'outstanding'       => ConnectRequirements::summarise($outstanding),
                    'upcoming'          => ConnectRequirements::summarise($upcoming),
                    'needs_document'    => ConnectRequirements::needsDocument(array_merge($outstanding, $upcoming)),
                    'errors'            => $user->connectRequirementErrors(),
                ];
            })
            ->sortBy(fn ($row) => [
                $row['has_outstanding'] ? 0 : ($row['upcoming'] ? 1 : 2),
                $row['deadline'] ? $row['deadline']->timestamp : PHP_INT_MAX,
                $row['user']->name,
            ])
            ->values();
    }

    private function paginate(Collection $rows, Request $request): LengthAwarePaginator
    {
        $perPage = 30;
        $page    = LengthAwarePaginator::resolveCurrentPage();

        return new LengthAwarePaginator(
            $rows->forPage($page, $perPage)->values(),
            $rows->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );
    }

    public function sync(User $user)
    {
        if (! $user->stripe_connect_account_id) {
            return back()->with('error', 'That partner has no payout account yet.');
        }

        try {
            // A status read: a duplicate bank account is no reason to fail it.
            $this->connect->syncAccount($user, enforceIdentity: false);
        } catch (BillingException $e) {
            return back()->with('error', 'Sync failed: '.$e->getMessage());
        }

        return back()->with('success', "Re-synced {$user->name} from Stripe.");
    }

    /** Re-pull accounts, least recently synced first, for when a webhook was missed. */
    public function syncAll()
    {
        $users = User::whereNotNull('stripe_connect_account_id')
            ->orderByRaw('connect_synced_at IS NULL DESC')
            ->orderBy('connect_synced_at')
            ->limit(self::SYNC_BATCH)
            ->get();

        $synced = 0;

        foreach ($users as $user) {
            try {
                if ($this->connect->syncAccount($user, enforceIdentity: false)) {
                    $synced++;
                }
            } catch (BillingException $e) {
                Log::notice('Connect bulk sync skipped an account', ['user_id' => $user->id, 'error' => $e->getMessage()]);
            }
        }

        $total   = User::whereNotNull('stripe_connect_account_id')->count();
        $message = "Re-synced {$synced} payout account(s) from Stripe.";

        if ($total > self::SYNC_BATCH) {
            $message .= ' '.($total - self::SYNC_BATCH).' more were not touched. Run it again to continue.';
        }

        return back()->with('success', $message);
    }

    /**
     * Email one partner exactly what Stripe is missing.
     *
     * The email sends them to our Get Paid page rather than to Stripe: the
     * embedded form is the only place these accounts can be completed.
     */
    public function requestInformation(User $user)
    {
        $outstanding = $user->connectOutstandingRequirements();
        $items = ConnectRequirements::summarise($outstanding ?: $user->connectUpcomingRequirements());

        if ($items === []) {
            return back()->with('success', "Stripe is not asking {$user->name} for anything right now.");
        }

        $user->notify(new ConnectInformationNeeded($items, urgent: $outstanding !== []));

        return back()->with('success', "Emailed {$user->name} for: ".implode(', ', $items).'.');
    }

    /** The same request, to everyone Stripe is waiting on. */
    public function requestInformationBulk(Request $request)
    {
        $upcomingToo = $request->boolean('include_upcoming');
        $sent = 0;

        foreach ($this->rows() as $row) {
            $items = $row['outstanding'] ?: ($upcomingToo ? $row['upcoming'] : []);

            if ($items === []) {
                continue;
            }

            $row['user']->notify(new ConnectInformationNeeded($items, urgent: $row['has_outstanding']));
            $sent++;
        }

        return back()->with('success', $sent === 0
            ? 'Nobody is waiting on information right now.'
            : "Emailed {$sent} partner(s) about what Stripe needs.");
    }
}
