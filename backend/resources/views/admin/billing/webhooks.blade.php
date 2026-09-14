@extends('layouts.admin')

@section('title', 'Payment Webhooks')
@section('page-title', 'Payment Webhooks')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('admin.billing.subscriptions') }}">Billing</a></li>
    <li class="breadcrumb-item active">Webhooks</li>
@endsection

@section('content')

@if(session('status'))<div class="alert alert-success py-2">{{ session('status') }}</div>@endif
@if($errors->any())<div class="alert alert-danger py-2">{{ $errors->first() }}</div>@endif

<div class="row g-3 mb-3">
    <div class="col-md-3">
        <div class="card mb-0">
            <div class="card-body py-3 text-center">
                <div class="fs-4 fw-bold {{ $unprocessed > 0 ? 'text-danger' : '' }}">{{ $unprocessed }}</div>
                <div class="text-muted small">Unprocessed</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card mb-0">
            <div class="card-body py-3 text-center">
                <div class="fs-4 fw-bold {{ $invalidSigs > 0 ? 'text-warning' : '' }}">{{ $invalidSigs }}</div>
                {{-- Stored for inspection, never processed. A non-zero count is
                     either a misconfigured secret or someone probing it. --}}
                <div class="text-muted small">Failed signature</div>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card mb-0">
            <div class="card-body py-3">
                <form method="GET" class="d-flex gap-2 flex-wrap">
                    <select name="type" class="form-select form-select-sm" style="max-width:230px;">
                        <option value="">All types</option>
                        @foreach($types as $t)
                            <option value="{{ $t }}" @selected($filters['type'] === $t)>{{ $t }}</option>
                        @endforeach
                    </select>
                    <select name="state" class="form-select form-select-sm" style="max-width:160px;">
                        <option value="">Any state</option>
                        <option value="processed" @selected($filters['state'] === 'processed')>Processed</option>
                        <option value="unprocessed" @selected($filters['state'] === 'unprocessed')>Unprocessed</option>
                    </select>
                    <button class="btn btn-sm btn-primary">Filter</button>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm mb-0 align-middle">
                <thead>
                    <tr><th>Received</th><th>Type</th><th>Event ID</th><th>Endpoint</th><th>State</th><th>Attempts</th><th></th></tr>
                </thead>
                <tbody>
                @forelse($events as $event)
                    <tr>
                        <td class="small text-muted">{{ $event->received_at?->format('j M H:i') ?? '—' }}</td>
                        <td class="small"><code>{{ $event->type }}</code></td>
                        <td class="small text-muted" style="font-size:.72rem;">{{ $event->stripe_event_id }}</td>
                        <td class="small">
                            <span class="badge bg-light text-dark">{{ $event->endpoint }}</span>
                            @unless($event->signature_valid)
                                <span class="badge bg-danger ms-1">bad signature</span>
                            @endunless
                        </td>
                        <td>
                            <span class="badge bg-{{ $event->status === 'processed' ? 'success' : ($event->status === 'failed' ? 'danger' : 'secondary') }}">
                                {{ $event->status }}
                            </span>
                            @if($event->processing_error)
                                <div class="text-danger" style="font-size:.7rem;">{{ Str::limit($event->processing_error, 90) }}</div>
                            @endif
                        </td>
                        <td class="small">{{ $event->processing_attempts }}</td>
                        <td class="text-end">
                            @if($event->signature_valid)
                                <form method="POST" action="{{ route('admin.billing.webhooks.replay', $event) }}" class="d-inline">
                                    @csrf
                                    <button class="btn btn-sm btn-outline-secondary py-0">Replay</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-center text-muted py-4">No events.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if($events->hasPages())
        <div class="card-footer">{{ $events->links() }}</div>
    @endif
</div>
@endsection
