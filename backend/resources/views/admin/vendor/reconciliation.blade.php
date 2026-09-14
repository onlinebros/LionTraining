@extends('layouts.admin')

@section('title', 'Vendor Reconciliation')
@section('page-title', 'Vendor Reconciliation')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('admin.vendor-leads.index') }}">Vendor Leads</a></li>
    <li class="breadcrumb-item active">Reconciliation</li>
@endsection

@section('content')

@if(session('status'))<div class="alert alert-success py-2">{{ session('status') }}</div>@endif
@if($errors->any())<div class="alert alert-danger py-2">{{ $errors->first() }}</div>@endif

@php $money = fn ($m) => '$'.number_format(((int) $m) / 100, 2); @endphp

{{-- Gold on the one number that matters: what has been earned and not yet
     billed. The other two are context. --}}
<div class="row g-3 mb-3">
    <div class="col-md-4">
        <div class="card mb-0"><div class="card-body py-4 text-center">
            <div class="q3-stat-value q3-stat-value--gold">{{ $money($totals['owed']) }}</div>
            <div class="q3-stat-label">Owed — not yet invoiced</div>
            <div class="text-muted small mt-1">{{ $totals['owed_n'] }} order(s)</div>
        </div></div>
    </div>
    <div class="col-md-4">
        <div class="card mb-0"><div class="card-body py-4 text-center">
            <div class="q3-stat-value">{{ $money($totals['invoiced']) }}</div>
            <div class="q3-stat-label">Invoiced — awaiting payment</div>
            <div class="text-muted small mt-1">{{ $totals['invoiced_n'] }} order(s)</div>
        </div></div>
    </div>
    <div class="col-md-4">
        <div class="card mb-0"><div class="card-body py-4 text-center">
            <div class="q3-stat-value">{{ $money($totals['settled']) }}</div>
            <div class="q3-stat-label">Settled</div>
            <div class="text-muted small mt-1">{{ $totals['settled_n'] }} order(s)</div>
        </div></div>
    </div>
</div>

<div class="card">
    <div class="card-header py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <h5 class="mb-0">Uninvoiced sales — {{ \App\Support\Vendors::name($vendor) }}</h5>
            <span class="text-muted small">
                Nothing is taken at the point of sale; this is what {{ \App\Support\Vendors::name($vendor) }}
                owes us and has not yet been billed for.
            </span>
        </div>
        <a class="btn btn-sm btn-outline-primary"
           href="{{ route('admin.vendor-leads.export', ['vendor' => $vendor, 'status' => 'converted']) }}">
            Export CSV
        </a>
    </div>

    <form method="POST" action="{{ route('admin.vendor-leads.invoice') }}">
        @csrf
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th style="width:36px;"><input type="checkbox" id="check-all" class="form-check-input"></th>
                        <th>Reference</th><th>Sold</th><th>Customer</th><th>Partner</th>
                        <th class="text-end">Order total</th><th class="text-end">Owed to us</th>
                    </tr>
                </thead>
                <tbody>
                @forelse ($orders as $order)
                    <tr>
                        <td><input type="checkbox" name="ids[]" value="{{ $order->id }}" class="form-check-input row-check"></td>
                        <td>
                            <a href="{{ route('admin.vendor-leads.show', $order->id) }}"><code>{{ $order->public_ref }}</code></a>
                            @if ($order->confirmed_via !== 'webhook')
                                {{-- A human asserted this sale rather than a signed
                                     webhook proving it. Worth seeing before billing. --}}
                                <span class="badge bg-warning ms-1">{{ Str::headline((string) $order->confirmed_via) }}</span>
                            @endif
                        </td>
                        <td class="text-muted small">{{ optional($order->converted_at)->format('d M Y') }}</td>
                        <td>
                            {{ $order->fullName() }}
                            <div class="text-muted small">{{ $order->quantity }} × {{ $order->productName() }}</div>
                        </td>
                        <td>
                            {{ $order->member?->name ?? '—' }}
                            <div class="text-muted small">{{ $order->referral_code }}</div>
                        </td>
                        <td class="text-end">{{ $order->currency }} {{ $order->amountDecimal() }}</td>
                        <td class="text-end fw-bold">{{ $money($order->our_share_amount) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-center text-muted py-4">
                        Nothing outstanding — every converted sale has been invoiced.
                    </td></tr>
                @endforelse
                </tbody>
            </table>
        </div>

        @if ($orders->count())
            <div class="card-footer">
                <div class="row g-2 align-items-end">
                    <div class="col-md-4">
                        <label class="form-label small text-muted mb-1">Invoice reference</label>
                        <input type="text" name="reference" class="form-control form-control-sm"
                               placeholder="e.g. Q3-PG-2026-09" required>
                    </div>
                    <div class="col-md-3">
                        <button class="btn btn-sm btn-primary w-100">Mark selected as invoiced</button>
                    </div>
                    <div class="col-md-5">
                        <span class="text-muted small">
                            Records that these lines are on an invoice. It does not send anything —
                            raise the invoice in your accounting system and put its number here.
                        </span>
                    </div>
                </div>
            </div>
        @endif
    </form>

    @if ($orders->hasPages())
        <div class="card-footer">{{ $orders->links() }}</div>
    @endif
</div>

{{-- Settling is by invoice reference rather than per order: the vendor pays an
     invoice, not a line, and reconciling line by line invites partial states
     nobody can explain. --}}
<div class="card mt-3">
    <div class="card-header py-3"><h5 class="mb-0">Record a payment</h5></div>
    <div class="card-body">
        <form method="POST" action="{{ route('admin.vendor-leads.settle') }}" class="row g-2 align-items-end">
            @csrf
            <div class="col-md-4">
                <label class="form-label small text-muted mb-1">Invoice reference</label>
                <input type="text" name="reference" class="form-control form-control-sm" required>
            </div>
            <div class="col-md-3">
                <button class="btn btn-sm btn-success w-100">Mark invoice settled</button>
            </div>
            <div class="col-md-5">
                <span class="text-muted small">Marks every order on that invoice as paid.</span>
            </div>
        </form>
    </div>
</div>

@endsection

@push('scripts')
<script>
    var all = document.getElementById('check-all');
    if (all) {
        all.addEventListener('change', function () {
            document.querySelectorAll('.row-check').forEach(function (c) { c.checked = all.checked; });
        });
    }
</script>
@endpush
