@extends('layouts.member')

@section('title', 'My Team')
@section('page-title', 'My Team')

@section('breadcrumb')
    <li class="breadcrumb-item active">My Team</li>
@endsection

@push('styles')
<style>
    /* Genealogy tree — all colours come from the Q3 tokens in q3-theme.css. */
    .qtree-stats { display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:12px; }
    .qtree-stat-card { background:var(--q3-surface); border:1px solid var(--q3-border);
        border-radius:var(--q3-radius); padding:16px; text-align:center; }
    .qtree-stat-card .v { font-size:1.7rem; font-weight:650; line-height:1; color:var(--q3-text);
        font-variant-numeric:tabular-nums; }
    .qtree-stat-card .v-gold { color:var(--q3-gold-high); }
    .qtree-stat-card .l { font-size:.72rem; color:var(--q3-text-muted); margin-top:6px;
        letter-spacing:.06em; text-transform:uppercase; }

    .qtree-levels { display:flex; flex-wrap:wrap; gap:8px; }
    .qtree-level { background:var(--q3-surface-2); border:1px solid var(--q3-border);
        border-radius:var(--q3-radius-sm); padding:7px 13px; font-size:.83rem; color:var(--q3-text-muted); }
    .qtree-level strong { color:var(--q3-gold-high); }

    .qtree, .qtree-children { list-style:none; margin:0; padding:0; }
    .qtree-children { padding-left:26px; border-left:1px dashed var(--q3-border-strong); margin-left:13px; }
    .qtree-item { margin:5px 0; }
    .qtree-row { display:flex; align-items:flex-start; gap:7px; }

    .qtree-toggle { flex-shrink:0; width:20px; height:20px; margin-top:12px; padding:0;
        border:1px solid var(--q3-border-strong); background:var(--q3-surface-2); border-radius:4px;
        cursor:pointer; font-size:.7rem; line-height:1; color:var(--q3-text-muted);
        transition:transform .15s, border-color var(--q3-transition), color var(--q3-transition); }
    .qtree-toggle:hover { border-color:var(--q3-gold-soft); color:var(--q3-gold-high); }
    .qtree-toggle.is-collapsed { transform:rotate(-90deg); }
    .qtree-toggle-leaf { border:none; background:none; cursor:default; }

    .qtree-card { position:relative; flex:1; background:var(--q3-surface); border:1px solid var(--q3-border);
        border-left:3px solid var(--q3-surface-3); border-radius:var(--q3-radius-sm);
        padding:11px 40px 11px 13px; }
    .qtree-card-root { border-left-color:var(--q3-gold); background:
        linear-gradient(100deg, rgba(var(--q3-gold-rgb),.07) 0%, transparent 55%), var(--q3-surface); }
    .qtree-card-active { border-left-color:var(--q3-success); }
    .qtree-card-inactive { border-left-color:var(--q3-text-dim); opacity:.6; }

    .qtree-card-main { display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
    .qtree-avatar { width:29px; height:29px; border-radius:50%; background:var(--q3-gold-tint-2);
        border:1px solid var(--q3-border-gold); color:var(--q3-gold-high);
        display:flex; align-items:center; justify-content:center;
        font-weight:700; font-size:.79rem; flex-shrink:0; }
    .qtree-name { font-weight:600; color:var(--q3-text); }
    .qtree-badge { font-size:.64rem; padding:.22em .5em; }

    .qtree-meta { display:flex; flex-wrap:wrap; gap:13px; margin-top:6px; font-size:.79rem; color:var(--q3-text-muted); }
    .qtree-stat strong { color:var(--q3-text); }
    .qtree-stat-muted { color:var(--q3-text-dim); }

    .qtree-contact { margin-top:5px; font-size:.79rem; }
    .qtree-contact a { color:var(--q3-gold-soft); }
    .qtree-contact a:hover { color:var(--q3-gold-high); }
    .qtree-sep { margin:0 6px; color:var(--q3-text-dim); }

    .qtree-focus { position:absolute; top:10px; right:11px; width:25px; height:25px;
        display:flex; align-items:center; justify-content:center; border-radius:5px;
        color:var(--q3-text-dim); text-decoration:none; font-size:1rem;
        transition:background var(--q3-transition), color var(--q3-transition); }
    .qtree-focus:hover { background:var(--q3-gold-tint); color:var(--q3-gold-high); }

    .qtree-more { font-size:.83rem; color:var(--q3-gold-soft); padding:7px 0; display:inline-block; }
    .qtree-empty { text-align:center; padding:38px 20px; color:var(--q3-text-muted); }
</style>
@endpush

@section('content')

{{-- ── Your position ──────────────────────────────────────── --}}
<div class="qtree-stats mb-3">
    <div class="qtree-stat-card">
        <div class="v v-gold">{{ number_format($directs) }}</div>
        <div class="l">Personally enrolled</div>
    </div>
    <div class="qtree-stat-card">
        <div class="v">{{ number_format($teamSize) }}</div>
        <div class="l">Total team</div>
    </div>
    <div class="qtree-stat-card">
        <div class="v">{{ $depth }}</div>
        <div class="l">Your depth</div>
    </div>
    <div class="qtree-stat-card">
        <div class="v">{{ $user->placed_at ? $user->placed_at->format('M j') : '—' }}</div>
        <div class="l">Placed</div>
    </div>
</div>

{{-- ── Holding spots ──────────────────────────────────────── --}}
{{-- Counted here, listed elsewhere. The tree below is people; an unclaimed
     imported position is a place in the structure with nobody in it, and
     mixing the two would make every number on this page mean two things. --}}
@if($spots['unclaimed'] > 0)
    <div class="card mb-3">
        <div class="card-body py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h6 class="mb-1 fw-bold">
                    {{ number_format($spots['unclaimed']) }} position{{ $spots['unclaimed'] === 1 ? '' : 's' }}
                    in your organisation not claimed yet
                </h6>
                <p class="mb-0 small text-muted">
                    Imported from a partner company. They hold their place in your downline and join
                    your team the moment their owner activates them.
                </p>
            </div>
            <a href="{{ route('member.network.spots') }}" class="btn btn-sm btn-outline-primary">
                View holding spots
            </a>
        </div>
    </div>
@endif

{{-- ── Upline ─────────────────────────────────────────────── --}}
@if($upline->isNotEmpty())
    <div class="card mb-3">
        <div class="card-body py-3">
            <h6 class="mb-2 fw-bold">Your upline</h6>
            <div class="d-flex flex-wrap align-items-center gap-2">
                @foreach($upline as $i => $ancestor)
                    <span class="badge {{ $i === 0 ? 'bg-primary' : 'bg-light text-dark' }}">
                        {{ $ancestor->name }}{{ $i === 0 ? ' (sponsor)' : '' }}
                    </span>
                    @if(! $loop->last)<i class="fa-solid fa-arrow-up text-muted small"></i>@endif
                @endforeach
            </div>
        </div>
    </div>
@endif

{{-- ── Team by level ──────────────────────────────────────── --}}
@if($byLevel->isNotEmpty())
    <div class="card mb-3">
        <div class="card-body py-3">
            <h6 class="mb-2 fw-bold">Team by level</h6>
            <div class="qtree-levels">
                @foreach($byLevel as $level => $count)
                    <div class="qtree-level">Level {{ $level }} &mdash; <strong>{{ number_format($count) }}</strong></div>
                @endforeach
            </div>
        </div>
    </div>
@endif

{{-- ── The tree ───────────────────────────────────────────── --}}
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2 py-3">
        <h6 class="mb-0 fw-bold">
            @if($isReRooted)
                Team below {{ $root->name }}
            @else
                Your organisation
            @endif
        </h6>

        <div class="d-flex align-items-center gap-2">
            @if($isReRooted)
                <a href="{{ route('member.network') }}" class="btn btn-sm btn-outline-secondary">
                    &larr; Back to my tree
                </a>
            @endif
            <input type="search" id="qtreeSearch" class="form-control form-control-sm"
                   placeholder="Find a partner…" style="max-width:210px;">
        </div>
    </div>

    <div class="card-body">
        @if($tree === null)
            <div class="qtree-empty">
                <p class="mb-1 fw-semibold">You have not been placed yet.</p>
                <p class="mb-0 small">Your position appears here as soon as placement runs.</p>
            </div>
        @elseif(empty($tree['children']))
            <div class="qtree-empty">
                <p class="mb-2 fw-semibold">Nobody in your team yet.</p>
                <p class="mb-3 small">Share your referral link and the people who join appear here immediately.</p>
                <a href="{{ route('member.referrals') }}" class="btn btn-primary btn-sm">Get my referral link</a>
            </div>
        @else
            <ul class="qtree">
                @include('member._tree-node', ['node' => $tree, 'isRoot' => true])
            </ul>
        @endif
    </div>
</div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    // Collapse / expand. Delegated, so nodes revealed by search still work.
    document.querySelectorAll('.qtree-toggle:not(.qtree-toggle-leaf)').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var children = btn.closest('.qtree-item').querySelector(':scope > .qtree-children');
            if (!children) return;
            var collapsed = btn.classList.toggle('is-collapsed');
            children.classList.toggle('d-none', collapsed);
            btn.setAttribute('aria-expanded', String(!collapsed));
        });
    });

    // Filter by name. Matching a node reveals its ancestors so it stays in
    // context rather than appearing detached from the tree.
    var search = document.getElementById('qtreeSearch');
    if (!search) return;

    search.addEventListener('input', function () {
        var term = search.value.trim().toLowerCase();
        var items = document.querySelectorAll('.qtree-item');

        if (term === '') {
            items.forEach(function (li) { li.classList.remove('d-none'); });
            return;
        }

        items.forEach(function (li) { li.classList.add('d-none'); });

        items.forEach(function (li) {
            if ((li.dataset.nodeName || '').indexOf(term) === -1) return;

            li.classList.remove('d-none');

            for (var el = li.parentElement; el; el = el.parentElement) {
                if (el.classList && el.classList.contains('qtree-item')) {
                    el.classList.remove('d-none');
                }
                if (el.classList && el.classList.contains('qtree-children')) {
                    el.classList.remove('d-none');
                }
            }
        });
    });
});
</script>
@endpush
