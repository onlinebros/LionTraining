@extends('layouts.member')

@section('title', 'Billing')
@section('page-title', 'Billing')

@section('breadcrumb')
    <li class="breadcrumb-item active">Billing</li>
@endsection

@section('content')

@if(session('status'))
    <div class="alert alert-success py-2">{{ session('status') }}</div>
@endif
@if($errors->any())
    <div class="alert alert-danger py-2">{{ $errors->first() }}</div>
@endif

<div class="row g-3">

    {{-- ── Membership ─────────────────────────────────────── --}}
    <div class="col-lg-7">
        <div class="card mb-0 h-100">
            <div class="card-header py-3"><h6 class="mb-0 fw-bold">Membership</h6></div>
            <div class="card-body">

                @if($subscription)
                    @php
                        $badge = match($subscription->status) {
                            'active', 'trialing' => 'success',
                            'past_due'           => 'warning',
                            default              => 'secondary',
                        };
                    @endphp

                    <div class="d-flex align-items-center gap-2 mb-3">
                        <span class="badge bg-{{ $badge }}">{{ ucfirst(str_replace('_', ' ', $subscription->status)) }}</span>
                        @if($subscription->cancel_at_period_end)
                            <span class="badge bg-secondary">Ends at period close</span>
                        @endif
                        @if($subscription->is_prelaunch_trial)
                            <span class="badge bg-info">Pre-launch trial</span>
                        @endif
                    </div>

                    <dl class="row mb-0 small">
                        @if($subscription->amount)
                            <dt class="col-5 text-muted fw-normal">Price</dt>
                            <dd class="col-7">{{ strtoupper($subscription->currency) }} {{ number_format($subscription->amount / 100, 2) }}</dd>
                        @endif

                        @if($subscription->trial_ends_at)
                            <dt class="col-5 text-muted fw-normal">First charge</dt>
                            <dd class="col-7">{{ $subscription->trial_ends_at->format('j F Y') }}</dd>
                        @endif

                        @if($subscription->current_period_end)
                            <dt class="col-5 text-muted fw-normal">Current period ends</dt>
                            <dd class="col-7">{{ $subscription->current_period_end->format('j F Y') }}</dd>
                        @endif
                    </dl>

                    <div class="mt-4 d-flex gap-2 flex-wrap">
                        @if($subscription->cancel_at_period_end && ! $subscription->ended_at)
                            <form method="POST" action="{{ route('member.billing.resume') }}">
                                @csrf
                                <button class="btn btn-sm btn-primary">Resume membership</button>
                            </form>
                        @elseif(! $subscription->ended_at)
                            <form method="POST" action="{{ route('member.billing.cancel') }}"
                                  onsubmit="return confirm('Cancel at the end of the current period? You keep access until then.')">
                                @csrf
                                <button class="btn btn-sm btn-outline-danger">Cancel membership</button>
                            </form>
                        @else
                            <a href="{{ route('member.billing.start') }}" class="btn btn-sm btn-primary">Start a new membership</a>
                        @endif
                    </div>
                @else
                    <p class="text-muted mb-3">No membership yet.</p>
                    <a href="{{ route('member.billing.start') }}" class="btn btn-primary btn-sm">Activate membership</a>
                @endif
            </div>
        </div>
    </div>

    {{-- ── Cards ──────────────────────────────────────────── --}}
    <div class="col-lg-5">
        <div class="card mb-0 h-100">
            <div class="card-header py-3"><h6 class="mb-0 fw-bold">Payment methods</h6></div>
            <div class="card-body">
                @forelse($paymentMethods as $method)
                    <div class="d-flex align-items-center justify-content-between {{ ! $loop->last ? 'mb-3 pb-3 border-bottom' : '' }}">
                        <div>
                            <div class="fw-semibold">{{ $method->label() }}</div>
                            <small class="text-muted">
                                Expires {{ str_pad($method->exp_month, 2, '0', STR_PAD_LEFT) }}/{{ $method->exp_year }}
                                @if($method->isExpired())
                                    <span class="badge bg-danger ms-1">Expired</span>
                                @endif
                            </small>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            @if($method->is_default)<span class="badge bg-primary">Default</span>@endif
                            <form method="POST" action="{{ route('member.billing.card.remove', $method) }}"
                                  onsubmit="return confirm('Remove this card?')">
                                @csrf @method('DELETE')
                                <button class="btn btn-sm btn-link text-danger p-0">Remove</button>
                            </form>
                        </div>
                    </div>
                @empty
                    <p class="text-muted small mb-0">No cards on file.</p>
                @endforelse

                <a href="{{ route('member.billing.start') }}" class="btn btn-sm btn-outline-primary mt-3">Add a card</a>
            </div>
        </div>
    </div>

    {{-- ── Invoices ───────────────────────────────────────── --}}
    <div class="col-12">
        <div class="card mb-0">
            <div class="card-header py-3"><h6 class="mb-0 fw-bold">Invoices</h6></div>
            <div class="card-body p-0">
                @if(empty($invoices))
                    <p class="text-muted small p-3 mb-0">No invoices yet.</p>
                @else
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead><tr><th>Date</th><th>Number</th><th>Status</th><th class="text-end">Total</th><th></th></tr></thead>
                            <tbody>
                                @foreach($invoices as $invoice)
                                    <tr>
                                        <td class="small">{{ $invoice['created']->format('j M Y') }}</td>
                                        <td class="small text-muted">{{ $invoice['number'] ?? '—' }}</td>
                                        <td><span class="badge bg-{{ $invoice['status'] === 'paid' ? 'success' : 'secondary' }}">{{ ucfirst($invoice['status']) }}</span></td>
                                        <td class="text-end small">{{ strtoupper($invoice['currency']) }} {{ number_format($invoice['total'] / 100, 2) }}</td>
                                        <td class="text-end">
                                            @if($invoice['pdf'])
                                                <a href="{{ $invoice['pdf'] }}" class="small" target="_blank" rel="noopener">PDF</a>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>
    </div>

</div>
@endsection
