@extends('layouts.product-partner')

@section('title', 'Overview')
@section('page-title', 'Overview')

@php
    $fmt = fn ($m) => '$'.number_format(((int) $m) / 100, 2);
    $ctx = !empty($vendorSwitch) ? ['vendor' => $vendor] : [];
@endphp

@section('content')

{{-- The four numbers this portal exists to answer. Gold on the one the vendor
     came to look at. --}}
<div class="row g-3 mb-3">
    <div class="col-6 col-xl-3">
        <div class="card mb-0"><div class="card-body py-4 text-center">
            <div class="q3-stat-value q3-stat-value--gold">{{ number_format($stats['sales']['units']) }}</div>
            <div class="q3-stat-label">Systems sold</div>
            <div class="text-muted small mt-1">
                {{ number_format($stats['sales']['orders']) }} confirmed order(s)
            </div>
        </div></div>
    </div>

    <div class="col-6 col-xl-3">
        <div class="card mb-0"><div class="card-body py-4 text-center">
            <div class="q3-stat-value">{{ number_format($stats['prospects']['total']) }}</div>
            <div class="q3-stat-label">Prospects created</div>
            <div class="text-muted small mt-1">
                {{ number_format($stats['prospects']['open']) }} still open &middot;
                {{ number_format($stats['prospects']['last_30_days']) }} in 30 days
            </div>
        </div></div>
    </div>

    {{-- The size of the channel behind the product. --}}
    <div class="col-6 col-xl-3">
        <div class="card mb-0"><div class="card-body py-4 text-center">
            <div class="q3-stat-value">
                {{ $stats['sales_force']['active'] === null ? '—' : number_format($stats['sales_force']['active']) }}
            </div>
            <div class="q3-stat-label">Active partners on this line</div>
            <div class="text-muted small mt-1">
                @if ($stats['sales_force']['selling'] !== null)
                    {{ number_format($stats['sales_force']['selling']) }} have sourced a prospect
                @else
                    No business line is pointed at this product
                @endif
            </div>
        </div></div>
    </div>

    {{-- What is waiting on a decision at our end before a sale counts. Shown
         because it is the honest answer to "why is that order not on my
         statement yet", and it is ours to clear, not theirs. --}}
    <div class="col-6 col-xl-3">
        <div class="card mb-0"><div class="card-body py-4 text-center">
            <div class="q3-stat-value {{ $stats['approval_queue']['total'] > 0 ? 'text-warning' : '' }}">
                {{ number_format($stats['approval_queue']['total']) }}
            </div>
            <div class="q3-stat-label">In approval</div>
            <div class="text-muted small mt-1">
                @if ($stats['approval_queue']['total'] > 0)
                    {{ $stats['approval_queue']['attribution'] }} attribution &middot;
                    {{ $stats['approval_queue']['address'] }} address
                @else
                    Nothing waiting on a decision
                @endif
            </div>
        </div></div>
    </div>
</div>

<div class="row g-3">

    {{-- Money, summarised. Invoice-by-invoice detail is the Statement screen. --}}
    <div class="col-xl-4">
        <div class="card h-100">
            <div class="card-header py-3 d-flex justify-content-between align-items-center">
                <h5 class="mb-0">The account</h5>
                <a class="btn btn-sm btn-outline-primary" href="{{ route('product-partner.statement', $ctx) }}">Open statement</a>
            </div>
            <div class="card-body pt-2">
                <div class="d-flex justify-content-between py-2 border-bottom">
                    <span class="f-light">Earned by Quantum 3</span>
                    <span class="fw-bold">{{ $fmt($account['earned']) }}</span>
                </div>
                <div class="d-flex justify-content-between py-2 border-bottom">
                    <span class="f-light">Paid by {{ $vendorName }}</span>
                    <span>{{ $fmt($account['paid']) }}</span>
                </div>
                @if ($account['credits'] > 0)
                    <div class="d-flex justify-content-between py-2 border-bottom">
                        <span class="f-light">Credits (refunded after invoicing)</span>
                        <span>−{{ $fmt($account['credits']) }}</span>
                    </div>
                @endif
                <div class="d-flex justify-content-between py-3">
                    <span class="fw-bold">Outstanding</span>
                    <span class="q3-stat-value q3-stat-value--gold" style="font-size:1.4rem;">
                        {{ $fmt($account['outstanding']) }}
                    </span>
                </div>

                @if ($account['pending'] > 0)
                    <div class="alert alert-info py-2 mb-0 small">
                        {{ $fmt($account['pending']) }} in recorded payments is awaiting confirmation
                        by Quantum 3 and is not yet off the balance.
                    </div>
                @endif
            </div>
        </div>
    </div>

    {{-- Prospects and sales, day by day. A bar per day rather than a chart
         library: it is thirty numbers, and a 300 KB dependency to draw them
         would be the heaviest thing on the page. --}}
    <div class="col-xl-8">
        <div class="card h-100">
            <div class="card-header py-3">
                <h5 class="mb-0">Last 30 days</h5>
                <span class="f-light small">Prospects created, and the days a sale was confirmed.</span>
            </div>
            <div class="card-body">
                @php $peak = max(1, collect($activity)->max('prospects')); @endphp
                <div class="d-flex align-items-end gap-1" style="height:160px;">
                    @foreach ($activity as $day)
                        <div class="flex-fill d-flex flex-column justify-content-end align-items-center h-100"
                             title="{{ $day['label'] }} — {{ $day['prospects'] }} prospect(s), {{ $day['sales'] }} sale(s)">
                            @if ($day['sales'] > 0)
                                <span class="badge bg-warning mb-1" style="font-size:.6rem;">{{ $day['sales'] }}</span>
                            @endif
                            <div style="width:100%; border-radius:3px 3px 0 0;
                                        background:{{ $day['prospects'] > 0 ? 'var(--theme-default, #7366ff)' : 'rgba(255,255,255,.08)' }};
                                        height:{{ $day['prospects'] > 0 ? max(4, (int) round(($day['prospects'] / $peak) * 130)) : 3 }}px;"></div>
                        </div>
                    @endforeach
                </div>
                <div class="d-flex justify-content-between mt-2 f-light small">
                    <span>{{ $activity[0]['label'] ?? '' }}</span>
                    <span>{{ $activity[count($activity) - 1]['label'] ?? '' }}</span>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mt-0">
    <div class="col-xl-8">
        <div class="card">
            <div class="card-header py-3 d-flex justify-content-between align-items-center">
                <h5 class="mb-0">Latest sales</h5>
                <a class="btn btn-sm btn-outline-primary" href="{{ route('product-partner.sales', $ctx) }}">All sales</a>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Reference</th><th>Confirmed</th><th>Customer</th>
                            <th>Product</th><th class="text-end">Order total</th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse ($recent as $order)
                        <tr>
                            <td>
                                <a href="{{ route('product-partner.sales.show', $order) }}">
                                    <code>{{ $order->public_ref }}</code>
                                </a>
                            </td>
                            <td class="f-light small">{{ optional($order->converted_at)->format('d M Y') }}</td>
                            <td>
                                {{ $order->fullName() }}
                                @if ($order->company)<div class="f-light small">{{ $order->company }}</div>@endif
                            </td>
                            <td class="f-light small">{{ $order->quantity }} × {{ $order->productName() }}</td>
                            <td class="text-end">{{ $order->currency }} {{ $order->amountDecimal() }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-center f-light py-4">No confirmed sales yet.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-xl-4">
        <div class="card h-100">
            <div class="card-header py-3"><h5 class="mb-0">Pipeline</h5></div>
            <div class="card-body pt-2">
                @foreach ([
                    ['New — captured, not yet at checkout', $stats['prospects']['new']],
                    ['At checkout', $stats['prospects']['handed_off']],
                    ['Converted', $stats['prospects']['converted']],
                    ['Lost', $stats['prospects']['lost']],
                    ['Refunded', $stats['prospects']['refunded']],
                ] as [$label, $count])
                    <div class="d-flex justify-content-between py-2 border-bottom">
                        <span class="f-light">{{ $label }}</span>
                        <span class="fw-bold">{{ number_format($count) }}</span>
                    </div>
                @endforeach
                <div class="d-flex justify-content-between py-2">
                    <span class="f-light">Conversion rate</span>
                    <span class="fw-bold">{{ $stats['prospects']['conversion_rate'] }}%</span>
                </div>
                <a class="btn btn-sm btn-outline-primary w-100 mt-2"
                   href="{{ route('product-partner.prospects', $ctx) }}">Pipeline detail</a>
            </div>
        </div>
    </div>
</div>

@endsection
