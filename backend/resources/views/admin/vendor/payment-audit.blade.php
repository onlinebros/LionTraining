@extends('layouts.admin')

@section('title', 'Vendor Payment Audit')
@section('page-title', 'Vendor Payment Audit')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('admin.vendor-leads.index') }}">Vendor Leads</a></li>
    <li class="breadcrumb-item active">Payment Audit</li>
@endsection

@section('content')

@if(session('status'))<div class="alert alert-success py-2">{{ session('status') }}</div>@endif
@if($errors->any())<div class="alert alert-danger py-2">{{ $errors->first() }}</div>@endif

@use('App\Models\VendorPaymentAudit', 'Audit')

@php
    $money = fn ($m) => $m === null ? '—' : '$'.number_format(((int) $m) / 100, 2);
    $vendorName = \App\Support\Vendors::name($vendor);
@endphp

<div class="card">
    <div class="card-body py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <h5 class="mb-1">
                {{ $vendorName }}'s Stripe
                <span class="badge bg-{{ $mode === 'live' ? 'danger' : 'secondary' }} ms-1">{{ strtoupper($mode) }}</span>
            </h5>
            <span class="text-muted small">
                Read with {{ $vendorName }}'s restricted key. Nothing here changes an order or touches their account.
                @if ($lastChecked)
                    Last checked {{ \Illuminate\Support\Carbon::parse($lastChecked)->diffForHumans() }}; re-checked hourly.
                @endif
            </span>
        </div>
        @if (! $skipReason)
            <form method="POST" action="{{ route('admin.vendor-leads.payment-audit.refresh') }}">
                @csrf
                <input type="hidden" name="vendor" value="{{ $vendor }}">
                <button class="btn btn-sm btn-primary">Check Stripe now</button>
            </form>
        @else
            <span class="badge bg-warning">Cannot audit: {{ $skipReason }}</span>
        @endif
    </div>
</div>

{{-- Where to look first: the verdicts that need a person, then everything. --}}
<div class="d-flex flex-wrap gap-2 mb-3">
    <a href="{{ route('admin.vendor-leads.payment-audit', ['vendor' => $vendor]) }}"
       class="btn btn-sm {{ ! $verdict ? 'btn-dark' : 'btn-outline-dark' }}">All <span class="ms-1">{{ $counts->sum() }}</span></a>
    <a href="{{ route('admin.vendor-leads.payment-audit', ['vendor' => $vendor, 'verdict' => 'attention']) }}"
       class="btn btn-sm {{ $verdict === 'attention' ? 'btn-dark' : 'btn-outline-dark' }}">Needs attention
        <span class="ms-1">{{ collect(Audit::NEEDS_ATTENTION)->sum(fn ($v) => $counts[$v] ?? 0) }}</span></a>
    @foreach (Audit::VERDICTS as $key => [$label, $badge])
        @if (($counts[$key] ?? 0) > 0)
            <a href="{{ route('admin.vendor-leads.payment-audit', ['vendor' => $vendor, 'verdict' => $key]) }}"
               class="btn btn-sm {{ $verdict === $key ? 'btn-'.$badge : 'btn-outline-'.$badge }}">{{ $label }}
                <span class="ms-1">{{ $counts[$key] }}</span></a>
        @endif
    @endforeach
</div>

@if (($counts[Audit::VERDICT_PAID_NOT_RECORDED] ?? 0) > 0)
    <div class="alert alert-danger">
        <strong>{{ $counts[Audit::VERDICT_PAID_NOT_RECORDED] }} order(s) were paid on Stripe but are not confirmed here.</strong>
        The webhook never reached us for these. Run <code>php artisan vendors:sync-events</code>
        (needs PLASMAGUARD_EVENT_SYNC_SINCE set), or confirm each by hand from its order page.
    </div>
@endif

