@extends('layouts.admin')

@section('title', $standings['promotion']['name'] ?? 'Promotion')
@section('page-title', $standings['promotion']['name'] ?? 'Promotion')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('admin.vendor-leads.index') }}">Vendor Leads</a></li>
    <li class="breadcrumb-item active">Promotion</li>
@endsection

@section('content')

@if (! $standings)
    <div class="card"><div class="card-body text-center text-muted py-5">
        No promotion is running. Promotions are set in <code>config/promotions.php</code>.
    </div></div>
@else
    @php
        $attributionLabels = [
            'customer' => ['Customer sale', 'secondary'],
            'self'     => ['Own purchase', 'info'],
            'review'   => ['Needs review', 'warning'],
        ];
        $unitLabel = $standings['counts'] === 'units' ? 'Systems' : 'Orders';
        $held  = collect($standings['entries'])->filter(fn ($e) => $e['lead']->attribution === 'review')->count();
        $terms = $standings['terms'];
        $pool  = $standings['pool'];
        $usd   = static fn (float $amount): string => '$'.number_format($amount, 2);
    @endphp

    <div class="row g-3 mb-3">
        <div class="col-6 col-md-3">
            <div class="card mb-0"><div class="card-body py-3 text-center">
                <div class="fs-4 fw-bold">{{ $standings['filled'] }} / {{ $standings['cap'] }}</div>
                <div class="text-muted small">{{ $unitLabel }} counted</div>
            </div></div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card mb-0"><div class="card-body py-3 text-center">
                <div class="fs-4 fw-bold text-success">{{ $standings['remaining'] }}</div>
                <div class="text-muted small">Places left</div>
            </div></div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card mb-0"><div class="card-body py-3 text-center">
                <div class="fs-4 fw-bold">{{ count($standings['leaderboard']) }}</div>
                <div class="text-muted small">Partners placed</div>
            </div></div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card mb-0"><div class="card-body py-3 text-center">
                {{-- Counted provisionally for the link owner until decided, and
                     earning nothing until then. --}}
                <div class="fs-4 fw-bold {{ $held > 0 ? 'text-warning' : '' }}">{{ $held }}</div>
                <div class="text-muted small">Awaiting attribution review</div>
            </div></div>
        </div>
    </div>

    {{-- ── The special's money ──────────────────────────────────────────── --}}
    @if ($terms && $overview)
        <div class="card mb-3">
            <div class="card-header py-3">
                <h5 class="mb-0">Launch special credits</h5>
                <span class="text-muted small">
                    {{ $usd($terms['place_amount']) }} per place, plus {{ $usd($terms['pool_per_unit']) }} from each of the next
                    {{ number_format($terms['pool_units']) }} systems shared one share per place. Pending credits in the commission ledger.
                </span>
            </div>
            <div class="card-body">
                <div class="row g-3 text-center">
                    <div class="col-6 col-lg-3">
                        <div class="fs-4 fw-bold">{{ $usd($overview['place_total']) }}</div>
                        <div class="text-muted small">Place bonuses ({{ $overview['place_count'] }})</div>
                    </div>
                    <div class="col-6 col-lg-3">
                        <div class="fs-4 fw-bold">{{ $usd($overview['pool_total']) }}</div>
                        <div class="text-muted small">Pool credited</div>
                    </div>
                    <div class="col-6 col-lg-3">
                        <div class="fs-4 fw-bold">{{ $pool ? $pool['filled'].' / '.$pool['units'] : '—' }}</div>
                        <div class="text-muted small">Pool sales</div>
                    </div>
                    <div class="col-6 col-lg-3">
                        <div class="fs-4 fw-bold {{ $overview['needs_clawback']->isNotEmpty() ? 'text-danger' : '' }}">
                            {{ $overview['needs_clawback']->count() }}
                        </div>
                        <div class="text-muted small">Need a clawback</div>
                    </div>
                </div>
            </div>

            @if ($overview['needs_clawback']->isNotEmpty())
                {{-- Credits the standings no longer support that were already
                     approved or paid, so they were not voided automatically. --}}
                <div class="table-responsive border-top">
                    <table class="table mb-0">
                        <thead><tr><th>Partner</th><th>Credit</th><th>Order</th><th>Ledger</th><th>Why</th></tr></thead>
                        <tbody>
                        @foreach ($overview['needs_clawback'] as $award)
                            <tr>
                                <td>{{ $award->earner?->name ?? '—' }}</td>
                                <td>{{ $usd((float) $award->amount) }} {{ $award->kind === 'place' ? 'place bonus' : 'pool share' }}</td>
                                <td>
                                    @if ($award->lead)
                                        <a href="{{ route('admin.vendor-leads.show', $award->lead->id) }}"><code>{{ $award->lead->public_ref }}</code></a>
                                    @endif
                                </td>
                                <td>#{{ $award->commission_ledger_id }} · {{ $award->ledger?->status }}</td>
                                <td class="small text-muted">
                                    The standings changed after this credit was {{ $award->ledger?->status }}.
                                    Raise a clawback from the <a href="{{ route('admin.commission-ledger.index') }}">commission ledger</a>.
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    @endif

    <div class="row g-3">
        <div class="col-xl-8">
            <div class="card">
                <div class="card-header py-3">
                    <h5 class="mb-0">Qualifying orders</h5>
                    <span class="text-muted small">
                        In the order {{ $standings['vendor_name'] }} confirmed payment
                        @if ($standings['starts_label']), counting from {{ $standings['starts_label'] }}@endif.
                        Orders refunded within {{ $terms['lock_days'] ?? 60 }} days drop out on their own.
                    </span>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Place</th><th>Reference</th><th>Confirmed</th><th>Buyer</th>
                                <th>Share link</th><th>Counts for</th><th>Type</th><th class="text-end">Counted</th>
                            </tr>
                        </thead>
                        <tbody>
                        @forelse ($standings['entries'] as $entry)
                            @php
                                $lead = $entry['lead'];
                                [$label, $color] = $attributionLabels[$lead->attribution] ?? $attributionLabels['customer'];
                            @endphp
                            <tr>
                                <td class="text-nowrap">#{{ $entry['from'] }}@if ($entry['to'] > $entry['from'])–{{ $entry['to'] }}@endif</td>
                                <td><a href="{{ route('admin.vendor-leads.show', $lead->id) }}"><code>{{ $lead->public_ref }}</code></a></td>
                                <td class="small text-nowrap">
                                    {{ optional($lead->converted_at)->format('d M Y H:i') }}
                                    @if ($lead->status === 'refunded') <span class="badge bg-danger">Refunded after lock</span> @endif
                                </td>
                                <td>
                                    {{ $lead->fullName() }}
                                    <div class="text-muted small">{{ $lead->email }}</div>
                                </td>
                                <td>{{ $lead->member?->name ?? $lead->referral_code }}</td>
                                <td>{{ $entry['credited_name'] }}</td>
                                <td><span class="badge bg-{{ $color }}">{{ $label }}</span></td>
                                <td class="text-end text-nowrap">
                                    {{ $entry['units'] }}
                                    @if ($entry['partial'])<span class="text-muted small">of {{ $lead->quantity }}</span>@endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="text-center text-muted py-4">No qualifying sales yet.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-xl-4">
            <div class="card">
                <div class="card-header py-3"><h5 class="mb-0">Leaderboard</h5></div>
                <div class="table-responsive">
                    <table class="table mb-0">
                        <thead>
                            <tr>
                                <th>#</th><th>Partner</th><th class="text-end">{{ $unitLabel }}</th>
                                @if ($terms)<th class="text-end">Credited</th>@endif
                            </tr>
                        </thead>
                        <tbody>
                        @forelse ($standings['leaderboard'] as $rank => $row)
                            <tr>
                                <td>{{ $rank + 1 }}</td>
                                <td><a href="{{ route('admin.users.show', $row['user_id']) }}">{{ $row['name'] }}</a></td>
                                <td class="text-end fw-semibold">{{ $row['units'] }}</td>
                                @if ($terms)
                                    <td class="text-end">{{ $usd($earnings[$row['user_id']]['total'] ?? 0) }}</td>
                                @endif
                            </tr>
                        @empty
                            <tr><td colspan="4" class="text-center text-muted py-4">No one yet.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
@endif

@endsection
