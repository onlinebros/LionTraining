@extends('layouts.product-partner')

@section('title', 'Statement')
@section('page-title', 'Statement')

@php
    $fmt = fn ($m) => '$'.number_format(((int) $m) / 100, 2);
    $ctx = !empty($vendorSwitch) ? ['vendor' => $vendor] : [];
@endphp

@section('content')

{{-- The whole point of the screen, said in one line: one copy of the account,
     visible to both companies. --}}
<div class="alert alert-info py-2">
    This is the account between {{ $vendorName }} and Quantum 3 Solution — the same figures
    both companies are looking at. Quantum 3's share is recorded on each order at the moment
    it is paid for, from your own order totals.
</div>

<div class="row g-3 mb-3">
    <div class="col-6 col-xl-3">
        <div class="card mb-0"><div class="card-body py-4 text-center">
            <div class="q3-stat-value">{{ $fmt($totals['earned']) }}</div>
            <div class="q3-stat-label">Earned by Quantum 3</div>
            <div class="text-muted small mt-1">
                {{ number_format($totals['orders']) }} order(s) · {{ number_format($totals['units']) }} system(s)
            </div>
        </div></div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="card mb-0"><div class="card-body py-4 text-center">
            <div class="q3-stat-value">{{ $fmt($totals['paid']) }}</div>
            <div class="q3-stat-label">Paid by {{ $vendorName }}</div>
            <div class="text-muted small mt-1">Confirmed payments only</div>
        </div></div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="card mb-0"><div class="card-body py-4 text-center">
            <div class="q3-stat-value q3-stat-value--gold">{{ $fmt($totals['outstanding']) }}</div>
            <div class="q3-stat-label">Outstanding</div>
            <div class="text-muted small mt-1">Earned − credits − paid</div>
        </div></div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="card mb-0"><div class="card-body py-4 text-center">
            <div class="q3-stat-value {{ $totals['pending'] > 0 ? 'text-warning' : '' }}">{{ $fmt($totals['pending']) }}</div>
            <div class="q3-stat-label">Awaiting confirmation</div>
            <div class="text-muted small mt-1">
                {{ $totals['pending_count'] }} recorded payment(s)
            </div>
        </div></div>
    </div>
</div>

