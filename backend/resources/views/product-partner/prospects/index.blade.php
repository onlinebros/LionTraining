@extends('layouts.product-partner')

@section('title', 'Pipeline')
@section('page-title', 'Pipeline')

@php $ctx = !empty($vendorSwitch) ? ['vendor' => $vendor] : []; @endphp

@section('content')

{{-- Said once, at the top, rather than left for the vendor to work out from an
     absence: this screen is counts by design, not a list that failed to load. --}}
<div class="alert alert-info py-2">
    Open prospects are shown as numbers. They are customers Quantum 3 partners are still
    working, and their details stay with the partner who found them. The moment an order is
    paid for, the full record appears on <a href="{{ route('product-partner.sales', $ctx) }}">Sales</a>.
</div>

<div class="row g-3 mb-3">
    @foreach ([
        ['Prospects created', $stats['prospects']['total'], 'All time'],
        ['Open right now', $stats['prospects']['open'], $stats['prospects']['new'].' new · '.$stats['prospects']['handed_off'].' at checkout'],
        ['Converted', $stats['prospects']['converted'], $stats['prospects']['conversion_rate'].'% of all prospects'],
        ['Median days to sale', $timeToSale === null ? '—' : $timeToSale, 'Capture to payment'],
    ] as [$label, $value, $sub])
        <div class="col-6 col-xl-3">
            <div class="card mb-0"><div class="card-body py-4 text-center">
                <div class="q3-stat-value">{{ is_numeric($value) ? number_format($value, is_float($value) ? 1 : 0) : $value }}</div>
                <div class="q3-stat-label">{{ $label }}</div>
                <div class="text-muted small mt-1">{{ $sub }}</div>
            </div></div>
        </div>
    @endforeach
</div>

<div class="row g-3">
    <div class="col-xl-7">
        <div class="card h-100">
            <div class="card-header py-3">
                <h5 class="mb-0">Twelve weeks</h5>
                <span class="f-light small">Prospects created, and systems sold, per week.</span>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Week of</th>
                            <th class="text-end">Prospects</th>
                            <th class="text-end">Orders</th>
                            <th class="text-end">Systems</th>
                        </tr>
                    </thead>
                    <tbody>
                    @foreach (array_reverse($weekly) as $week)
                        <tr>
                            <td class="f-light">{{ $week['label'] }}</td>
                            <td class="text-end">{{ $week['prospects'] ?: '—' }}</td>
                            <td class="text-end">{{ $week['sales'] ?: '—' }}</td>
                            <td class="text-end fw-bold">{{ $week['units'] ?: '—' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-xl-5">
        <div class="card">
            <div class="card-header py-3"><h5 class="mb-0">By product</h5></div>
            <div class="table-responsive">
                <table class="table mb-0">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th class="text-end">Prospects</th>
                            <th class="text-end">Open</th>
                            <th class="text-end">Sold</th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse ($byProduct as $product)
                        <tr>
                            <td>{{ $product['name'] }}</td>
                            <td class="text-end">{{ $product['total'] }}</td>
                            <td class="text-end">{{ $product['open'] }}</td>
                            <td class="text-end fw-bold">{{ $product['units'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="text-center f-light py-4">No prospects yet.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <div class="card-header py-3"><h5 class="mb-0">The channel</h5></div>
            <div class="card-body pt-2">
                <div class="d-flex justify-content-between py-2 border-bottom">
                    <span class="f-light">Active partners on this line</span>
                    <span class="fw-bold">
                        {{ $stats['sales_force']['active'] === null ? '—' : number_format($stats['sales_force']['active']) }}
                    </span>
                </div>
                <div class="d-flex justify-content-between py-2 border-bottom">
                    <span class="f-light">Have sourced a prospect</span>
                    <span class="fw-bold">
                        {{ $stats['sales_force']['selling'] === null ? '—' : number_format($stats['sales_force']['selling']) }}
                    </span>
                </div>
                <div class="d-flex justify-content-between py-2">
                    <span class="f-light">Sourced one in the last 30 days</span>
                    <span class="fw-bold">
                        {{ $stats['sales_force']['sourcing_30'] === null ? '—' : number_format($stats['sales_force']['sourcing_30']) }}
                    </span>
                </div>
            </div>
        </div>

        @if ($stats['approval_queue']['total'] > 0)
            <div class="card">
                <div class="card-header py-3"><h5 class="mb-0">In approval</h5></div>
                <div class="card-body pt-2">
                    {{-- Both of these are ours to clear. The screen says so, so
                         nobody at the vendor spends a week chasing it. --}}
                    <div class="d-flex justify-content-between py-2 border-bottom">
                        <span class="f-light">Awaiting attribution review</span>
                        <span class="fw-bold">{{ $stats['approval_queue']['attribution'] }}</span>
                    </div>
                    <div class="d-flex justify-content-between py-2">
                        <span class="f-light">Awaiting address check</span>
                        <span class="fw-bold">{{ $stats['approval_queue']['address'] }}</span>
                    </div>
                    <p class="f-light small mb-0 mt-3">
                        These are checks Quantum 3 runs before a sale is final. Nothing is needed
                        from your side.
                    </p>
                </div>
            </div>
        @endif
    </div>
</div>

@endsection
