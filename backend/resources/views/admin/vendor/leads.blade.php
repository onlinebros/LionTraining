@extends('layouts.admin')

@section('title', 'Vendor Leads')
@section('page-title', 'Vendor Leads')

@section('breadcrumb')
    <li class="breadcrumb-item active">Vendor Leads</li>
@endsection

@section('content')

@if(session('status'))<div class="alert alert-success py-2">{{ session('status') }}</div>@endif
@if($errors->any())<div class="alert alert-danger py-2">{{ $errors->first() }}</div>@endif

@php
    $statusColors = [
        'new' => 'secondary', 'handed_off' => 'info',
        'converted' => 'success', 'refunded' => 'danger', 'lost' => 'dark',
    ];
@endphp

<div class="row g-3 mb-3">
    <div class="col-6 col-md">
        <div class="card mb-0"><div class="card-body py-3 text-center">
            <div class="fs-4 fw-bold">{{ $stats['awaiting'] }}</div>
            <div class="text-muted small">At Checkout</div>
        </div></div>
    </div>
    <div class="col-6 col-md">
        <div class="card mb-0"><div class="card-body py-3 text-center">
            <div class="fs-4 fw-bold text-success">{{ $stats['converted'] }}</div>
            <div class="text-muted small">Converted</div>
        </div></div>
    </div>
    <div class="col-6 col-md">
        <a class="card mb-0 text-reset text-decoration-none" href="{{ route('admin.vendor-leads.index', ['address' => 'review']) }}">
            <div class="card-body py-3 text-center">
                {{-- The buyer shipped to an address FedEx did not confirm. Check
                     it with them, or with PlasmaGuard, before it ships. --}}
                <div class="fs-4 fw-bold {{ $stats['address_review'] > 0 ? 'text-warning' : '' }}">{{ $stats['address_review'] }}</div>
                <div class="text-muted small">Address Checks</div>
            </div>
        </a>
    </div>
    <div class="col-6 col-md">
        <div class="card mb-0"><div class="card-body py-3 text-center">
            <div class="fs-4 fw-bold {{ $stats['unpaid'] > 0 ? 'text-warning' : '' }}">{{ $stats['unpaid'] }}</div>
            {{-- Converted but with no commission raised — the vendor sent no
                 amount, or the rate is unset. Non-zero means someone is owed
                 money the ledger does not know about. --}}
            <div class="text-muted small">Needs Reconciliation</div>
        </div></div>
    </div>
    <div class="col-6 col-md-3">
        <a class="card mb-0 text-reset text-decoration-none" href="{{ route('admin.vendor-leads.index', ['attribution' => 'review']) }}">
            <div class="card-body py-3 text-center">
                {{-- Details match a partner on address alone. Commission is
                     held until someone decides whose purchase it was. --}}
                <div class="fs-4 fw-bold {{ $stats['review'] > 0 ? 'text-warning' : '' }}">{{ $stats['review'] }}</div>
                <div class="text-muted small">Attribution Review</div>
            </div>
        </a>
    </div>
</div>

<div class="card">
    <div class="card-header py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h5 class="mb-0">Leads</h5>
        <a class="btn btn-sm btn-outline-primary"
           href="{{ route('admin.vendor-leads.export', request()->only(['vendor', 'status'])) }}">
            Export CSV for vendor
        </a>
    </div>

    <div class="card-body border-bottom py-3">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label small text-muted mb-1">Vendor</label>
                <select name="vendor" class="form-select form-select-sm">
                    <option value="">All</option>
                    @foreach ($vendors as $slug => $vendor)
                        <option value="{{ $slug }}" @selected(($filters['vendor'] ?? null) === $slug)>
                            {{ $vendor['name'] }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small text-muted mb-1">Status</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">All</option>
                    @foreach (['new', 'handed_off', 'converted', 'refunded', 'lost'] as $status)
                        <option value="{{ $status }}" @selected(($filters['status'] ?? null) === $status)>
                            {{ Str::headline($status) }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label small text-muted mb-1">Search</label>
                <input type="text" name="q" class="form-control form-control-sm"
                       value="{{ $filters['q'] ?? '' }}" placeholder="Reference, name or email">
            </div>
            <div class="col-md-2">
                <button class="btn btn-sm btn-primary w-100">Filter</button>
            </div>
        </form>
    </div>

    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>Reference</th><th>Customer</th><th>Partner</th>
                    <th>Status</th><th>Total</th><th>Confirmed</th><th>Captured</th><th></th>
                </tr>
            </thead>
            <tbody>
            @forelse ($leads as $lead)
                <tr>
                    <td><code>{{ $lead->public_ref }}</code></td>
                    <td>
                        {{ $lead->fullName() }}
                        <div class="text-muted small">{{ $lead->email }}</div>
                    </td>
                    <td>
                        {{ $lead->member?->name ?? '—' }}
                        <div class="text-muted small">{{ $lead->referral_code }}</div>
                    </td>
                    <td>
                        <span class="badge bg-{{ $statusColors[$lead->status] ?? 'secondary' }}">
                            {{ Str::headline($lead->status) }}
                        </span>
                        @if ($lead->needsAddressReview())
                            <span class="badge bg-warning ms-1" title="The buyer confirmed an address FedEx did not verify">Check address</span>
                        @endif
                    </td>
                    <td>{{ $lead->amountDecimal() ? $lead->currency.' '.$lead->amountDecimal() : '—' }}</td>
                    <td>
                        @if ($lead->confirmed_via)
                            {{-- A signed webhook and an admin's assertion are not
                                 equally trustworthy, and a dispute turns on which. --}}
                            <span class="badge bg-{{ $lead->confirmed_via === 'webhook' ? 'success' : 'warning' }}">
                                {{ Str::headline($lead->confirmed_via) }}
                            </span>
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </td>
                    <td class="text-muted small">{{ $lead->created_at->format('d M Y') }}</td>
                    <td class="text-end">
                        <a class="btn btn-sm btn-outline-primary"
                           href="{{ route('admin.vendor-leads.show', $lead->id) }}">View</a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="8" class="text-center text-muted py-4">No leads match these filters.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    @if ($leads->hasPages())
        <div class="card-footer">{{ $leads->links() }}</div>
    @endif
</div>

@endsection
