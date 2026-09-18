@extends('layouts.admin')

@section('title', 'Partner Spots')
@section('page-title', 'Partner Spots')

@section('breadcrumb')
    <li class="breadcrumb-item active">Partner Spots</li>
@endsection

@section('content')

@if(session('success'))<div class="alert alert-success py-2">{{ session('success') }}</div>@endif
@if($errors->any())<div class="alert alert-danger py-2">{{ $errors->first() }}</div>@endif

@if(session('code_issued'))
    {{-- The one moment a code exists in readable form. It is not stored, not
         logged and not shown again — an admin reads it out on the call they are
         already on, and refreshing this page loses it. --}}
    <div class="alert alert-warning">
        <div class="fw-bold mb-1">New activation code for spot {{ session('code_issued')['spot'] }}</div>
        <div class="fs-4 fw-bold" style="letter-spacing:.14em;font-family:var(--bs-font-monospace,monospace);">
            {{ session('code_issued')['code'] }}
        </div>
        <div class="small mt-1">
            Shown once. It is stored hashed — nobody can read it back, including us.
            The previous code for this spot no longer works.
        </div>
    </div>
@endif

{{-- Unclaimed is the number this page exists for, so it is the one in gold. --}}
<div class="row g-3 mb-3">
    <div class="col-md-4">
        <div class="card mb-0"><div class="card-body py-4 text-center">
            <div class="q3-stat-value q3-stat-value--gold">{{ number_format($totals['unclaimed']) }}</div>
            <div class="q3-stat-label">Unclaimed — waiting on their owner</div>
        </div></div>
    </div>
    <div class="col-md-4">
        <div class="card mb-0"><div class="card-body py-4 text-center">
            <div class="q3-stat-value">{{ number_format($totals['claimed']) }}</div>
            <div class="q3-stat-label">Claimed &amp; activated</div>
        </div></div>
    </div>
    <div class="col-md-4">
        <div class="card mb-0"><div class="card-body py-4 text-center">
            <div class="q3-stat-value">{{ number_format($totals['total']) }}</div>
            <div class="q3-stat-label">Positions imported</div>
            <div class="text-muted small mt-1">
                {{ $totals['total'] > 0 ? round($totals['claimed'] / $totals['total'] * 100) : 0 }}% claimed
            </div>
        </div></div>
    </div>
</div>

