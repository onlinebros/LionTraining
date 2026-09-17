@extends('layouts.admin')

@section('title', 'Webhook ' . $delivery->event_type)
@section('page-title', 'Webhook delivery')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('admin.partners.webhooks.index') }}">Webhooks</a></li>
    <li class="breadcrumb-item active">{{ Str::limit($delivery->event_id, 18) }}</li>
@endsection

@push('styles')
<style>
    .q3-payload {
        background: var(--q3-surface-2); border: 1px solid var(--q3-border);
        border-radius: var(--q3-radius-sm); padding: 14px;
        font-family: var(--bs-font-monospace, monospace); font-size: .78rem;
        line-height: 1.55; white-space: pre-wrap; word-break: break-word;
        max-height: 460px; overflow: auto; margin: 0;
    }
</style>
@endpush

@section('content')

@if(session('success'))<div class="alert alert-success py-2">{{ session('success') }}</div>@endif

<div class="row g-3">
    <div class="col-lg-4">
        <div class="card mb-0 h-100">
            <div class="card-header py-3"><h6 class="mb-0 fw-bold">This event</h6></div>
            <div class="card-body">
                <dl class="row mb-0 small">
                    <dt class="col-5 text-muted">Type</dt>
                    <dd class="col-7">{{ $delivery->event_type }}</dd>

                    <dt class="col-5 text-muted">Event ID</dt>
                    <dd class="col-7" style="word-break:break-all;font-family:var(--bs-font-monospace,monospace);">
                        {{ $delivery->event_id }}
                    </dd>

                    <dt class="col-5 text-muted">Company</dt>
                    <dd class="col-7">{{ $delivery->company?->name }}</dd>

                    <dt class="col-5 text-muted">Endpoint</dt>
                    <dd class="col-7" style="word-break:break-all;">{{ $delivery->company?->webhook_url ?: '—' }}</dd>

                    <dt class="col-5 text-muted">Spot</dt>
                    <dd class="col-7">{{ $delivery->external_user_id ?: '—' }}</dd>

                    <dt class="col-5 text-muted">Status</dt>
                    <dd class="col-7">
                        @if($delivery->isDelivered())
                            <span class="badge bg-success">Delivered</span>
                        @elseif($delivery->status === \App\Models\PartnerWebhookDelivery::STATUS_FAILED)
                            <span class="badge bg-danger">Failed</span>
                        @else
                            <span class="badge bg-secondary">Pending</span>
                        @endif
                    </dd>

                    <dt class="col-5 text-muted">Attempts</dt>
                    <dd class="col-7">{{ $delivery->attempts }}</dd>

                    <dt class="col-5 text-muted">Created</dt>
                    <dd class="col-7">{{ $delivery->created_at->format('M j, Y g:i:sa') }}</dd>

                    <dt class="col-5 text-muted">Last attempt</dt>
                    <dd class="col-7">{{ $delivery->last_attempt_at?->format('M j, Y g:i:sa') ?: '—' }}</dd>

                    <dt class="col-5 text-muted">Delivered</dt>
                    <dd class="col-7">{{ $delivery->delivered_at?->format('M j, Y g:i:sa') ?: '—' }}</dd>
                </dl>

                <form method="POST" action="{{ route('admin.partners.webhooks.replay', $delivery) }}"
                      class="mt-3">
                    @csrf
                    <button class="btn btn-sm btn-outline-secondary" type="submit">Send it again</button>
                </form>
                <div class="form-text mt-1">
                    Same event ID, same bytes. A partner keying idempotency on the ID will recognise
                    a replay rather than counting it twice.
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-8">
        <div class="card mb-3">
            <div class="card-header py-3">
                <h6 class="mb-0 fw-bold">What we sent</h6>
            </div>
            <div class="card-body">
                <p class="text-muted small">
                    Exactly the bytes that were signed, POSTed as <code>application/json</code>. The
                    signature goes in <code>Q3-Signature: t=&lt;unix&gt;,v1=&lt;hmac&gt;</code>, which is
                    HMAC-SHA256 of <code>"{t}.{body}"</code> with the shared secret — the same scheme
                    Stripe uses, so their engineers already have the code that checks it.
                </p>
                <pre class="q3-payload">{{ $body }}</pre>
            </div>
        </div>

        <div class="card mb-0">
            <div class="card-header py-3"><h6 class="mb-0 fw-bold">What they said back</h6></div>
            <div class="card-body">
                @if($delivery->response_status)
                    <p class="mb-2">
                        <span class="badge {{ $delivery->isDelivered() ? 'bg-success' : 'bg-danger' }}">
                            HTTP {{ $delivery->response_status }}
                        </span>
                    </p>
                @endif

                @if($delivery->error)
                    <div class="alert alert-danger small">{{ $delivery->error }}</div>
                @endif

                @if(filled($delivery->response_body))
                    <pre class="q3-payload">{{ $delivery->response_body }}</pre>
                @elseif(! $delivery->response_status && ! $delivery->error)
                    <p class="text-muted mb-0">No attempt has been made yet.</p>
                @else
                    <p class="text-muted mb-0">Empty response body.</p>
                @endif
            </div>
        </div>
    </div>
</div>

@endsection
