@extends('layouts.admin')

@section('title', 'Subscriptions')
@section('page-title', 'Subscriptions')

@section('breadcrumb')
    <li class="breadcrumb-item active">Billing</li>
@endsection

@section('content')

@if(session('status'))<div class="alert alert-success py-2">{{ session('status') }}</div>@endif
@if($errors->any())<div class="alert alert-danger py-2">{{ $errors->first() }}</div>@endif

{{-- These three numbers are the health of the billing integration. A rising
     stale or unprocessed count means webhooks are being missed, which is
     otherwise invisible until someone complains about their access. --}}
<div class="row g-3 mb-3">
    <div class="col-md-6">
        <div class="card mb-0">
            <div class="card-body py-3">
                <div class="text-muted small mb-2">By status</div>
                <div class="d-flex flex-wrap gap-2">
                    @forelse($byStatus as $status => $count)
                        <span class="badge bg-light text-dark">{{ str_replace('_', ' ', $status) }} — <strong>{{ $count }}</strong></span>
                    @empty
                        <span class="text-muted small">No subscriptions yet.</span>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card mb-0 h-100">
            <div class="card-body py-3 text-center">
                <div class="fs-4 fw-bold {{ $staleCount > 0 ? 'text-warning' : '' }}">{{ $staleCount }}</div>
                <div class="text-muted small">Not synced in 24h</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card mb-0 h-100">
            <div class="card-body py-3 text-center">
                <div class="fs-4 fw-bold {{ $unprocessed > 0 ? 'text-danger' : '' }}">{{ $unprocessed }}</div>
                <div class="text-muted small">
                    <a href="{{ route('admin.billing.webhooks', ['state' => 'unprocessed']) }}">Unprocessed events</a>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header py-3">
        <form method="GET" class="d-flex gap-2 flex-wrap">
            <input type="search" name="q" value="{{ $filters['q'] }}" class="form-control form-control-sm"
                   placeholder="Partner name or email" style="max-width:240px;">
            <select name="status" class="form-select form-select-sm" style="max-width:170px;">
                <option value="">All statuses</option>
                @foreach(['trialing','active','past_due','canceled','incomplete','unpaid'] as $s)
                    <option value="{{ $s }}" @selected($filters['status'] === $s)>{{ str_replace('_',' ',$s) }}</option>
                @endforeach
            </select>
            <button class="btn btn-sm btn-primary">Filter</button>
            @if($filters['q'] || $filters['status'])
                <a href="{{ route('admin.billing.subscriptions') }}" class="btn btn-sm btn-outline-secondary">Clear</a>
            @endif
        </form>
    </div>

    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm mb-0 align-middle">
                <thead>
                    <tr>
                        <th>Partner</th><th>Status</th><th>Trial ends</th>
                        <th>Period ends</th><th>Amount</th><th>Synced</th><th></th>
                    </tr>
                </thead>
                <tbody>
                @forelse($subscriptions as $sub)
                    <tr>
                        <td>
                            <div class="fw-semibold small">{{ $sub->user?->name ?? '—' }}</div>
                            <div class="text-muted" style="font-size:.75rem;">{{ $sub->user?->email }}</div>
                        </td>
                        <td>
                            <span class="badge bg-{{ in_array($sub->status, ['active','trialing']) ? 'success' : ($sub->status === 'past_due' ? 'warning' : 'secondary') }}">
                                {{ str_replace('_', ' ', $sub->status) }}
                            </span>
                            @if($sub->isCommissionHold())<span class="badge bg-info ms-1">waiting on commissions</span>
                            @elseif($sub->is_prelaunch_trial)<span class="badge bg-info ms-1">parked</span>@endif
                        </td>
                        <td class="small">{{ $sub->trial_ends_at?->format('j M Y') ?? '—' }}</td>
                        <td class="small">{{ $sub->current_period_end?->format('j M Y') ?? '—' }}</td>
                        <td class="small">{{ $sub->amount ? strtoupper($sub->currency).' '.number_format($sub->amount/100, 2) : '—' }}</td>
                        <td class="small {{ $sub->last_synced_at === null || $sub->last_synced_at->lt(now()->subDay()) ? 'text-warning' : 'text-muted' }}">
                            {{ $sub->last_synced_at?->diffForHumans() ?? 'never' }}
                        </td>
                        <td class="text-end text-nowrap">
                            @if($sub->isCommissionHold())
                                <form method="POST" action="{{ route('admin.billing.subscriptions.start-billing', $sub) }}" class="d-inline"
                                      onsubmit="return confirm('Start billing for this partner now? They will be charged when the training program opens, or today if it already has.')">
                                    @csrf
                                    <button class="btn btn-sm btn-outline-primary py-0">Start billing</button>
                                </form>
                            @endif
                            <form method="POST" action="{{ route('admin.billing.subscriptions.sync', $sub) }}" class="d-inline">
                                @csrf
                                <button class="btn btn-sm btn-outline-secondary py-0">Sync</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-center text-muted py-4">No subscriptions match.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if($subscriptions->hasPages())
        <div class="card-footer">{{ $subscriptions->links() }}</div>
    @endif
</div>
@endsection
