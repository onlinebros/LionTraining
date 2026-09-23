@extends('layouts.product-partner')

@section('title', 'Sales')
@section('page-title', 'Sales')

@php
    $fmt = fn ($m) => $m === null ? '—' : '$'.number_format(((int) $m) / 100, 2);
    $ctx = !empty($vendorSwitch) ? ['vendor' => $vendor] : [];
@endphp

@section('content')

<div class="card">
    <div class="card-header py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <h5 class="mb-0">Confirmed orders</h5>
            <span class="f-light small">
                Every order paid for through a Quantum 3 partner. You are the merchant of
                record on these — the customer detail is here so your team can fulfil and
                support them.
            </span>
        </div>
        <a class="btn btn-sm btn-outline-primary"
           href="{{ route('product-partner.sales.export', array_filter($ctx + request()->only('status', 'q', 'unshipped'))) }}">
            Export CSV
        </a>
    </div>

    <div class="card-body pb-0">
        <form method="GET" class="row g-2 align-items-end">
            @foreach ($ctx as $k => $v)<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endforeach
            <div class="col-md-4">
                <label class="form-label small f-light mb-1">Search</label>
                <input type="text" name="q" value="{{ $filters['q'] }}" class="form-control form-control-sm"
                       placeholder="Reference, name, email, company">
            </div>
            <div class="col-md-3">
                <label class="form-label small f-light mb-1">Status</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">Sold and refunded</option>
                    <option value="converted" @selected($filters['status'] === 'converted')>Sold</option>
                    <option value="refunded"  @selected($filters['status'] === 'refunded')>Refunded</option>
                </select>
            </div>
            <div class="col-md-3">
                <div class="form-check mb-2">
                    <input class="form-check-input" type="checkbox" name="unshipped" value="1"
                           id="unshipped" @checked($filters['unshipped'])>
                    <label class="form-check-label small" for="unshipped">
                        Not yet shipped ({{ $unshippedCount }})
                    </label>
                </div>
            </div>
            <div class="col-md-2">
                <button class="btn btn-sm btn-primary w-100 mb-2">Filter</button>
            </div>
        </form>
    </div>

    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>Reference</th><th>Confirmed</th><th>Customer</th><th>Ship to</th>
                    <th>Product</th><th class="text-end">Order total</th>
                    <th class="text-end">Owed to Q3</th><th>Fulfilment</th>
                </tr>
            </thead>
            <tbody>
            @forelse ($orders as $order)
                <tr>
                    <td>
                        <a href="{{ route('product-partner.sales.show', $order) }}"><code>{{ $order->public_ref }}</code></a>
                        @if ($order->status === 'refunded')
                            <span class="badge bg-danger ms-1">Refunded</span>
                        @endif
                        @if ($order->vendor_order_ref)
                            <div class="f-light small">{{ $order->vendor_order_ref }}</div>
                        @endif
                    </td>
                    <td class="f-light small">{{ optional($order->converted_at)->format('d M Y') }}</td>
                    <td>
                        {{ $order->fullName() }}
                        @if ($order->company)<div class="f-light small">{{ $order->company }}</div>@endif
                        <div class="f-light small">{{ $order->email }}</div>
                    </td>
                    <td class="f-light small">
                        {{ $order->city }}@if ($order->state), {{ $order->state }}@endif
                        <div>{{ $order->postal_code }}</div>
                    </td>
                    <td class="f-light small">{{ $order->quantity }} × {{ $order->productName() }}</td>
                    <td class="text-end">{{ $order->currency }} {{ $order->amountDecimal() }}</td>
                    <td class="text-end fw-bold">{{ $fmt($order->our_share_amount) }}</td>
                    <td>
                        @if ($order->shipped_at)
                            <span class="badge bg-success">Shipped</span>
                            <div class="f-light small">{{ $order->tracking_number }}</div>
                        @elseif ($order->status === 'converted')
                            <span class="badge bg-warning">To ship</span>
                        @else
                            <span class="f-light small">—</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="8" class="text-center f-light py-4">
                    No orders match. Prospects that have not been paid for are on the Pipeline screen.
                </td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    @if ($orders->hasPages())
        <div class="card-footer">{{ $orders->links() }}</div>
    @endif
</div>

@endsection
