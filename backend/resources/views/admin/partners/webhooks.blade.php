@extends('layouts.admin')

@section('title', 'Partner Webhooks')
@section('page-title', 'Partner Webhooks')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('admin.partners.spots') }}">Partner Spots</a></li>
    <li class="breadcrumb-item active">Webhooks</li>
@endsection

@section('content')

@if(session('success'))<div class="alert alert-success py-2">{{ session('success') }}</div>@endif
@if($errors->any())<div class="alert alert-danger py-2">{{ $errors->first() }}</div>@endif

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <p class="text-muted mb-0" style="max-width:680px;">
        A signed <code>spot.claimed</code> event goes to each partner's endpoint when one of their
        people claims a position. Everything we sent, and everything they said back, is here.
    </p>
    <div class="d-flex gap-2">
        <a href="{{ route('admin.partners.webhooks.guide') }}" class="btn btn-sm btn-outline-secondary">
            Integration guide
        </a>
        <a href="{{ route('admin.partners.webhooks.troubleshooting') }}" class="btn btn-sm btn-outline-secondary">
            Signature troubleshooting
        </a>
    </div>
</div>

@if($failing > 0)
    <div class="alert alert-warning">
        <strong>{{ number_format($failing) }}</strong> event(s) were never accepted. Each one is a
        claim a partner does not know about — open it, check what their endpoint said, and replay
        once they have fixed it.
    </div>
@endif

<div class="card">
    <div class="card-header py-3">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-4">
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
            <div class="col-md-4">
                <label class="form-label small mb-1">Status</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">Any</option>
                    <option value="delivered" @selected($status === 'delivered')>Delivered</option>
                    <option value="pending"   @selected($status === 'pending')>Pending</option>
                    <option value="failed"    @selected($status === 'failed')>Failed</option>
                </select>
            </div>
            <div class="col-md-2">
                <button class="btn btn-sm btn-primary w-100" type="submit">Filter</button>
            </div>
        </form>
    </div>

    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead><tr>
                <th>Event</th><th>Company</th><th>Spot</th>
                <th>Status</th><th>Attempts</th><th>When</th><th></th>
            </tr></thead>
            <tbody>
            @forelse($deliveries as $delivery)
                <tr>
                    <td>
                        <div class="fw-semibold">{{ $delivery->event_type }}</div>
                        <div class="small text-muted" style="font-family:var(--bs-font-monospace,monospace);">
                            {{ Str::limit($delivery->event_id, 18) }}
                        </div>
                    </td>
                    <td class="small">{{ $delivery->company?->name }}</td>
                    <td class="small" style="font-family:var(--bs-font-monospace,monospace);">
                        {{ $delivery->external_user_id ?: '—' }}
                    </td>
                    <td>
                        @switch($delivery->status)
                            @case(\App\Models\PartnerWebhookDelivery::STATUS_DELIVERED)
                                <span class="badge bg-success">Delivered</span>
                                @break
                            @case(\App\Models\PartnerWebhookDelivery::STATUS_FAILED)
                                <span class="badge bg-danger">Failed</span>
                                @break
                            @default
                                <span class="badge bg-secondary">Pending</span>
                        @endswitch
                        @if($delivery->response_status)
                            <div class="small text-muted">HTTP {{ $delivery->response_status }}</div>
                        @endif
                    </td>
                    <td class="small text-muted">{{ $delivery->attempts }}</td>
                    <td class="small text-muted">{{ $delivery->created_at->format('M j, g:ia') }}</td>
                    <td class="text-end">
                        <a href="{{ route('admin.partners.webhooks.show', $delivery) }}"
                           class="btn btn-sm btn-outline-secondary">Open</a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="text-center text-muted py-4">
                    Nothing sent yet. Events appear here as spots get claimed.
                </td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <div class="card-body">{{ $deliveries->links() }}</div>
</div>

@endsection