<div class="card">
    <div class="card-header py-3">
        <h5 class="mb-0">Orders placed on {{ $vendorName }}'s Stripe</h5>
        <span class="text-muted small">Every order that reached the payment step in the last 30 days, and any still waiting.</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead>
                <tr>
                    <th>Order</th><th>Started</th><th>Buyer</th><th class="text-end">Amount</th>
                    <th>Method</th><th>Stripe says</th><th>Why</th><th class="text-center">Attempts</th>
                </tr>
            </thead>
            <tbody>
            @forelse ($audits as $audit)
                @php $lead = $audit->lead; @endphp
                <tr>
                    <td>
                        <a href="{{ route('admin.vendor-leads.show', $audit->vendor_lead_id) }}"><code>{{ $lead?->public_ref }}</code></a>
                        <div class="text-muted small">ours: {{ Str::headline((string) $lead?->status) }}</div>
                    </td>
                    <td class="text-muted small text-nowrap">{{ optional($audit->intent_created_at)->format('d M Y H:i') }}</td>
                    <td>
                        {{ $lead?->fullName() }}
                        <div class="text-muted small">
                            {{ $lead?->state }}
                            @if ($lead?->member) · via {{ $lead->member->name }} @endif
                        </div>
                    </td>
                    <td class="text-end">{{ $money($audit->intent_amount) }}</td>
                    <td>{{ $audit->methodLabel() ?? '—' }}</td>
                    <td>
                        <span class="badge bg-{{ $audit->badge() }}" title="{{ $audit->advice() }}">{{ $audit->label() }}</span>
                        <div class="text-muted small">{{ $audit->intent_status }}</div>
                    </td>
                    <td style="max-width:320px;">
                        @if ($audit->check_error)
                            <div class="text-danger small">Last read failed: {{ Str::limit($audit->check_error, 160) }}</div>
                        @endif
                        @if ($audit->reason())
                            <div class="small">{{ $audit->reason() }}</div>
                        @endif
                        @if ($audit->decline_code || $audit->failure_code)
                            <div class="small"><code>{{ $audit->decline_code ?: $audit->failure_code }}</code>
                                @if ($audit->risk_level && $audit->risk_level !== 'normal')
                                    <span class="badge bg-warning ms-1">risk {{ $audit->risk_level }}</span>
                                @endif
                            </div>
                        @endif
                        @if (! $audit->reason() && ! $audit->check_error)
                            <div class="text-muted small">{{ $audit->advice() }}</div>
                        @endif
                    </td>
                    <td class="text-center">
                        @php $attempts = $audit->attempts ?? []; @endphp
                        @if (count($attempts))
                            <button class="btn btn-sm btn-outline-secondary" type="button"
                                    data-bs-toggle="collapse" data-bs-target="#attempts-{{ $audit->id }}">{{ count($attempts) }}</button>
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </td>
                </tr>
                @if (count($attempts))
                    <tr class="collapse" id="attempts-{{ $audit->id }}">
                        <td colspan="8" class="bg-light">
                            <ol class="mb-0 small">
                                @foreach ($attempts as $a)
                                    <li>
                                        <span class="text-muted">{{ \Illuminate\Support\Carbon::createFromTimestamp($a['at'] ?? 0)->format('d M H:i:s') }}</span>
                                        — <strong>{{ str_replace('_', ' ', $a['type'] ?? '?') }}</strong>
                                        @if (! empty($a['method'])) · {{ Audit::methodName($a['method']) }} @endif
                                        @if (! empty($a['decline']) || ! empty($a['code'])) · <code>{{ $a['decline'] ?? $a['code'] }}</code> @endif
                                        @if (! empty($a['message'])) · {{ $a['message'] }} @endif
                                        @if (! empty($a['action'])) · waiting on {{ str_replace('_', ' ', $a['action']) }} @endif
                                        @if (! empty($a['reason'])) · {{ str_replace('_', ' ', $a['reason']) }} @endif
                                    </li>
                                @endforeach
                            </ol>
                        </td>
                    </tr>
                @endif
            @empty
                <tr><td colspan="8" class="text-center text-muted py-4">
                    Nothing audited yet. Press <em>Check Stripe now</em>, or wait for the hourly run.
                </td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @if ($audits->hasPages())
        <div class="card-footer">{{ $audits->links() }}</div>
    @endif
</div>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card h-100 mb-0">
            <div class="card-header py-3">
                <h5 class="mb-0">What our key can see</h5>
                <span class="text-muted small">
                    Each line is one read-only call made with {{ $vendorName }}'s key; only allowed or refused is kept.
                    @if ($access) Tested {{ \Illuminate\Support\Carbon::parse($access['checked_at'])->diffForHumans() }}. @endif
                </span>
            </div>
            <div class="card-body p-0">
                @if ($accessError)
                    <div class="alert alert-danger m-3">The key could not be tested: {{ $accessError }}</div>
                @elseif (! $access)
                    <p class="text-muted m-3">No key is installed for this vendor on this server.</p>
                @else
                    <table class="table mb-0">
                        @foreach ($access['probes'] as $probe)
                            <tr>
                                <td class="text-nowrap">
                                    @if ($probe['result'] === 'allowed')
                                        <span class="badge bg-success">Allowed</span>
                                    @elseif ($probe['result'] === 'denied')
                                        <span class="badge bg-secondary">Not granted</span>
                                    @else
                                        <span class="badge bg-danger">Error</span>
                                    @endif
                                </td>
                                <td>
                                    <strong>{{ $probe['label'] }}</strong>
                                    <div class="text-muted small">{{ $probe['why'] }}</div>
                                    @if ($probe['detail'])
                                        <div class="small">{{ $probe['result'] === 'denied' ? 'Would need: ' : '' }}{{ Str::limit($probe['detail'], 200) }}</div>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </table>

                    @if (is_array($access['webhooks']))
                        <div class="p-3 border-top">
                            <h6 class="mb-2">Their webhook endpoint(s) pointing at us</h6>
                            @forelse ($access['webhooks'] as $hook)
                                <div class="small mb-2">
                                    <span class="badge bg-{{ $hook['status'] === 'enabled' ? 'success' : 'danger' }}">{{ $hook['status'] }}</span>
                                    <code>{{ $hook['id'] }}</code> → {{ $hook['url'] }}
                                    <div class="text-muted">API {{ $hook['api_version'] ?? 'default' }} · sends {{ implode(', ', $hook['events']) }}</div>
                                </div>
                            @empty
                                <div class="text-danger small">None found. Successful payments will only be picked up by recovery.</div>
                            @endforelse
                        </div>
                    @endif
                @endif
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card h-100 mb-0">
            <div class="card-header py-3">
                <h5 class="mb-0">Our side of the monitoring</h5>
                <span class="text-muted small">What would let a paid order go unnoticed on this server.</span>
            </div>
            <ul class="list-group list-group-flush">
                @foreach ($health as $check)
                    <li class="list-group-item">
                        <span class="badge bg-{{ $check['ok'] ? 'success' : 'warning' }} me-1">{{ $check['ok'] ? 'OK' : 'Check' }}</span>
                        <strong>{{ $check['label'] }}</strong>
                        <div class="text-muted small">{{ $check['detail'] }}</div>
                    </li>
                @endforeach
            </ul>
        </div>
    </div>
</div>

@endsection
