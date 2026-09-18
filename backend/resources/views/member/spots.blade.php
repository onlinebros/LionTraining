@extends('layouts.member')

@section('title', 'Holding Spots')
@section('page-title', 'Holding Spots')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('member.network') }}">My Team</a></li>
    <li class="breadcrumb-item active">Holding Spots</li>
@endsection

@push('styles')
<style>
    .qspot-stats { display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:12px; }
    .qspot-stat { background:var(--q3-surface); border:1px solid var(--q3-border);
        border-radius:var(--q3-radius); padding:16px; text-align:center; }
    .qspot-stat .v { font-size:1.7rem; font-weight:650; line-height:1; color:var(--q3-text);
        font-variant-numeric:tabular-nums; }
    .qspot-stat .v-gold { color:var(--q3-gold-high); }
    .qspot-stat .l { font-size:.72rem; color:var(--q3-text-muted); margin-top:6px;
        letter-spacing:.06em; text-transform:uppercase; }
    .qspot-id { font-family:var(--bs-font-monospace,monospace); letter-spacing:.04em; }
    .qspot-empty { text-align:center; padding:38px 20px; color:var(--q3-text-muted); }
</style>
@endpush

@section('content')

<div class="qspot-stats mb-3">
    <div class="qspot-stat">
        <div class="v v-gold">{{ number_format($counts['unclaimed']) }}</div>
        <div class="l">Waiting to be claimed</div>
    </div>
    <div class="qspot-stat">
        <div class="v">{{ number_format($counts['claimed']) }}</div>
        <div class="l">Claimed &amp; active</div>
    </div>
    <div class="qspot-stat">
        <div class="v">{{ number_format($counts['total']) }}</div>
        <div class="l">Directly below you</div>
    </div>
</div>

<div class="card mb-3">
    <div class="card-body py-3">
        <h6 class="fw-bold mb-2">What these are</h6>
        <p class="text-muted mb-2 small">
            These positions came across from a partner company's organisation and sit
            <strong>directly beneath you</strong>. They hold their place, but nobody has activated
            them yet — until somebody enters their ID and activation code, there is no account
            behind the position. We are not given names or contact details for them, which is why
            you see an ID and nothing else.
        </p>
        <p class="text-muted mb-2 small">
            This is your first level only, not your whole organisation. Everyone further down is
            being followed up by {{ $waiting->first()?->partnerCompany?->name ?? 'the partner company' }}
            from their own system — we tell them each time a position activates, and they know who
            still needs a hand.
        </p>
        <p class="text-muted mb-0 small">
            They do not appear on <a href="{{ route('member.network') }}">My Team</a> because your
            team numbers count people, and an unclaimed spot is not one yet. The moment it is
            claimed it appears in your tree at the level it has always held.
        </p>
    </div>
</div>

@if($recentlyClaimed->isNotEmpty())
<div class="card mb-3">
    <div class="card-header py-3"><h6 class="mb-0 fw-bold">Recently claimed</h6></div>
    <div class="card-body py-3">
        <div class="d-flex flex-wrap gap-2">
            @foreach($recentlyClaimed as $claimed)
                <span class="badge bg-success">
                    {{ $claimed->name }} &middot; {{ $claimed->claimed_at?->diffForHumans() }}
                </span>
            @endforeach
        </div>
    </div>
</div>
@endif

<div class="card">
    <div class="card-header py-3">
        <h6 class="mb-0 fw-bold">Still waiting ({{ number_format($counts['unclaimed']) }})</h6>
    </div>

    @if($waiting->isEmpty())
        <div class="qspot-empty">
            <p class="mb-1 fw-semibold">Nothing waiting on your first level.</p>
            <p class="mb-0 small">
                Every imported position directly beneath you has been activated.
            </p>
        </div>
    @else
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead><tr>
                    <th>Position</th><th>From</th><th>Held since</th>
                </tr></thead>
                <tbody>
                @foreach($waiting as $spot)
                    <tr>
                        {{-- The partner company's own identifier, which is all
                             there is: an import carries positions, never people.
                             Nobody here has a name or an email until they claim. --}}
                        <td class="qspot-id fw-semibold">{{ $spot->external_user_id }}</td>
                        <td class="small text-muted">{{ $spot->partnerCompany?->name }}</td>
                        <td class="small text-muted">{{ $spot->imported_at?->format('M j, Y') }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <div class="card-body">{{ $waiting->links() }}</div>
    @endif
</div>

@endsection
