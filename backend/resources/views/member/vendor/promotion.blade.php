@extends('layouts.member')

@section('title', $standings['promotion']['name'] ?? 'Promotion')
@section('page-title', $standings['promotion']['name'] ?? 'Promotion')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('member.sales.index') }}">Product Sales</a></li>
    <li class="breadcrumb-item active">Promotion</li>
@endsection

@section('content')

@if (! $standings)
    <div class="card"><div class="card-body text-center text-muted py-5">
        There is no promotion running right now.
    </div></div>
@else
    @php
        $mine     = collect($standings['entries'])->filter(fn ($e) => (int) $e['credited_id'] === (int) $user->id);
        $byUnits  = $standings['counts'] === 'units';
        $unit     = $byUnits ? 'system' : 'order';
        $percent  = (int) floor($standings['filled'] / $standings['cap'] * 100);
        $terms    = $standings['terms'];
        $pool     = $standings['pool'];
        $usd      = static fn (float $amount): string => '$'.number_format($amount, 2);
    @endphp

    <div class="card mb-3">
        <div class="card-body">
            @if ($buyYours)
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 pb-3 border-bottom">
                    <span>
                        <strong>{{ $buyYours['remaining'] }} left for the launch special.</strong>
                        Only the first {{ $buyYours['cap'] }} systems qualify, and a system you buy for yourself counts.
                    </span>
                    <a class="btn btn-primary" href="{{ $buyYours['buy_url'] }}">Buy yours</a>
                </div>
            @endif
            @if (filled($standings['promotion']['summary'] ?? null))
                <p class="mb-3">{{ $standings['promotion']['summary'] }}</p>
            @endif

            <div class="row g-3 text-center mb-3">
                <div class="col-4">
                    <div class="q3-stat-value">{{ $standings['filled'] }}</div>
                    <div class="q3-stat-label">{{ Str::plural(ucfirst($unit)) }} Sold</div>
                </div>
                <div class="col-4">
                    <div class="q3-stat-value q3-stat-value--gold">{{ $standings['remaining'] }}</div>
                    <div class="q3-stat-label">Places Left</div>
                </div>
                <div class="col-4">
                    <div class="q3-stat-value">{{ $mine->sum('units') }}</div>
                    <div class="q3-stat-label">Yours</div>
                </div>
            </div>

            <div class="progress" style="height:10px;" role="progressbar" aria-label="Places filled"
                 aria-valuenow="{{ $standings['filled'] }}" aria-valuemin="0" aria-valuemax="{{ $standings['cap'] }}">
                <div class="progress-bar" style="width: {{ $percent }}%"></div>
            </div>
            <p class="text-muted small mt-2 mb-0">
                @if ($standings['full'])
                    All {{ $standings['cap'] }} places are filled.
                @else
                    {{ $standings['filled'] }} of {{ $standings['cap'] }} sold ·
                    {{ $standings['remaining'] }} {{ Str::plural('place', $standings['remaining']) }} left.
                @endif
                @if ($standings['starts_label'])
                    Counting sales confirmed from {{ $standings['starts_label'] }}.
                @endif
            </p>
        </div>
    </div>

    {{-- ── The viewer's own launch-special earnings ─────────────────────── --}}
    @if ($terms && $earnings)
        <div class="card mb-3">
            <div class="card-header py-3">
                <h5 class="mb-0">Your launch special earnings</h5>
                <span class="text-muted small">Credited as each sale is confirmed, and paid with your commissions.</span>
            </div>
            <div class="card-body">
                <div class="row g-3 text-center">
                    <div class="col-6 col-lg-3">
                        <div class="q3-stat-value">{{ $earnings['places'] }}</div>
                        <div class="q3-stat-label">Your places (pool shares)</div>
                    </div>
                    <div class="col-6 col-lg-3">
                        <div class="q3-stat-value">{{ $usd($earnings['place']) }}</div>
                        <div class="q3-stat-label">Place bonuses</div>
                    </div>
                    <div class="col-6 col-lg-3">
                        <div class="q3-stat-value">{{ $usd($earnings['pool']) }}</div>
                        <div class="q3-stat-label">Pool earnings</div>
                    </div>
                    <div class="col-6 col-lg-3">
                        <div class="q3-stat-value q3-stat-value--gold">{{ $usd($earnings['total']) }}</div>
                        <div class="q3-stat-label">Total</div>
                    </div>
                </div>

                @if ($pool)
                    <hr class="my-3">
                    <div class="d-flex justify-content-between small mb-1">
                        <span>Bonus pool: sales {{ $standings['cap'] + 1 }}–{{ $standings['cap'] + $pool['units'] }}</span>
                        <span>{{ $pool['filled'] }} of {{ $pool['units'] }} sold</span>
                    </div>
                    <div class="progress" style="height:8px;" role="progressbar" aria-label="Bonus pool sales"
                         aria-valuenow="{{ $pool['filled'] }}" aria-valuemin="0" aria-valuemax="{{ $pool['units'] }}">
                        <div class="progress-bar" style="width: {{ $pool['percent'] }}%"></div>
                    </div>
                    <p class="text-muted small mt-2 mb-0">
                        Each of those sales adds {{ $usd($terms['pool_per_unit']) }} to the pool,
                        {{ $usd($terms['per_share']) }} for every place you hold.
                        @if ($pool['remaining'] === 0) The pool is complete. @endif
                    </p>
                @endif
            </div>
        </div>
    @endif

    <div class="row g-3">
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header py-3"><h5 class="mb-0">Leaderboard</h5></div>
                <div class="table-responsive">
                    <table class="table mb-0">
                        <thead><tr><th style="width:3rem;">#</th><th>Partner</th><th class="text-end">{{ Str::plural(ucfirst($unit)) }}</th></tr></thead>
                        <tbody>
                        @forelse ($standings['leaderboard'] as $rank => $row)
                            <tr @class(['table-active' => (int) $row['user_id'] === (int) $user->id])>
                                <td>{{ $rank + 1 }}</td>
                                <td>
                                    {{ $row['name'] }}
                                    @if ((int) $row['user_id'] === (int) $user->id)
                                        <span class="badge bg-primary ms-1">You</span>
                                    @endif
                                </td>
                                <td class="text-end fw-semibold">{{ $row['units'] }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="text-center text-muted py-4">No qualifying sales yet.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card mb-3">
                <div class="card-header py-3"><h5 class="mb-0">Your qualifying sales</h5></div>
                <div class="table-responsive">
                    <table class="table mb-0">
                        <thead><tr><th>Place</th><th>Reference</th><th>Sold</th><th>Type</th></tr></thead>
                        <tbody>
                        @forelse ($mine as $entry)
                            <tr>
                                <td class="text-nowrap">
                                    #{{ $entry['from'] }}@if ($entry['to'] > $entry['from'])–{{ $entry['to'] }}@endif
                                </td>
                                <td><code>{{ $entry['lead']->public_ref }}</code></td>
                                <td class="small text-nowrap">{{ optional($entry['lead']->converted_at)->format('d M Y') }}</td>
                                <td>
                                    @if ($entry['lead']->isOwnPurchase())
                                        <span class="badge bg-info">Own purchase</span>
                                    @else
                                        <span class="badge bg-secondary">Customer sale</span>
                                    @endif
                                    @if ($entry['lead']->attribution === 'review')
                                        <span class="badge bg-warning ms-1">Being checked</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="text-center text-muted py-4">
                                None yet. Share your link from <a href="{{ route('member.sales.index') }}">Product Sales</a>.
                            </td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card">
                <div class="card-header py-3"><h5 class="mb-0">How the special works</h5></div>
                <div class="card-body small">
                    <ul class="mb-0 ps-3">
                        <li class="mb-2">
                            A {{ $unit }} counts once {{ $standings['vendor_name'] }} confirms payment.
                            @if ($byUnits) An order for three systems fills three places. @endif
                        </li>
                        <li class="mb-2">
                            A sale through your share link counts for you. So does a system you buy for yourself,
                            but the sale commission on your own purchase goes to your sponsor.
                        </li>
                        @if ($terms)
                            <li class="mb-2">
                                Each of the first {{ $standings['cap'] }} places earns a {{ $usd($terms['place_amount']) }} bonus
                                for the partner it counts for, on top of the sale commission.
                            </li>
                            @if ($terms['pool_units'] > 0)
                                <li class="mb-2">
                                    The next {{ number_format($terms['pool_units']) }} systems sold each add {{ $usd($terms['pool_per_unit']) }}
                                    to a pool of up to {{ $usd($terms['pool_total']) }}, shared one share per place. After that the special ends.
                                </li>
                            @endif
                        @endif
                        <li class="mb-2">
                            Places go in the order payments were confirmed. If a sale is refunded within
                            {{ $terms['lock_days'] ?? 60 }} days, it drops out, its bonus is cancelled, and the next sale moves up.
                            After that, the place is final.
                        </li>
                        <li>If an order fills the last place, only the places left are counted.</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
@endif

@endsection
