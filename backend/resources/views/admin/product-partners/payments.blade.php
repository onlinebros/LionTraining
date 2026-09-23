@extends('layouts.admin')

@section('title', 'Vendor Payments')
@section('page-title', 'Vendor Payments')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('admin.product-partners.index') }}">Product Partners</a></li>
    <li class="breadcrumb-item active">Payments</li>
@endsection

@php $fmt = fn ($m) => '$'.number_format(((int) $m) / 100, 2); @endphp

@section('content')

@if(session('success'))<div class="alert alert-success py-2">{{ session('success') }}</div>@endif
@if($errors->any())<div class="alert alert-danger py-2">{{ $errors->first() }}</div>@endif

<div class="row g-3 mb-3">
    <div class="col-md-3">
        <div class="card mb-0"><div class="card-body py-4 text-center">
            <div class="q3-stat-value q3-stat-value--gold">{{ $fmt($totals['outstanding']) }}</div>
            <div class="q3-stat-label">Outstanding</div>
        </div></div>
    </div>
    <div class="col-md-3">
        <div class="card mb-0"><div class="card-body py-4 text-center">
            <div class="q3-stat-value">{{ $fmt($totals['earned']) }}</div>
            <div class="q3-stat-label">Earned</div>
        </div></div>
    </div>
    <div class="col-md-3">
        <div class="card mb-0"><div class="card-body py-4 text-center">
            <div class="q3-stat-value">{{ $fmt($totals['paid']) }}</div>
            <div class="q3-stat-label">Confirmed paid</div>
        </div></div>
    </div>
    <div class="col-md-3">
        <div class="card mb-0"><div class="card-body py-4 text-center">
            <div class="q3-stat-value {{ $totals['pending'] > 0 ? 'text-warning' : '' }}">{{ $fmt($totals['pending']) }}</div>
            <div class="q3-stat-label">Claimed, unconfirmed</div>
        </div></div>
    </div>
</div>

<div class="card">
    <div class="card-header py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <h5 class="mb-0">Payments recorded by {{ \App\Support\Vendors::name($vendor) }}</h5>
            <span class="text-muted small">
                Check each one against the bank before confirming. Confirming a payment that names an
                invoice settles every order on that invoice in the same step.
            </span>
        </div>
        <form method="GET">
            <select name="vendor" class="form-select form-select-sm" onchange="this.form.submit()">
                @foreach ($vendors as $slug => $config)
                    <option value="{{ $slug }}" @selected($vendor === $slug)>{{ $config['name'] ?? $slug }}</option>
                @endforeach
            </select>
        </form>
    </div>

    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>Paid</th><th class="text-end">Amount</th><th>Method</th>
                    <th>Reference</th><th>Against</th><th>Recorded by</th><th>Status</th><th></th>
                </tr>
            </thead>
            <tbody>
            @forelse ($payments as $payment)
                <tr>
                    <td class="text-muted small">{{ $payment->paid_on?->format('d M Y') }}</td>
                    <td class="text-end fw-bold">${{ $payment->amountDecimal() }}</td>
                    <td class="text-muted small">{{ $payment->method ? Str::headline($payment->method) : '—' }}</td>
                    <td class="text-muted small">{{ $payment->reference ?: '—' }}</td>
                    <td class="text-muted small">
                        @if ($payment->invoice_reference)
                            <code>{{ $payment->invoice_reference }}</code>
                        @else
                            On account
                        @endif
                    </td>
                    <td class="text-muted small">
                        {{ $payment->recordedBy?->name ?? '—' }}
                        <div>{{ $payment->created_at?->format('d M Y') }}</div>
                    </td>
                    <td>
                        @if ($payment->isConfirmed())
                            <span class="badge bg-success">Confirmed</span>
                            <div class="text-muted small">
                                {{ $payment->confirmedBy?->name }} · {{ $payment->confirmed_at?->format('d M Y') }}
                            </div>
                        @elseif ($payment->status === 'rejected')
                            <span class="badge bg-danger">Rejected</span>
                            <div class="text-muted small">{{ $payment->decision_note }}</div>
                        @else
                            <span class="badge bg-warning">Pending</span>
                        @endif
                    </td>
                    <td class="text-end" style="min-width:230px;">
                        @if ($payment->isPending() && auth()->user()->isSuperAdmin())
                            <form method="POST" action="{{ route('admin.product-partners.payments.confirm', $payment) }}"
                                  class="d-flex gap-1 mb-1">
                                @csrf
                                <input type="text" name="decision_note" class="form-control form-control-sm"
                                       placeholder="Note (optional)">
                                <button class="btn btn-sm btn-success">Confirm</button>
                            </form>
                            <form method="POST" action="{{ route('admin.product-partners.payments.reject', $payment) }}"
                                  class="d-flex gap-1">
                                @csrf
                                <input type="text" name="decision_note" class="form-control form-control-sm"
                                       placeholder="Reason — the vendor sees this" required>
                                <button class="btn btn-sm btn-outline-danger">Reject</button>
                            </form>
                        @elseif ($payment->isPending())
                            <span class="text-muted small">Super admin only</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="8" class="text-center text-muted py-4">
                    No payments recorded for this vendor.
                </td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    @if ($payments->hasPages())
        <div class="card-footer">{{ $payments->links() }}</div>
    @endif
</div>

@endsection