@if($byCompany !== [])
<div class="card mb-3">
    <div class="card-header py-3"><h6 class="mb-0 fw-bold">By partner company</h6></div>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead><tr>
                <th>Company</th><th class="text-end">Unclaimed</th>
                <th class="text-end">Claimed</th><th class="text-end">Total</th>
                <th style="width:180px;">Progress</th><th>Claim link</th>
            </tr></thead>
            <tbody>
            @foreach($byCompany as $row)
                @php $claimed = $row['total'] - $row['unclaimed']; @endphp
                <tr>
                    <td class="fw-semibold">{{ $row['name'] }}</td>
                    <td class="text-end">{{ number_format($row['unclaimed']) }}</td>
                    <td class="text-end">{{ number_format($claimed) }}</td>
                    <td class="text-end">{{ number_format($row['total']) }}</td>
                    <td>
                        <div class="progress" style="height:6px;">
                            <div class="progress-bar bg-success"
                                 style="width: {{ $row['total'] > 0 ? ($claimed / $row['total'] * 100) : 0 }}%"></div>
                        </div>
                    </td>
                    <td class="small text-muted">/partner/{{ $row['slug'] }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>
@endif

<div class="card">
    <div class="card-header py-3">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label small mb-1">Company</label>
                <select name="company" class="form-select form-select-sm">
                    <option value="">All companies</option>
                    @foreach($companies as $company)
                        <option value="{{ $company->id }}" @selected($companyId === $company->id)>
                            {{ $company->name }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small mb-1">State</label>
                <select name="state" class="form-select form-select-sm">
                    <option value="unclaimed" @selected($state === 'unclaimed')>Unclaimed</option>
                    <option value="claimed"   @selected($state === 'claimed')>Claimed</option>
                    <option value="merged"    @selected($state === 'merged')>Merged into another account</option>
                    <option value="all"       @selected($state === 'all')>All</option>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label small mb-1">Search</label>
                <input type="search" name="q" value="{{ request('q') }}"
                       class="form-control form-control-sm" placeholder="Partner ID, or name/email once claimed">
            </div>
            <div class="col-md-2">
                <button class="btn btn-sm btn-primary w-100" type="submit">Filter</button>
            </div>
        </form>
    </div>

    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead><tr>
                <th>Partner ID</th><th>Company</th><th>Member</th>
                <th>Sits beneath</th><th>State</th><th>Imported</th><th></th>
            </tr></thead>
            <tbody>
            @forelse($spots as $spot)
                <tr>
                    <td class="fw-semibold" style="font-family:var(--bs-font-monospace,monospace);">
                        {{ $spot->external_user_id }}
                    </td>
                    <td class="small">{{ $spot->partnerCompany?->name }}</td>
                    <td>
                        {{-- Blank until somebody claims. We are not sent names or
                             email addresses with an import; the position's only
                             label before then is the partner's own ID. --}}
                        @if($spot->isHolding())
                            <span class="text-muted">Not claimed &mdash; no details held</span>
                        @else
                            {{ $spot->name }}
                            @if($spot->email)<div class="small text-muted">{{ $spot->email }}</div>@endif
                        @endif
                    </td>
                    <td class="small">
                        @if($spot->placementParent)
                            {{ $spot->placementParent->name }}
                            @if($spot->placementParent->isHolding())
                                <span class="badge bg-secondary">unclaimed</span>
                            @endif
                        @else
                            <span class="text-muted">&mdash;</span>
                        @endif
                    </td>
                    <td>
                        @if($spot->isHolding())
                            <span class="badge bg-warning text-dark">Unclaimed</span>
                            @if($spot->claim_locked_until?->isFuture())
                                <span class="badge bg-danger" title="Too many wrong codes">Locked</span>
                            @endif
                        @elseif($spot->isMerged())
                            <span class="badge bg-secondary">Merged</span>
                            <div class="small text-muted">{{ $spot->merged_at?->format('M j, Y') }}</div>
                        @else
                            <span class="badge bg-success">Claimed</span>
                            <div class="small text-muted">{{ $spot->claimed_at?->format('M j, Y') }}</div>
                        @endif
                    </td>
                    <td class="small text-muted">{{ $spot->imported_at?->format('M j, Y') }}</td>
                    <td class="text-end">
                        @if($spot->isHolding())
                            <form method="POST" action="{{ route('admin.partners.spots.reissue', $spot) }}"
                                  onsubmit="return confirm('Issue a new activation code for {{ $spot->external_user_id }}? The current code stops working immediately.');">
                                @csrf
                                <button class="btn btn-sm btn-outline-secondary" type="submit">New code</button>
                            </form>
                        @elseif($spot->isMerged())
                            <span class="small text-muted">
                                merged into #{{ $spot->merged_into_user_id }}
                            </span>
                        @else
                            <a href="{{ route('admin.users.show', $spot) }}" class="btn btn-sm btn-outline-secondary">View</a>

                            {{-- Folds this position and its whole downline into
                                 another account. The one action here that
                                 rearranges a live genealogy, so it asks. --}}
                            <form method="POST" action="{{ route('admin.partners.spots.merge', $spot) }}"
                                  class="d-flex gap-1 mt-1"
                                  onsubmit="return confirm('Merge {{ $spot->external_user_id }} and everyone below it into that account? This cannot be undone.');">
                                @csrf
                                <input type="text" name="into" class="form-control form-control-sm"
                                       placeholder="Merge into: email or user ID" style="min-width:190px;">
                                <button class="btn btn-sm btn-outline-warning" type="submit">Merge</button>
                            </form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="text-center text-muted py-4">
                    No spots match these filters.
                </td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <div class="card-body">{{ $spots->links() }}</div>
</div>

@endsection
