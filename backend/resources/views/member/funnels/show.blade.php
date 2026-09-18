@extends('layouts.member')

@section('title', $funnel->title)

@push('styles')
<style>
    .stat-row { display:grid; grid-template-columns:repeat(auto-fit, minmax(140px,1fr)); gap:10px; margin-bottom:16px; }
    .stat { background:#fff; border:1px solid #e2e8f0; border-radius:10px; padding:13px 15px; }
    .stat__n { font-size:22px; font-weight:700; color:#0f172a; line-height:1.1; font-variant-numeric:tabular-nums; }
    .stat__l { font-size:11.5px; color:#64748b; text-transform:uppercase; letter-spacing:.06em; margin-top:3px; }

    .path { font-size:12px; color:#475569; }
    .tag { display:inline-block; border-radius:999px; padding:2px 9px; font-size:11px; font-weight:600; }
    .tag--won  { background:#dcfce7; color:#166534; }
    .tag--live { background:#fee2e2; color:#991b1b; }

    .bar { position:relative; background:#f1f5f9; border-radius:6px; height:22px; }
    .bar__fill { position:absolute; inset:0 auto 0 0; background:#dbeafe; border-radius:6px; }
    .bar__text {
        position:relative; font-size:12px; font-weight:600; color:#0f172a;
        line-height:22px; padding:0 8px; font-variant-numeric:tabular-nums;
    }
</style>
@endpush

@section('content')
<div class="container-fluid">
    <div class="page-title mb-3 d-flex flex-wrap gap-3 align-items-center justify-content-between">
        <div>
            <h3 class="mb-1">{{ $funnel->title }}</h3>
            <p class="text-muted mb-0">
                {{ $funnel->steps->count() }} {{ Str::plural('video', $funnel->steps->count()) }} ·
                only the people you invited appear here.
            </p>
        </div>
        <a href="{{ route('member.funnels.index') }}" class="btn btn-sm btn-outline-secondary">All flows</a>
    </div>

    <div class="card mb-3">
        <div class="card-body d-flex flex-wrap gap-2 align-items-end">
            <div style="flex:1;min-width:260px;">
                <label class="form-label mb-1" style="font-size:12.5px;">Your link</label>
                <input type="text" class="form-control form-control-sm" id="share-url" readonly value="{{ $shareUrl }}">
            </div>
            <button class="btn btn-sm btn-primary" type="button" id="copy-link">Copy</button>
            <a class="btn btn-sm btn-outline-secondary"
               href="mailto:?subject={{ rawurlencode($funnel->title) }}&body={{ rawurlencode("Have a look at this: ".$shareUrl) }}">Email</a>
            <a class="btn btn-sm btn-outline-secondary"
               href="sms:?&body={{ rawurlencode("Have a look at this: ".$shareUrl) }}">Text</a>
            <a class="btn btn-sm btn-danger" href="{{ route('member.presentations.live') }}">Your rooms</a>
        </div>
    </div>

    <div class="stat-row">
        <div class="stat"><div class="stat__n">{{ $totals['started'] }}</div><div class="stat__l">Started</div></div>
        <div class="stat"><div class="stat__n">{{ $totals['moved_on'] }}</div><div class="stat__l">Made a choice</div></div>
        <div class="stat"><div class="stat__n">{{ $totals['asked'] }}</div><div class="stat__l">Asked for something</div></div>
        <div class="stat"><div class="stat__n">{{ $totals['watching'] }}</div><div class="stat__l">Watching now</div></div>
    </div>

    <div class="row g-3">
        <div class="col-lg-7">
            <div class="card">
                <div class="card-header"><h5 class="mb-0">Your people</h5></div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead>
                                <tr>
                                    <th>Person</th>
                                    <th>Where they are</th>
                                    <th>What they picked</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                            @forelse($people as $p)
                                <tr>
                                    <td>
                                        <div class="fw-semibold">{{ $p['name'] }}</div>
                                        <div class="text-muted small">{{ $p['email'] }}</div>
                                    </td>
                                    <td>
                                        <div>{{ $p['step'] ?? '—' }}</div>
                                        <div class="text-muted small">
                                            {{ $p['seen'] }} {{ Str::plural('video', $p['seen']) }} seen
                                        </div>
                                        @if($p['watching'])<span class="tag tag--live mt-1">Watching now</span>@endif
                                    </td>
                                    <td>
                                        @if($p['path'])
                                            <div class="path">
                                                @foreach($p['path'] as $choice)
                                                    <span>{{ $choice }}</span>@if(! $loop->last) &rarr; @endif
                                                @endforeach
                                            </div>
                                        @else
                                            <span class="text-muted small">Nothing yet</span>
                                        @endif
                                        @if($p['outcome'])
                                            <span class="tag tag--won mt-1">{{ $p['outcome'] }}</span>
                                        @endif
                                    </td>
                                    <td class="text-end">
                                        @if($p['thread'])
                                            <a class="btn btn-sm btn-outline-primary"
                                               href="{{ route('member.presentations.live') }}?guest={{ $p['thread'] }}">
                                                Talk
                                            </a>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="text-center text-muted py-5">
                                        Nobody yet. Share your link and they will appear here as they go.
                                    </td>
                                </tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-1">What they choose</h5>
                    <small class="text-muted">Across the people you invited.</small>
                </div>
                <div class="card-body">
                    @forelse($choices as $choice)
                        <div class="mb-3">
                            <div class="d-flex justify-content-between align-items-baseline gap-2 mb-1">
                                <span class="fw-semibold" style="font-size:13.5px;">{{ $choice['label'] }}</span>
                                <span class="text-muted small">{{ $choice['share'] }}%</span>
                            </div>
                            <div class="bar">
                                <div class="bar__fill" style="width:{{ $choice['share'] }}%;"></div>
                                <div class="bar__text">
                                    {{ $choice['count'] }} {{ Str::plural('person', $choice['count']) }}
                                    @if($choice['typical']) · usually at {{ $choice['typical'] }} @endif
                                </div>
                            </div>
                            <div class="text-muted small mt-1">on {{ $choice['on'] ?? '—' }}</div>
                        </div>
                    @empty
                        <p class="text-muted text-center py-4 mb-0">Nothing chosen yet.</p>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
(function () {
    var btn = document.getElementById('copy-link');
    var url = document.getElementById('share-url');
    if (!btn || !url) return;

    btn.addEventListener('click', function () {
        url.select();
        navigator.clipboard.writeText(url.value).then(function () {
            btn.textContent = 'Copied';
            setTimeout(function () { btn.textContent = 'Copy'; }, 1600);
        }).catch(function () {});
    });
})();
</script>
@endpush
