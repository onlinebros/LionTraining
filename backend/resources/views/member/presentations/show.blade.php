@extends('layouts.member')

@section('title', $presentation->title)

@push('styles')
<style>
    .pres-head { display:flex; flex-wrap:wrap; gap:14px; align-items:center; justify-content:space-between; }
    .share-row { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
    .share-url { font-family:ui-monospace,Menlo,monospace; font-size:12.5px; min-width:250px; }

    /* Where the room is, for a showing everybody watches together. */
    .now-bar {
        display:flex; flex-wrap:wrap; gap:6px 18px; align-items:center;
        background:var(--q3-surface); border:1px solid var(--q3-border); border-radius:10px;
        padding:10px 14px; margin-bottom:14px; font-size:13px;
    }
    .now-bar .lbl { font-size:11px; text-transform:uppercase; letter-spacing:.06em; color:var(--q3-text-muted); }
    .now-bar .pos { font-weight:700; color:var(--q3-text); font-variant-numeric:tabular-nums; }
    .now-bar .rail { flex-basis:100%; height:4px; background:var(--q3-surface-3); border-radius:999px; overflow:hidden; }
    .now-bar .rail__fill { height:100%; width:0; background:var(--q3-danger); transition:width 1s linear; }

    /* One row per guest. Clicking one goes to Your Rooms, which is where every
       conversation lives — this page is about the showing, not the talking. */
    .guests { display:flex; flex-direction:column; }
    .guest {
        display:flex; gap:10px; align-items:flex-start; width:100%; text-align:left;
        padding:11px 14px; border-bottom:1px solid var(--q3-border);
        background:transparent; color:inherit; text-decoration:none;
    }
    .guest:last-child { border-bottom:0; }
    .guest:hover { background:var(--q3-surface-2); }
    .guest__dot { width:8px; height:8px; border-radius:50%; background:var(--q3-text-dim); margin-top:6px; flex:none; }
    .guest__dot.is-on { background:var(--q3-success); }
    .guest__body { flex:1; min-width:0; }
    .guest__top { display:flex; align-items:center; gap:8px; }
    .guest__name { font-weight:600; color:var(--q3-text); font-size:14px; }
    .guest__badge {
        background:var(--q3-danger); color:#fff; border-radius:999px;
        font-size:11px; font-weight:700; padding:1px 7px;
    }
    .guest__meta { font-size:12px; color:var(--q3-text-muted); }
    .guest__preview { font-size:12.5px; color:var(--q3-text-muted); overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .guest__go { font-size:12px; color:var(--q3-gold-high); white-space:nowrap; align-self:center; }
</style>
@endpush

@section('content')
<div class="container-fluid">

    <div class="page-title mb-3">
        <div class="pres-head">
            <div>
                <h3 class="mb-1">{{ $presentation->title }}</h3>
                <p class="text-muted mb-0">
                    @include('partials.presentation-time', ['presentation' => $presentation]) ·
                    {{ $presentation->formattedDuration() }} ·
                    <span class="badge bg-{{ $presentation->isLive() ? 'danger' : ($presentation->hasEnded() ? 'secondary' : 'primary') }}">
                        {{ $presentation->statusLabel() }}
                    </span>
                </p>
            </div>
            <div class="share-row">
                <input type="text" class="form-control form-control-sm share-url" id="share-url" readonly value="{{ $shareUrl }}">
                <button class="btn btn-sm btn-primary" type="button" id="copy-link">Copy my link</button>
                <a class="btn btn-sm btn-outline-secondary"
                   href="mailto:?subject={{ rawurlencode($presentation->title) }}&body={{ rawurlencode("I'd like to invite you to this: ".$shareUrl) }}">Email</a>
                <a class="btn btn-sm btn-outline-secondary"
                   href="sms:?&body={{ rawurlencode("I'd like to invite you to this: ".$shareUrl) }}">Text</a>
                <a class="btn btn-sm btn-outline-secondary"
                   href="{{ route('member.presentations.export', $presentation) }}">Export</a>
                @if($presentation->isPersonal() && ($presentation->isOnDemand() || ! $presentation->isLive()))
                    <form method="POST" action="{{ route('member.presentations.destroy', $presentation) }}"
                          onsubmit="return confirm('Cancel this presentation?');">
                        @csrf @method('DELETE')
                        <button class="btn btn-sm btn-outline-danger">Cancel</button>
                    </form>
                @endif
            </div>
        </div>
    </div>

    {{-- An always-open share has no shared position: every viewer is somewhere
         different, so there is no "now playing" to report. --}}
    <div class="now-bar" id="now-bar"
         style="{{ $presentation->isLive() && ! $presentation->isOnDemand() ? '' : 'display:none;' }}">
        <span class="lbl">Now playing</span>
        <span class="pos" id="now-pos">--:--</span>
        <span id="now-chapter" class="text-muted"></span>
        <span class="flex-grow-1"></span>
        <span id="now-watching" class="text-muted"></span>
        <div class="rail"><div class="rail__fill" id="rail-fill"></div></div>
    </div>

    <div class="card">
        <div class="card-header d-flex flex-wrap gap-2 align-items-center justify-content-between">
            <div>
                <h5 class="mb-1">Your guests</h5>
                <small class="text-muted">
                    Only the people you invited. Answering them happens in Your Rooms, where every
                    call you have people in is open at once.
                </small>
            </div>
            <a href="{{ route('member.presentations.live') }}" class="btn btn-sm btn-danger">
                Open Your Rooms
                <span class="badge bg-light text-dark ms-1" id="unread-total" style="display:none;"></span>
            </a>
        </div>
        <div class="card-body p-0">
            <div class="guests" id="guest-list"></div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
/*
 * Who is here, and how to reach them.
 *
 * Deliberately not a conversation. There is one place to chat and it is Your
 * Rooms, which holds every call at once — two consoles doing the same job meant
 * one of them was always the stale copy, and it was, silently, for a while.
 *
 * The list is filled from the feed rather than rendered into the page, so the
 * markup carries no guest data at all. That is a stronger position than
 * filtering the template correctly: there is nothing there to leak.
 */
(function () {
    var FEED   = @json(route('member.presentations.attendees', $presentation));
    var ROOMS  = @json(route('member.presentations.live'));
    var listEl = document.getElementById('guest-list');
    var guests = [];

    function span(cls, text) {
        var el = document.createElement('span');
        el.className = cls;
        el.textContent = text;
        return el;
    }

    function clock(total) {
        if (total === null || total === undefined) return '--:--';
        total = Math.max(0, Math.round(total));
        var m = Math.floor(total / 60), s = total % 60;
        return m + ':' + (s < 10 ? '0' : '') + s;
    }

    function render() {
        listEl.innerHTML = '';

        if (!guests.length) {
            var empty = span('text-muted text-center d-block py-5',
                'Nobody yet. Share your link and they will appear here.');
            empty.style.fontSize = '13px';
            listEl.appendChild(empty);
            return;
        }

        guests.forEach(function (g) {
            var row = document.createElement('a');
            row.className = 'guest';
            // Straight into the conversation, with them already open.
            row.href = ROOMS + '?guest=' + g.id;

            row.appendChild(span('guest__dot' + (g.watching ? ' is-on' : ''), ''));

            var body = document.createElement('span');
            body.className = 'guest__body';

            var top = document.createElement('span');
            top.className = 'guest__top';
            top.appendChild(span('guest__name', g.name));
            if (g.unread) top.appendChild(span('guest__badge', String(g.unread)));
            body.appendChild(top);

            body.appendChild(span('guest__meta d-block',
                g.watching ? 'watching · joined ' + g.joined_at
                           : (g.joined_at !== '—' ? 'watched ' + g.watched : 'not arrived')));

            if (g.preview) {
                body.appendChild(span('guest__preview d-block',
                    (g.preview_mine ? 'You: ' : '') + g.preview));
            }

            row.appendChild(body);
            row.appendChild(span('guest__go', g.unread ? 'Reply →' : 'Talk →'));
            listEl.appendChild(row);
        });
    }

    function renderRoom(data) {
        if (data.status === 'live' && data.offset !== null && data.offset !== undefined) {
            document.getElementById('now-bar').style.display = '';
            document.getElementById('now-pos').textContent =
                clock(data.offset) + ' / ' + clock(data.duration);
            document.getElementById('now-chapter').textContent = data.chapter || '';
            document.getElementById('now-watching').textContent =
                data.watching + ' of ' + data.total + ' watching';
            document.getElementById('rail-fill').style.width =
                (data.duration ? Math.min(100, (data.offset / data.duration) * 100) : 0) + '%';
        }

        var badge = document.getElementById('unread-total');
        badge.textContent = data.unread || '';
        badge.style.display = data.unread ? '' : 'none';
    }

    function poll() {
        fetch(FEED, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (d) {
                if (!d) return;
                guests = d.attendees || [];
                renderRoom(d);
                render();
            })
            .catch(function () { /* a dropped poll is not worth surfacing */ });
    }

    var copy = document.getElementById('copy-link');
    var url  = document.getElementById('share-url');

    if (copy && url) {
        copy.addEventListener('click', function () {
            url.select();
            navigator.clipboard.writeText(url.value).then(function () {
                copy.textContent = 'Copied';
                setTimeout(function () { copy.textContent = 'Copy my link'; }, 1600);
            }).catch(function () {});
        });
    }

    poll();
    setInterval(poll, 5000);
})();
</script>
@endpush
