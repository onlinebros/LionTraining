@extends('layouts.admin')

@section('title', 'Payout Accounts')
@section('page-title', 'Payout Accounts')

@section('breadcrumb')
    <li class="breadcrumb-item active">Payout Accounts</li>
@endsection

@push('styles')
<style>
    .pa-tile { border: 1px solid var(--bs-border-color, #2a2a2e); border-radius: 10px; padding: 16px 18px; height: 100%; }
    .pa-tile .label { font-size: .7rem; letter-spacing: .09em; text-transform: uppercase; color: #8a8a8a; margin-bottom: 6px; }
    .pa-tile .figure { font-size: 1.55rem; font-weight: 700; line-height: 1.1; font-variant-numeric: tabular-nums; }
    .pa-tile .sub { font-size: .78rem; color: #8a8a8a; margin-top: 4px; }
    .pa-tile.crit { border-left: 4px solid #B4483F; }
    .pa-tile.warn { border-left: 4px solid #C99A3E; }
    .pa-tile.good { border-left: 4px solid #3E9E6E; }
    .pa-tile.accent { border-left: 4px solid #D4AF37; }
    .req-chip { font-size: .72rem; font-weight: 500; }
    .acct-id { font-size: .7rem; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; color: #9a9a9a; }
</style>
@endpush

@section('content')
<div class="row">

    @foreach(['success' => 'success', 'error' => 'danger'] as $key => $class)
        @if(session($key))
            <div class="col-12"><div class="alert alert-{{ $class }}">{{ session($key) }}</div></div>
        @endif
    @endforeach

    @if(! $connectEnabled)
        <div class="col-12"><div class="alert alert-warning">Stripe payouts are switched off (STRIPE_CONNECT_ENABLED).</div></div>
    @endif

    @if($testMode)
        <div class="col-12">
            <div class="alert alert-secondary py-2 small mb-3">Stripe is in <strong>test mode</strong>. These are sandbox accounts.</div>
        </div>
    @endif

    <div class="col-12 mb-3">
        <div class="row g-3">
            <div class="col-xl-3 col-md-6">
                <div class="pa-tile crit">
                    <div class="label">Blocked now</div>
                    <div class="figure">{{ $counts['outstanding'] }}</div>
                    <div class="sub">Stripe is waiting on them today</div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="pa-tile warn">
                    <div class="label">Will be blocked</div>
                    <div class="figure">{{ $counts['upcoming'] }}</div>
                    <div class="sub">Details due as payouts approach $3,000</div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="pa-tile good">
                    <div class="label">Fully verified</div>
                    <div class="figure">{{ $counts['ready'] }}</div>
                    <div class="sub">Payouts on, nothing outstanding</div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="pa-tile accent">
                    <div class="label">Never started</div>
                    <div class="figure">{{ $notStarted }}</div>
                    <div class="sub">Partners with no payout account</div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-12 mb-3">
        <div class="alert alert-light border py-2 small mb-0">
            Stripe asks for the minimum to switch payouts on and holds the rest (typically date of birth and an
            identity document) until an account approaches <strong>$3,000</strong> in payouts, when it pauses them.
            This list is kept current by the <code>account.updated</code> webhook, so nobody has to check the Stripe
            dashboard.
        </div>
    </div>

    <div class="col-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <h5 class="mb-0">Connected Accounts</h5>

                <div class="d-flex gap-2 flex-wrap align-items-center">
                    <form method="GET" class="d-flex gap-2">
                        <input type="hidden" name="filter" value="{{ $filter }}">
                        <input type="search" name="q" value="{{ $search }}" class="form-control form-control-sm" placeholder="Name or email" style="width: 180px">
                        <button class="btn btn-sm btn-outline-secondary">Search</button>
                    </form>

                    <form method="POST" action="{{ route('admin.billing.payout-accounts.sync-all') }}">
                        @csrf
                        <button class="btn btn-sm btn-outline-secondary">Re-sync from Stripe</button>
                    </form>

                    <form method="POST" action="{{ route('admin.billing.payout-accounts.request-all') }}"
                          onsubmit="return confirm('Email every partner Stripe is waiting on?')">
                        @csrf
                        <input type="hidden" name="include_upcoming" value="1">
                        <button class="btn btn-sm btn-primary" @disabled($counts['outstanding'] + $counts['upcoming'] === 0)>Request information</button>
                    </form>
                </div>
            </div>

            <div class="card-header py-2">
                <div class="btn-group btn-group-sm flex-wrap">
                    @foreach([
                        'all'         => 'All ('.$counts['all'].')',
                        'outstanding' => 'Blocked ('.$counts['outstanding'].')',
                        'upcoming'    => 'Due later ('.$counts['upcoming'].')',
                        'documents'   => 'Needs ID ('.$counts['documents'].')',
                        'errors'      => 'Failed checks ('.$counts['errors'].')',
                        'ready'       => 'Verified ('.$counts['ready'].')',
                    ] as $value => $label)
                        <a href="{{ route('admin.billing.payout-accounts.index', array_filter(['filter' => $value, 'q' => $search])) }}"
                           class="btn btn-outline-secondary {{ $filter === $value ? 'active' : '' }}">{{ $label }}</a>
                    @endforeach
                </div>
            </div>

            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Partner</th>
                                <th>Payouts</th>
                                <th>Stripe still needs</th>
                                <th>Due later</th>
                                <th>Last checked</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        @forelse($rows as $row)
                            <tr>
                                <td style="min-width: 190px">
                                    <a href="{{ route('admin.users.edit', $row['user']->id) }}">{{ $row['user']->name }}</a>
                                    <br><small class="text-muted">{{ $row['user']->email }}</small>
                                    <br><span class="acct-id">{{ $row['account_id'] }}</span>
                                </td>
                                <td>
                                    @if($row['payouts_enabled'])
                                        <span class="badge bg-success">Enabled</span>
                                    @elseif($row['details_submitted'])
                                        <span class="badge bg-warning">In review</span>
                                    @else
                                        <span class="badge bg-danger">Not finished</span>
                                    @endif

                                    @if($row['disabled_reason'])
                                        <div class="small text-danger mt-1">{{ \App\Support\ConnectRequirements::label($row['disabled_reason']) }}</div>
                                    @endif

                                    @if($row['tax_status'] && $row['tax_status'] !== 'active')
                                        <div class="small text-muted mt-1">1099 details: {{ str_replace('_', ' ', $row['tax_status']) }}</div>
                                    @endif
                                </td>
                                <td style="min-width: 200px">
                                    @if($row['outstanding'])
                                        <div class="d-flex flex-wrap gap-1">
                                            @foreach($row['outstanding'] as $item)
                                                <span class="badge bg-danger req-chip">{{ $item }}</span>
                                            @endforeach
                                        </div>
                                        @if($row['deadline'])
                                            <div class="small text-danger mt-1">Due {{ $row['deadline']->format('M j, Y') }}</div>
                                        @endif
                                    @else
                                        <span class="text-muted small">—</span>
                                    @endif

                                    @foreach($row['errors'] as $error)
                                        <div class="small text-danger mt-1">⚠ {{ $error['reason'] ?? 'Verification failed' }}</div>
                                    @endforeach
                                </td>
                                <td style="min-width: 200px">
                                    @if($row['upcoming'])
                                        <div class="d-flex flex-wrap gap-1">
                                            @foreach($row['upcoming'] as $item)
                                                <span class="badge bg-secondary req-chip">{{ $item }}</span>
                                            @endforeach
                                        </div>
                                    @else
                                        <span class="text-muted small">—</span>
                                    @endif
                                </td>
                                <td class="small text-muted">{{ $row['synced_at'] ? $row['synced_at']->diffForHumans() : 'never' }}</td>
                                <td class="text-end" style="min-width: 190px">
                                    <div class="d-inline-flex gap-1">
                                        <form method="POST" action="{{ route('admin.billing.payout-accounts.sync', $row['user']->id) }}">
                                            @csrf
                                            <button class="btn btn-sm btn-outline-secondary" title="Re-pull this account from Stripe">Sync</button>
                                        </form>

                                        @if($row['outstanding'] || $row['upcoming'])
                                            <form method="POST" action="{{ route('admin.billing.payout-accounts.request', $row['user']->id) }}">
                                                @csrf
                                                <button class="btn btn-sm btn-outline-primary" title="Email the partner the exact items">Ask</button>
                                            </form>
                                        @endif

                                        <a href="https://dashboard.stripe.com/{{ $testMode ? 'test/' : '' }}connect/accounts/{{ $row['account_id'] }}"
                                           target="_blank" rel="noopener" class="btn btn-sm btn-link" title="Open in Stripe">Stripe ↗</a>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="text-center text-muted py-4">No payout accounts match this filter.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            @if($rows->hasPages())
                <div class="card-footer">{{ $rows->links() }}</div>
            @endif
        </div>
    </div>
</div>
@endsection
