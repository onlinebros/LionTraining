@extends('layouts.admin')

@section('title', 'Partner Activations & Sales')
@section('page-title', 'Activations & Sales')

@section('breadcrumb')
    <li class="breadcrumb-item active">Activations &amp; Sales</li>
@endsection

@section('content')

@php
    $money = fn ($minor) => '$'.number_format($minor / 100, 2);
@endphp

{{-- Activated is the number this page exists for: positions are on the spots
     board, people are here. --}}
<div class="row g-3 mb-3">
    <div class="col-md-3">
        <div class="card mb-0"><div class="card-body py-4 text-center">
            <div class="q3-stat-value q3-stat-value--gold">{{ number_format($summary['activated']) }}</div>
            <div class="q3-stat-label">Activated &mdash; claimed their position</div>
            @if($summary['merged'] > 0)
                <div class="text-muted small mt-1">
                    plus {{ number_format($summary['merged']) }} folded into an account they already had
                </div>
            @endif
        </div></div>
    </div>
    <div class="col-md-3">
        <div class="card mb-0"><div class="card-body py-4 text-center">
            <div class="q3-stat-value">{{ number_format($summary['selling']) }}</div>
            <div class="q3-stat-label">With at least one sale</div>
            <div class="text-muted small mt-1">
                @php $people = $summary['activated'] + $summary['merged']; @endphp
                {{ $people > 0 ? round($summary['selling'] / $people * 100) : 0 }}% of those who came through
            </div>
        </div></div>
    </div>
    <div class="col-md-3">
        <div class="card mb-0"><div class="card-body py-4 text-center">
            <div class="q3-stat-value">{{ $money($summary['revenue']) }}</div>
            <div class="q3-stat-label">Direct sales &mdash; {{ number_format($summary['orders']) }} orders</div>
            @if($summary['own_orders'] > 0)
                <div class="text-muted small mt-1">
                    {{ number_format($summary['own_orders']) }} own purchases ({{ $money($summary['own_revenue']) }}) not counted here
                </div>
            @endif
        </div></div>
    </div>
    <div class="col-md-3">
        <div class="card mb-0"><div class="card-body py-4 text-center">
            <div class="q3-stat-value">{{ number_format($summary['paying']) }}</div>
            <div class="q3-stat-label">On a paying membership</div>
            <div class="text-muted small mt-1">
                of {{ number_format($summary['imported']) }} positions imported,
                {{ number_format($summary['unclaimed']) }} still unclaimed
            </div>
        </div></div>
    </div>
</div>

@if($summary['unpriced_orders'] > 0)
    {{-- An order confirmed by hand carries no total from the vendor. Saying so
         is better than quietly adding zero to the revenue above. --}}
    <div class="alert alert-warning py-2 small">
        {{ number_format($summary['unpriced_orders']) }} confirmed
        {{ Str::plural('order', $summary['unpriced_orders']) }}
        {{ $summary['unpriced_orders'] === 1 ? 'has' : 'have' }} no order total recorded,
        so the revenue figures leave {{ $summary['unpriced_orders'] === 1 ? 'it' : 'them' }} out.
    </div>
@endif

<div class="card">
    <div class="card-header py-3">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label small mb-1">Partner company</label>
                <select name="company" class="form-select form-select-sm">
                    @foreach($companies as $company)
                        <option value="{{ $company->id }}" @selected($companyId === $company->id)>
                            {{ $company->name }}
                        </option>
                    @endforeach
                    <option value="all" @selected($companyId === null)>All companies</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-1">Position</label>
                <select name="state" class="form-select form-select-sm">
                    <option value="activated" @selected($filters['state'] === 'activated')>Activated</option>
                    <option value="merged"    @selected($filters['state'] === 'merged')>Merged into another account</option>
                    <option value="all"       @selected($filters['state'] === 'all')>Both</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-1">Order by</label>
                <select name="sort" class="form-select form-select-sm">
                    <option value="revenue" @selected($filters['sort'] === 'revenue')>Sales revenue</option>
                    <option value="orders"  @selected($filters['sort'] === 'orders')>Order count</option>
                    <option value="recent"  @selected($filters['sort'] === 'recent')>Most recently activated</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small mb-1">Search</label>
                <input type="search" name="q" value="{{ $filters['q'] }}"
                       class="form-control form-control-sm" placeholder="Partner ID, name or email">
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button class="btn btn-sm btn-primary flex-fill" type="submit">Filter</button>
                <a class="btn btn-sm btn-outline-secondary"
                   href="{{ route('admin.partners.activations.export', request()->query()) }}"
                   title="Download these rows as CSV">CSV</a>
            </div>
        </form>
    </div>

    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead><tr>
                <th>Partner ID</th>
                <th>Member</th>
                <th>Activated</th>
                <th class="text-end">Direct sales</th>
                <th class="text-end">Revenue</th>
                <th class="text-end">Own</th>
                <th>Membership</th>
                <th></th>
            </tr></thead>
            <tbody>
            @forelse($rows as $row)
                @php $membership = $report->membershipLabel($row); @endphp
                <tr>
                    <td class="fw-semibold" style="font-family:var(--bs-font-monospace,monospace);">
                        {{ $row->external_user_id }}
                        <div class="small text-muted">{{ $row->partnerCompany?->name }}</div>
                    </td>
                    <td>
                        {{ $row->name }}
                        @if($row->email)<div class="small text-muted">{{ $row->email }}</div>@endif
                        @if($row->account_status === \App\Models\User::ACCOUNT_MERGED)
                            {{-- The position was folded into an account they
                                 already had, so the sales on this row are that
                                 account's. Say whose, or the number looks like
                                 it belongs to a row that cannot log in. --}}
                            <div class="small">
                                <span class="badge bg-secondary">Merged</span>
                                <span class="text-muted">
                                    sales counted on {{ $row->mergedInto?->name ?? 'account #'.$row->merged_into_user_id }}
                                </span>
                            </div>
                        @endif
                    </td>
                    <td class="small text-muted">
                        {{ $row->claimed_at?->format('M j, Y') ?? '—' }}
                    </td>
                    <td class="text-end fw-semibold">{{ number_format($row->customer_orders) }}</td>
                    <td class="text-end fw-semibold">{{ $money($row->customer_revenue) }}</td>
                    <td class="text-end small text-muted">
                        @if($row->own_orders > 0)
                            {{ number_format($row->own_orders) }} &middot; {{ $money($row->own_revenue) }}
                        @else
                            &mdash;
                        @endif
                    </td>
                    <td>
                        @if($membership['tone'] === 'muted')
                            <span class="text-muted small">{{ $membership['label'] }}</span>
                        @else
                            <span class="badge bg-{{ $membership['tone'] }}">{{ $membership['label'] }}</span>
                        @endif
                    </td>
                    <td class="text-end">
                        <a href="{{ route('admin.users.show', $row->id) }}"
                           class="btn btn-sm btn-outline-secondary">View</a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="8" class="text-center text-muted py-4">
                    Nobody matching these filters has activated a position yet.
                </td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <div class="card-body">
        {{ $rows->links() }}
        <div class="small text-muted mt-2">
            Direct sales are confirmed vendor orders that count for this member, refunds excluded.
            A partner's own purchase counts as their sale but is shown separately under
            <strong>Own</strong> &mdash; the commission on one goes to their sponsor, not to them.
        </div>
    </div>
</div>

@endsection