<div class="row g-3">
    <div class="col-xl-7">

        <div class="card">
            <div class="card-header py-3">
                <h5 class="mb-0">Invoices</h5>
                <span class="f-light small">Raised by Quantum 3 against confirmed sales.</span>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Reference</th><th>Raised</th>
                            <th class="text-end">Orders</th><th class="text-end">Amount</th><th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse ($invoices as $invoice)
                        <tr>
                            <td><code>{{ $invoice->invoice_reference }}</code></td>
                            <td class="f-light small">
                                {{ \Illuminate\Support\Carbon::parse($invoice->invoiced_at)->format('d M Y') }}
                            </td>
                            <td class="text-end">{{ $invoice->orders }}</td>
                            <td class="text-end fw-bold">{{ $fmt($invoice->amount) }}</td>
                            <td>
                                {{-- Partially settled reads as unsettled: see
                                     PartnerStatement::invoices(). --}}
                                @if ((int) $invoice->unsettled === 0)
                                    <span class="badge bg-success">Settled</span>
                                    <div class="f-light small">
                                        {{ \Illuminate\Support\Carbon::parse($invoice->settled_at)->format('d M Y') }}
                                    </div>
                                @else
                                    <span class="badge bg-warning">Open</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-center f-light py-4">No invoices raised yet.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <div class="card-header py-3">
                <h5 class="mb-0">Earned, not yet invoiced</h5>
                <span class="f-light small">
                    Sales Quantum 3 has earned a share on and has not yet billed for.
                    Shown so the balance above never moves without warning.
                </span>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr><th>Reference</th><th>Sold</th><th>Product</th><th class="text-end">Owed to Q3</th></tr>
                    </thead>
                    <tbody>
                    @forelse ($uninvoiced as $order)
                        <tr>
                            <td><a href="{{ route('product-partner.sales.show', $order) }}"><code>{{ $order->public_ref }}</code></a></td>
                            <td class="f-light small">{{ optional($order->converted_at)->format('d M Y') }}</td>
                            <td class="f-light small">{{ $order->quantity }} × {{ $order->productName() }}</td>
                            <td class="text-end fw-bold">{{ $fmt($order->our_share_amount) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="text-center f-light py-4">
                            Everything earned has been invoiced.
                        </td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            @if ($uninvoiced->hasPages())
                <div class="card-footer">{{ $uninvoiced->links() }}</div>
            @endif
        </div>
    </div>

    <div class="col-xl-5">

        {{-- The vendor's side of the ledger. They can add to it; only Quantum 3
             can confirm it, which is why the form says what happens next. --}}
        <div class="card">
            <div class="card-header py-3"><h5 class="mb-0">Record a payment</h5></div>
            <div class="card-body">
                <form method="POST" action="{{ route('product-partner.statement.payments', $ctx) }}" class="row g-2">
                    @csrf
                    <div class="col-6">
                        <label class="form-label small f-light mb-1">Amount (USD)</label>
                        <input type="number" step="0.01" min="0.01" name="amount"
                               value="{{ old('amount') }}" class="form-control form-control-sm" required>
                    </div>
                    <div class="col-6">
                        <label class="form-label small f-light mb-1">Date paid</label>
                        <input type="date" name="paid_on" max="{{ now()->toDateString() }}"
                               value="{{ old('paid_on', now()->toDateString()) }}"
                               class="form-control form-control-sm" required>
                    </div>
                    <div class="col-6">
                        <label class="form-label small f-light mb-1">Method</label>
                        <select name="method" class="form-select form-select-sm">
                            <option value="">—</option>
                            @foreach (['wire' => 'Wire', 'ach' => 'ACH', 'check' => 'Check', 'card' => 'Card', 'other' => 'Other'] as $k => $label)
                                <option value="{{ $k }}" @selected(old('method') === $k)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label small f-light mb-1">Your reference</label>
                        <input type="text" name="reference" value="{{ old('reference') }}"
                               class="form-control form-control-sm" placeholder="Wire confirmation">
                    </div>
                    <div class="col-12">
                        <label class="form-label small f-light mb-1">Against invoice</label>
                        <select name="invoice_reference" class="form-select form-select-sm">
                            <option value="">On account — no specific invoice</option>
                            @foreach ($openInvoices as $reference)
                                <option value="{{ $reference }}" @selected(old('invoice_reference') === $reference)>
                                    {{ $reference }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label small f-light mb-1">Note</label>
                        <textarea name="note" rows="2" class="form-control form-control-sm">{{ old('note') }}</textarea>
                    </div>
                    <div class="col-12">
                        <button class="btn btn-sm btn-primary w-100">Record payment</button>
                        <p class="f-light small mb-0 mt-2">
                            This goes on the record straight away and shows as pending. It comes off the
                            outstanding balance once Quantum 3 confirms it against the bank.
                        </p>
                    </div>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header py-3"><h5 class="mb-0">Payments</h5></div>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr><th>Paid</th><th class="text-end">Amount</th><th>Against</th><th>Status</th></tr>
                    </thead>
                    <tbody>
                    @forelse ($payments as $payment)
                        <tr>
                            <td class="f-light small">
                                {{ $payment->paid_on?->format('d M Y') }}
                                @if ($payment->reference)<div>{{ $payment->reference }}</div>@endif
                            </td>
                            <td class="text-end fw-bold">${{ $payment->amountDecimal() }}</td>
                            <td class="f-light small">{{ $payment->invoice_reference ?: 'On account' }}</td>
                            <td>
                                @if ($payment->isConfirmed())
                                    <span class="badge bg-success">Confirmed</span>
                                @elseif ($payment->status === 'rejected')
                                    <span class="badge bg-danger">Not accepted</span>
                                    @if ($payment->decision_note)
                                        <div class="f-light small">{{ $payment->decision_note }}</div>
                                    @endif
                                @else
                                    <span class="badge bg-warning">Pending</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="text-center f-light py-4">No payments recorded yet.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

@endsection
