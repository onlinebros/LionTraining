@extends('layouts.member')

@section('title', 'Your rooms')

@push('styles')
<style>
    .new-room {
        display:none; align-items:center; justify-content:space-between; gap:12px;
        background:#fef2f2; border:1px solid #fecaca; color:#991b1b;
        border-radius:10px; padding:11px 14px; margin-bottom:14px; font-size:14px;
    }
    .new-room.is-shown { display:flex; }
    .new-room__close {
        border:0; background:none; color:inherit; font-size:22px; line-height:1;
        cursor:pointer; padding:0 4px; opacity:.6;
    }
    .new-room__close:hover { opacity:1; }

    .rooms { display:flex; flex-wrap:wrap; gap:10px; margin-bottom:14px; }
    .room-chip {
        display:flex; flex-direction:column; gap:2px; min-width:180px;
        background:#fff; border:1px solid #e2e8f0; border-radius:10px; padding:10px 13px;
    }
    .room-chip.is-live { border-color:#fecaca; background:#fff5f5; }
    /* A call that opened while the console was already sitting there. */
    .room-chip.is-new { animation:roomIn 1.6s ease-out 2; }
    @keyframes roomIn {
        0%, 100% { box-shadow:0 0 0 0 rgba(220,38,38,0); }
        50%      { box-shadow:0 0 0 4px rgba(220,38,38,.18); }
    }
    @media (prefers-reduced-motion: reduce) { .room-chip.is-new { animation:none; border-color:#dc2626; } }
    .room-chip__title { font-weight:600; font-size:13.5px; color:#0f172a; }
    .room-chip__meta { font-size:11.5px; color:#64748b; font-variant-numeric:tabular-nums; }

    /* Watch-along panel. Collapsed by default: most of the time a member is
       reading and answering, and a video would just eat the screen. */
    .watch { margin-bottom:14px; }
    .watch__toggle {
        display:inline-flex; align-items:center; gap:8px; margin-bottom:10px;
        border:1px solid #cbd5e1; background:#fff; border-radius:8px;
        padding:7px 13px; font-size:13px; cursor:pointer;
    }
    .watch__body { display:none; }
    .watch.is-open .watch__body { display:block; }

    /* Video and timeline share one column and one width, so the track reads as
       this video's play bar rather than as a separate widget sitting beside it. */
    .watch__col { max-width:min(100%, 960px); }

    .watch__stage { background:#0b1020; border-radius:12px; overflow:hidden; aspect-ratio:16/9; width:100%; }
    .watch__controls {
        display:flex; flex-wrap:wrap; gap:10px; align-items:center;
        margin-top:8px; font-size:12.5px; color:#64748b;
    }
    .wctl {
        border:1px solid #cbd5e1; background:#fff; color:#334155;
        border-radius:8px; padding:5px 11px; font-size:12.5px; cursor:pointer;
    }
    .wctl:hover { border-color:#6366f1; color:#4338ca; }
    .watch__controls input[type=range] { accent-color:#4f46e5; width:110px; }
    .watch__pos { font-variant-numeric:tabular-nums; }
    .watch__stage video { width:100%; height:100%; object-fit:contain; background:#0b1020; display:block; }

    /* Where everybody is. The room's playhead, with a mark for the point each
       guest came in — the number that actually differs between them. */
    .track { background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:14px 16px; margin-top:12px; }
    .track__title { font-size:13px; font-weight:600; color:#0f172a; margin-bottom:12px; }
    .track__bar { position:relative; height:8px; background:#e2e8f0; border-radius:999px; margin:40px 0 8px; }
    .track__fill { position:absolute; inset:0 auto 0 0; background:#6366f1; border-radius:999px; width:0; transition:width .5s linear; }
    .track__head {
        position:absolute; top:50%; width:12px; height:12px; margin:-6px 0 0 -6px;
        border-radius:50%; background:#4f46e5; border:2px solid #fff; box-shadow:0 1px 4px rgba(0,0,0,.3);
    }
    .track__pin {
        position:absolute; top:-16px; width:2px; height:22px; background:#94a3b8;
        transform:translateX(-1px);
    }
    .track__pin span {
        position:absolute; top:-15px; left:50%; transform:translateX(-50%);
        font-size:10px; color:#475569; white-space:nowrap; background:#fff; padding:0 3px;
    }
    .track__ends { display:flex; justify-content:space-between; font-size:11px; color:#94a3b8;
                   font-variant-numeric:tabular-nums; }
    .track__empty { font-size:12.5px; color:#94a3b8; }

    .console {
        display:grid; grid-template-columns:330px minmax(0,1fr);
        border:1px solid #e2e8f0; border-radius:12px; overflow:hidden;
        background:#fff; height:min(74vh, 700px);
    }
    .console__list { border-right:1px solid #e2e8f0; display:flex; flex-direction:column; min-height:0; }
    .console__filter { padding:10px 12px; border-bottom:1px solid #e2e8f0; display:flex; gap:8px; }
    .console__filter input { flex:1; border:1px solid #cbd5e1; border-radius:8px; padding:6px 10px; font-size:13px; }
    .console__scroll { overflow-y:auto; flex:1; min-height:0; }

    .room-head {
        position:sticky; top:0; z-index:1; background:#f8fafc;
        border-bottom:1px solid #e2e8f0; padding:7px 13px;
        font-size:11px; font-weight:700; letter-spacing:.06em; text-transform:uppercase; color:#64748b;
    }

    .guest {
        display:flex; gap:10px; align-items:flex-start; width:100%; text-align:left;
        padding:11px 13px; border:0; border-bottom:1px solid #f1f5f9; background:none; cursor:pointer;
    }
    .guest:hover { background:#f8fafc; }
    .guest.is-active { background:#eef2ff; box-shadow:inset 3px 0 0 #6366f1; }
    .guest__dot { width:8px; height:8px; border-radius:50%; margin-top:6px; flex:none; background:#cbd5e1; }
    .guest__dot.is-on { background:#16a34a; }
    .guest__body { flex:1; min-width:0; }
    .guest__top { display:flex; align-items:baseline; gap:6px; }
    .guest__name { font-weight:600; font-size:14px; color:#0f172a; flex:1; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .guest__meta { font-size:11.5px; color:#94a3b8; }
    .guest__preview { font-size:12.5px; color:#64748b; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    /* What they last picked, or what they ended up asking for. Coloured
       because it is the line that tells a member there is something to do. */
    .guest__flow {
        font-size:12px; color:#0d7a4f; font-weight:600;
        overflow:hidden; text-overflow:ellipsis; white-space:nowrap;
    }
    .guest__badge { background:#4f46e5; color:#fff; border-radius:10px; font-size:10.5px; font-weight:700; padding:1px 7px; }

    .console__pane { display:flex; flex-direction:column; min-height:0; }
    .pane__head { padding:12px 16px; border-bottom:1px solid #e2e8f0; display:flex; gap:10px; align-items:center; }
    .pane__log { flex:1; overflow-y:auto; padding:16px; display:flex; flex-direction:column; gap:10px; background:#fbfcfe; min-height:0; }
    .pane__empty { flex:1; display:flex; align-items:center; justify-content:center; color:#94a3b8; text-align:center; padding:32px; }

    .msg { max-width:75%; padding:9px 12px; border-radius:12px; font-size:14px;
           line-height:1.45; display:flex; flex-direction:column; gap:3px; }
    .msg--them { background:#fff; border:1px solid #e2e8f0; color:#0f172a; align-self:flex-start; }
    .msg--mine { background:#4f46e5; color:#fff; align-self:flex-end; }
    .msg__who { font-size:10.5px; opacity:.7; display:block; }
    .msg__body { display:block; white-space:pre-wrap; word-break:break-word; }

    .pane__form { display:flex; gap:8px; padding:12px; border-top:1px solid #e2e8f0; align-items:flex-end; }
    .pane__form textarea { flex:1; resize:none; border:1px solid #cbd5e1; border-radius:10px; padding:9px 12px; font-size:14px; max-height:120px; }
    .pane__hint { font-size:11px; color:#94a3b8; padding:0 12px 10px; }

    /* Same reasoning as the single-room console: on a phone the list and the
       conversation are two full screens, because a member is switching between
       people — and now between rooms — while calls are running. */
    @media (max-width: 900px) {
        .console { grid-template-columns:1fr; height:auto; border:0; border-radius:0; background:transparent; margin:0 -12px; }
        .console__list, .console__pane { border:1px solid #e2e8f0; border-radius:12px; background:#fff; }
        .console.is-open .console__list { display:none; }
        .console:not(.is-open) .console__pane { display:none; }
        .console.is-open .console__pane {
            position:fixed; inset:0; z-index:1040; border:0; border-radius:0;
            height:100dvh; display:flex; flex-direction:column;
        }
        .console.is-open .pane__head { position:sticky; top:0; z-index:2; background:#fff; padding-top:max(12px, env(safe-area-inset-top)); }
        .console.is-open .pane__form { position:sticky; bottom:0; background:#fff; padding-bottom:max(12px, env(safe-area-inset-bottom)); }
        .console.is-open .pane__hint { display:none; }
        .guest { padding:14px; }
        .pane__form textarea { font-size:16px; }
        .msg { max-width:88%; font-size:15px; }
        .mobile-switch { display:flex !important; }
    }
    .mobile-switch { display:none; align-items:center; gap:8px; }
    .mobile-switch__count { background:#4f46e5; color:#fff; border-radius:999px; font-size:11px; font-weight:700; padding:2px 8px; }
</style>
@endpush

@section('content')
<div class="container-fluid">
    <div class="page-title mb-3 d-flex flex-wrap gap-3 align-items-center justify-content-between">
        <div>
            <h3 class="mb-1">Your rooms</h3>
            <p class="text-muted mb-0">
                Everyone you invited, across every call happening now — answer them all from here.
            </p>
        </div>
        <a href="{{ route('member.presentations.index') }}" class="btn btn-sm btn-outline-secondary">
            All presentations
        </a>
    </div>

    {{-- Deliberately not Bootstrap's alert/d-flex: those carry !important, which
         beats an inline display:none, so the bar showed empty and could not be
         dismissed. Visibility is a class on an element that is hidden by
         default. --}}
    <div class="new-room" id="new-room">
        <span id="new-room-text"></span>
        <button type="button" class="new-room__close" aria-label="Dismiss" id="new-room-dismiss">&times;</button>
    </div>

    <div class="rooms" id="rooms"></div>

    <div class="watch" id="watch">
        <button type="button" class="watch__toggle" id="watch-toggle">
            <span id="watch-toggle-text">Watch along</span>
        </button>
        <div class="watch__body">
            <div class="watch__col">
                <div class="watch__stage">
                    {{-- Starts muted because that is the only way a browser will
                         play it without a gesture. The controls below are how it
                         gets its sound back. No seek bar: a member watching a
                         different point to the room could not say "this bit,
                         right now", which is the whole reason to watch along. --}}
                    <video id="watch-player" playsinline preload="none" muted
                           controlslist="nodownload noplaybackrate noremoteplayback"
                           disablepictureinpicture></video>
                </div>

                <div class="watch__controls">
                    <button type="button" class="wctl" id="watch-mute">Unmute</button>
                    <input type="range" id="watch-volume" min="0" max="1" step="0.05" value="1"
                           aria-label="Volume">
                    <button type="button" class="wctl" id="watch-fs">Fullscreen</button>
                    <span class="flex-grow-1"></span>
                    <span class="watch__pos" id="watch-pos"></span>
                </div>

                {{-- The play bar, directly under the video and the same width,
                     with a mark for the point each guest came in. --}}
                <div class="track">
                    <div class="track__title" id="track-title">Where everyone is</div>
                    <div id="track-body">
                        <div class="track__bar">
                            <div class="track__fill" id="track-fill"></div>
                            <div class="track__head" id="track-head" style="left:0;"></div>
                        </div>
                        <div class="track__ends">
                            <span id="track-now">0:00</span>
                            <span id="track-total">0:00</span>
                        </div>
                    </div>
                    <div class="track__empty" id="track-empty" style="display:none;">
                        Pick a call above to follow it.
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="console" id="console">
        <div class="console__list">
            <div class="console__filter">
                <input type="search" id="filter" placeholder="Search your guests" aria-label="Search your guests">
                <button type="button" class="btn btn-sm btn-outline-secondary" id="unread-toggle"
                        title="Show only guests waiting on a reply">Unread</button>
            </div>
            <div class="console__scroll" id="guest-list"></div>
        </div>

        <div class="console__pane">
            <div class="pane__head" id="pane-head" style="display:none;">
                <span class="mobile-switch">
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="back-btn">&larr; Guests</button>
                    <span class="mobile-switch__count" id="other-unread" style="display:none;"></span>
                </span>
                <div class="flex-grow-1" style="min-width:0;">
                    <div class="fw-semibold" id="pane-name"></div>
                    <small class="text-muted" id="pane-meta"></small>
                </div>
            </div>

            <div class="pane__empty" id="pane-empty">
                <div>
                    <div class="fw-semibold mb-1">Nobody is here yet</div>
                    <div style="font-size:13px;max-width:340px;">
                        When someone you invited joins any of your calls, they appear on the left —
                        whichever call it is. Everything updates on its own.
                    </div>
                </div>
            </div>

            <div class="pane__log" id="pane-log" style="display:none;"></div>

            <form class="pane__form" id="pane-form" style="display:none;">
                <textarea id="pane-input" rows="1" maxlength="2000" placeholder="Write a reply…"></textarea>
                <button class="btn btn-primary" type="submit">Send</button>
            </form>
            <div class="pane__hint" id="pane-hint" style="display:none;">
                Enter sends · Shift + Enter for a new line. Only you and the hosts can see this.
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
(function () {
    var FEED   = @json(route('member.presentations.live.feed'));
    var THREAD = @json(url('/member/presentations/thread'));
    var CSRF   = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

    /*
     * One console across every room that is running.
     *
     * Calls overlap, and a member with guests spread across two or three of
     * them should not be switching browser tabs to answer people. Guests are
     * listed grouped by call, and a single poll carries every room, every guest
     * and every new message — so moving between people, or between rooms, costs
     * nothing.
     */
    var rooms = [], guests = [], threads = {}, seenIds = {};
    var lastId = 0, active = null, unreadOnly = false, search = '';

    // Which rooms the console already holds. Sent with every poll so the server
    // can hand back the full history of any call that has opened since — the
    // cursor alone would skip everything said in it before it appeared.
    var knownRooms = {};
    var justOpened = {};

    var wanted = new URLSearchParams(window.location.search).get('guest');
    wanted = wanted ? parseInt(wanted, 10) : null;

    var listEl  = document.getElementById('guest-list');
    var logEl   = document.getElementById('pane-log');
    var formEl  = document.getElementById('pane-form');
    var inputEl = document.getElementById('pane-input');

    function span(cls, text) {
        var el = document.createElement('span');
        el.className = cls;
        el.textContent = text;
        return el;
    }

    function clock(total) {
        total = Math.max(0, Math.round(total));
        var m = Math.floor(total / 60), s = total % 60;
        return m + ':' + (s < 10 ? '0' : '') + s;
    }

    function guestById(id) {
        for (var i = 0; i < guests.length; i++) if (guests[i].id === id) return guests[i];
        return null;
    }
    function roomById(id) {
        for (var i = 0; i < rooms.length; i++) if (rooms[i].id === id) return rooms[i];
        return null;
    }

    function markRead(id) {
        fetch(THREAD + '/' + id + '/read', {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' },
            credentials: 'same-origin'
        }).catch(function () {});
    }

    /*
     * Rooms, with the steps of a flow gathered into one.
     *
     * A funnel is six videos a prospect might take three of. Six chips would be
     * six things to scan when the member has one conversation to have, so the
     * flow gets one chip and each person's row says which video they are on.
     */
    function roomGroups() {
        var out = [], byFunnel = {};

        rooms.forEach(function (r) {
            if (!r.funnel) {
                out.push({ key: 'p' + r.id, room: r, rooms: [r], funnel: null });
                return;
            }

            var key = 'f' + r.funnel.id;

            if (!byFunnel[key]) {
                byFunnel[key] = { key: key, room: r, rooms: [], funnel: r.funnel };
                out.push(byFunnel[key]);
            }

            byFunnel[key].rooms.push(r);
        });

        return out;
    }

    function inGroup(group, g) {
        return group.rooms.some(function (r) { return r.id === g.room; });
    }

    // ── Rooms strip ───────────────────────────────────────────────────────
    function renderRooms() {
        var box = document.getElementById('rooms');
        box.innerHTML = '';

        if (!rooms.length) {
            box.appendChild(span('text-muted', 'No calls running right now.'));
            return;
        }

        roomGroups().forEach(function (group) {
            var r = group.room;
            var chip = document.createElement('div');
            chip.className = 'room-chip'
                + (r.status === 'live' ? ' is-live' : '')
                + (justOpened[r.id] ? ' is-new' : '');
            chip.appendChild(span('room-chip__title', group.funnel ? group.funnel.title : r.title));

            var here = guests.filter(function (g) { return inGroup(group, g); });
            var watching = here.filter(function (g) { return g.watching; }).length;

            if (group.funnel) {
                chip.appendChild(span('room-chip__meta',
                    group.rooms.length + ' videos · ' + watching + ' watching now · '
                        + here.length + (here.length === 1 ? ' person' : ' people')));
                box.appendChild(chip);
                return;
            }

            // An always-open share has no shared position to report — every
            // viewer is somewhere different, which is the point.
            chip.appendChild(span('room-chip__meta',
                r.format === 'on_demand'
                    ? 'Always open · ' + watching + ' watching now · ' + here.length
                        + (here.length === 1 ? ' viewer' : ' viewers')
                    : (r.status === 'live'
                        ? clock(r.offset) + ' / ' + clock(r.duration)
                            + (r.chapter ? ' · ' + r.chapter : '')
                            + ' · ' + watching + ' of ' + here.length + ' watching'
                        : (r.status === 'ended'
                            ? 'Finished · ' + here.length + (here.length === 1 ? ' guest' : ' guests')
                            : 'Starts ' + r.starts))));
            box.appendChild(chip);
        });
    }

    // ── Guest list, grouped by room ───────────────────────────────────────
    function renderList() {
        listEl.innerHTML = '';

        var shown = guests.filter(function (g) {
            if (unreadOnly && !g.unread) return false;
            if (!search) return true;
            return (g.name + ' ' + g.email).toLowerCase().indexOf(search) !== -1;
        });

        if (!shown.length) {
            var empty = span('text-muted text-center d-block py-4',
                guests.length ? 'Nobody matches that.' : 'Nobody has joined yet.');
            empty.style.fontSize = '13px';
            listEl.appendChild(empty);
            return;
        }

        // Grouped, and labelled, because "which call is this person on?" is the
        // first thing you need to know before answering them.
        roomGroups().forEach(function (group) {
            var r = group.room;
            var here = shown.filter(function (g) { return inGroup(group, g); });
            if (!here.length) return;

            listEl.appendChild(span('room-head', group.funnel ? group.funnel.title : r.title));

            here.forEach(function (g) {
                var row = document.createElement('button');
                row.type = 'button';
                row.className = 'guest' + (active === g.id ? ' is-active' : '');
                row.appendChild(span('guest__dot' + (g.watching ? ' is-on' : ''), ''));

                var body = document.createElement('span');
                body.className = 'guest__body';

                var top = document.createElement('span');
                top.className = 'guest__top';
                top.appendChild(span('guest__name', g.name));
                if (g.unread) top.appendChild(span('guest__badge', String(g.unread)));
                body.appendChild(top);

                /*
                 * What this person is doing, in one line.
                 *
                 * In a flow the useful facts are which video they picked and
                 * how far into it they are — that is the opening line a member
                 * needs. Outside one, how far through they have got.
                 */
                if (g.flow) {
                    body.appendChild(span('guest__meta d-block',
                        (g.flow.step || 'starting')
                            + ' · ' + g.progress + '% through'
                            + (g.watching ? '' : ' · away')));

                    if (g.flow.outcome || g.flow.chose) {
                        body.appendChild(span('guest__flow d-block',
                            g.flow.outcome
                                ? '✓ ' + g.flow.outcome
                                : 'chose “' + g.flow.chose + '”'));
                    }
                } else {
                    var onDemand = group.rooms.some(function (rm) {
                        return rm.id === g.room && rm.format === 'on_demand';
                    });

                    body.appendChild(span('guest__meta d-block',
                        onDemand
                            ? (g.watching ? 'watching · ' + g.progress + '% through'
                                          : (g.joined_at !== '—' ? 'stopped at ' + g.progress + '%' : 'not started'))
                            : (g.watching ? 'watching · joined ' + g.joined_at
                                          : (g.joined_at !== '—' ? 'left · watched ' + g.watched : 'not arrived'))));
                }

                if (g.preview) {
                    body.appendChild(span('guest__preview d-block',
                        (g.preview_mine ? 'You: ' : '') + g.preview));
                }

                row.appendChild(body);
                row.addEventListener('click', function () { open(g.id); });
                listEl.appendChild(row);
            });
        });
    }

    // ── Conversation ──────────────────────────────────────────────────────
    function bubble(m) {
        var el = document.createElement('div');
        el.className = 'msg ' + (m.guest ? 'msg--them' : 'msg--mine');
        el.appendChild(span('msg__who', m.from + ' · ' + m.at));
        el.appendChild(span('msg__body', m.body));
        return el;
    }

    function renderThread(preserveScroll) {
        if (active === null) return;
        var atBottom = logEl.scrollHeight - logEl.scrollTop - logEl.clientHeight < 80;
        logEl.innerHTML = '';
        (threads[active] || []).forEach(function (m) { logEl.appendChild(bubble(m)); });
        if (!preserveScroll || atBottom) logEl.scrollTop = logEl.scrollHeight;
    }

    function renderOtherUnread() {
        var badge = document.getElementById('other-unread');
        if (!badge) return;
        var waiting = guests.reduce(function (n, g) {
            return n + (g.id !== active && g.unread ? 1 : 0);
        }, 0);
        badge.textContent = waiting + ' waiting';
        badge.style.display = waiting ? '' : 'none';
    }

    function open(id) {
        active = id;
        var g = guestById(id);

        document.getElementById('console').classList.add('is-open');
        document.getElementById('pane-empty').style.display = 'none';
        document.getElementById('pane-head').style.display = 'flex';
        logEl.style.display = 'flex';
        formEl.style.display = 'flex';
        document.getElementById('pane-hint').style.display = 'block';

        if (g) {
            var room = roomById(g.room);
            document.getElementById('pane-name').textContent = g.name;
            // In a flow the heading says where in it they are, and how many
            // videos they have taken to get there — the context a member needs
            // before typing anything.
            document.getElementById('pane-meta').textContent = g.flow
                ? g.flow.funnel + ' · ' + (g.flow.step || 'starting')
                    + (g.flow.step_no ? ' (step ' + g.flow.step_no + ')' : '')
                    + ' · ' + g.progress + '% through · ' + g.flow.seen
                    + (g.flow.seen === 1 ? ' video seen' : ' videos seen')
                    + ' · ' + g.email
                    + (g.flow.outcome ? ' · ' + g.flow.outcome : '')
                : (room ? room.title + ' · ' : '') + g.email
                    + (room && room.format === 'on_demand'
                        ? (g.joined_at !== '—' ? ' · ' + g.progress + '% through · ' + g.watched : ' · not started')
                        : (g.joined_at !== '—' ? ' · joined at ' + g.joined_at + ' · watched ' + g.watched : ' · not arrived yet'))
                    + (g.cta_clicked ? ' · clicked through' : '');

            if (g.unread) { g.unread = 0; markRead(id); }
        }

        renderList();
        renderThread(false);
        renderOtherUnread();
        renderWatch();
        if (window.matchMedia('(min-width: 901px)').matches) inputEl.focus();
    }

    document.getElementById('back-btn').addEventListener('click', function () {
        document.getElementById('console').classList.remove('is-open');
        active = null;
        renderList();
        renderOtherUnread();
        window.scrollTo(0, 0);
    });

    // ── Polling ───────────────────────────────────────────────────────────
    function absorb(d) {
        // Announce any call that opened while this page was already sitting
        // here, rather than letting it appear silently in a list nobody is
        // looking at.
        var opened = (d.rooms || []).filter(function (r) { return r.is_new; });

        opened.forEach(function (r) {
            justOpened[r.id] = true;
            setTimeout(function () { delete justOpened[r.id]; renderRooms(); }, 20000);
        });

        if (opened.length) announceOpened(opened);

        (d.rooms || []).forEach(function (r) { knownRooms[r.id] = true; });

        rooms  = d.rooms;
        guests = d.attendees;

        (d.messages || []).forEach(function (m) {
            if (seenIds[m.id]) return;
            seenIds[m.id] = true;
            if (m.id > lastId) lastId = m.id;
            (threads[m.attendee] = threads[m.attendee] || []).push(m);
        });

        if (active !== null) {
            var g = guestById(active);
            if (g && g.unread) { g.unread = 0; markRead(active); }
        }

        renderRooms();
        renderList();
        renderThread(true);
        renderOtherUnread();
        renderWatch();

        if (wanted && guestById(wanted)) {
            var target = wanted;
            wanted = null;
            open(target);
        }
    }

    /* ── Watch along ──────────────────────────────────────────────────────
     *
     * The member follows the same moment their guests are on, and sees where
     * each of them came in against it. Synced from the room's own offset, like
     * a guest's player -- watching a different point to the room would defeat
     * the purpose of being able to say "this bit, right now".
     */
    var watchRoom = null;
    var watchOpen = false;
    var watchPlayer = document.getElementById('watch-player');

    function followedRoom() {
        // The room of whoever is open, else the first one running.
        var g = active !== null ? guestById(active) : null;
        if (g) return roomById(g.room);

        for (var i = 0; i < rooms.length; i++) if (rooms[i].status === 'live') return rooms[i];
        return rooms[0] || null;
    }

    function renderWatch() {
        var room = followedRoom();
        var body = document.getElementById('track-body');
        var empty = document.getElementById('track-empty');

        if (!room) {
            body.style.display = 'none';
            empty.style.display = '';
            return;
        }

        body.style.display = '';
        empty.style.display = 'none';
        document.getElementById('track-title').textContent =
            (room.format === 'on_demand' ? 'How far they have got — ' : 'Where everyone is — ') + room.title;

        // The marker follows the room on a scheduled showing, and the selected
        // guest on an always-open one.
        var followed = active !== null ? guestById(active) : null;
        var pos = room.format === 'on_demand'
            ? (followed ? (followed.position || 0) : 0)
            : (room.status === 'live' ? (room.offset || 0) : 0);
        var pct = room.duration ? Math.min(100, (pos / room.duration) * 100) : 0;

        document.getElementById('track-fill').style.width = pct + '%';
        document.getElementById('track-head').style.left = pct + '%';
        document.getElementById('track-now').textContent = clock(pos);
        document.getElementById('track-total').textContent = clock(room.duration);

        // A pin per guest, at the point they came in.
        var bar = document.querySelector('.track__bar');
        Array.prototype.slice.call(bar.querySelectorAll('.track__pin')).forEach(function (n) { n.remove(); });

        guests.filter(function (g) {
            return g.room === room.id
                && (room.format === 'on_demand' ? g.furthest > 0 : g.joined_secs !== null && g.joined_secs !== undefined);
        }).forEach(function (g) {
            var at = room.format === 'on_demand' ? g.furthest : g.joined_secs;

            var pin = document.createElement('div');
            pin.className = 'track__pin';
            pin.style.left = (room.duration ? Math.min(100, (at / room.duration) * 100) : 0) + '%';
            pin.title = room.format === 'on_demand'
                ? g.name + ' has reached ' + g.progress + '%'
                : g.name + ' joined at ' + g.joined_at;
            var tag = document.createElement('span');
            tag.textContent = g.name.split(' ')[0];
            pin.appendChild(tag);
            bar.appendChild(pin);
        });

        if (watchOpen) syncWatch(room);
    }

    function syncWatch(room) {
        if (!room) return;

        /*
         * On an always-open share there is no room clock to follow, so the
         * useful thing is to sit where the selected guest is — that is what
         * lets a member say "the part you are on right now".
         */
        if (room.format === 'on_demand') {
            if (watchRoom !== room.id) {
                watchRoom = room.id;
                watchPlayer.src = room.video;
            }

            var g = active !== null ? guestById(active) : null;

            if (g && g.position !== null && g.position !== undefined
                && Math.abs(watchPlayer.currentTime - g.position) > 3) {
                watchPlayer.currentTime = g.position;
            }

            return;
        }

        if (room.status !== 'live') return;

        if (watchRoom !== room.id) {
            var wasMuted = watchPlayer.muted;
            var level    = watchPlayer.volume;

            watchRoom = room.id;
            watchPlayer.src = room.video;

            // A new source resets nothing on the element, but be explicit --
            // losing the sound on every room switch would be maddening.
            watchPlayer.muted = wasMuted;
            watchPlayer.volume = level;
        }

        var target = room.offset || 0;

        if (watchPlayer.paused) {
            watchPlayer.currentTime = target;
            watchPlayer.play().catch(function () {
                watchPlayer.muted = true;
                applySound();
                watchPlayer.play().catch(function () {});
            });
            return;
        }

        // Same tiering as the guest player: ignore a small gap, close a medium
        // one invisibly, jump a large one.
        var drift = watchPlayer.currentTime - target;
        if (Math.abs(drift) > 5) watchPlayer.currentTime = target;
        else if (Math.abs(drift) > 1) watchPlayer.playbackRate = drift < 0 ? 1.03 : 0.97;
        else watchPlayer.playbackRate = 1;
    }

    /*
     * Sound.
     *
     * The player has to start muted -- that is the only way a browser will play
     * it without a gesture -- so unmuting is an explicit act, and the choice is
     * remembered. A member who turned the sound on once should not have to do it
     * again every time they switch calls or come back to the page.
     */
    var muteBtn   = document.getElementById('watch-mute');
    var volumeEl  = document.getElementById('watch-volume');

    function storedVolume() {
        try {
            var raw = localStorage.getItem('sxf.watch.volume');
            return raw === null ? null : Math.min(1, Math.max(0, parseFloat(raw)));
        } catch (e) { return null; }
    }

    function rememberVolume(value) {
        try { localStorage.setItem('sxf.watch.volume', String(value)); } catch (e) {}
    }

    function applySound() {
        muteBtn.textContent = watchPlayer.muted || watchPlayer.volume === 0 ? 'Unmute' : 'Mute';
        volumeEl.value = watchPlayer.muted ? 0 : watchPlayer.volume;
    }

    muteBtn.addEventListener('click', function () {
        if (watchPlayer.muted || watchPlayer.volume === 0) {
            watchPlayer.muted = false;
            if (watchPlayer.volume === 0) watchPlayer.volume = 1;
            rememberVolume(watchPlayer.volume);
        } else {
            watchPlayer.muted = true;
        }

        // Unmuting can be refused if the browser wants a fresher gesture; this
        // click is one, so a refusal means fall back rather than go silent.
        watchPlayer.play().catch(function () { watchPlayer.muted = true; applySound(); });
        applySound();
    });

    volumeEl.addEventListener('input', function () {
        watchPlayer.volume = parseFloat(this.value);
        watchPlayer.muted = watchPlayer.volume === 0;
        rememberVolume(watchPlayer.volume);
        applySound();
    });

    document.getElementById('watch-fs').addEventListener('click', function () {
        if (document.fullscreenElement) document.exitFullscreen();
        else if (watchPlayer.requestFullscreen) watchPlayer.requestFullscreen();
    });

    watchPlayer.addEventListener('volumechange', applySound);

    watchPlayer.addEventListener('timeupdate', function () {
        var room = followedRoom();
        document.getElementById('watch-pos').textContent = room
            ? clock(watchPlayer.currentTime) + ' / ' + clock(room.duration)
            : '';
    });

    document.getElementById('watch-toggle').addEventListener('click', function () {
        watchOpen = !watchOpen;
        document.getElementById('watch').classList.toggle('is-open', watchOpen);
        document.getElementById('watch-toggle-text').textContent =
            watchOpen ? 'Hide the video' : 'Watch along';

        if (!watchOpen) {
            watchPlayer.pause();
            watchPlayer.removeAttribute('src');
            watchPlayer.load();
            watchRoom = null;
        } else {
            var saved = storedVolume();

            if (saved !== null && saved > 0) {
                watchPlayer.volume = saved;
                watchPlayer.muted = false;
            }

            applySound();
            renderWatch();
        }
    });

    var announceTimer = null;

    function announceOpened(opened) {
        var banner = document.getElementById('new-room');

        document.getElementById('new-room-text').textContent = opened.length === 1
            ? '“' + opened[0].title + '” has started — your guests on it are in the list below.'
            : opened.length + ' more calls have started. Their guests are in the list below.';

        banner.classList.add('is-shown');

        // Stands down on its own. A notice that has to be dismissed is one more
        // thing to do in the middle of a live room.
        clearTimeout(announceTimer);
        announceTimer = setTimeout(function () { banner.classList.remove('is-shown'); }, 30000);
    }

    function poll() {
        var params = [];
        if (lastId) params.push('since=' + lastId);

        var known = Object.keys(knownRooms);
        if (known.length) params.push('known=' + known.join(','));

        /*
         * Carried on every poll, not just the first load.
         *
         * A notification can name somebody whose call finished weeks ago. The
         * server pulls that room in when asked for them by name — so if the
         * polls stopped asking, the room would quietly drop out from under an
         * open conversation.
         */
        var open = active !== null ? active : wanted;
        if (open) params.push('guest=' + open);

        fetch(FEED + (params.length ? '?' + params.join('&') : ''), {
            credentials: 'same-origin', headers: { 'Accept': 'application/json' }
        })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (d) { if (d) absorb(d); })
            .catch(function () {});
    }

    // ── Sending ───────────────────────────────────────────────────────────
    function send() {
        var body = inputEl.value.trim();
        if (!body || active === null) return;

        var to = active;
        inputEl.value = '';
        inputEl.style.height = 'auto';

        var payload = new FormData();
        payload.append('body', body);

        fetch(THREAD + '/' + to, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' },
            body: payload, credentials: 'same-origin'
        })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (m) {
                if (!m) throw new Error('send failed');
                if (seenIds[m.id]) return;
                seenIds[m.id] = true;
                if (m.id > lastId) lastId = m.id;
                m.attendee = to;
                (threads[to] = threads[to] || []).push(m);
                if (active === to) renderThread(false);
                renderList();
                inputEl.focus();
            })
            .catch(function () { inputEl.value = body; });
    }

    formEl.addEventListener('submit', function (e) { e.preventDefault(); send(); });
    inputEl.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(); }
    });
    inputEl.addEventListener('input', function () {
        this.style.height = 'auto';
        this.style.height = Math.min(this.scrollHeight, 120) + 'px';
    });

    document.getElementById('new-room-dismiss').addEventListener('click', function () {
        clearTimeout(announceTimer);
        document.getElementById('new-room').classList.remove('is-shown');
    });

    document.getElementById('filter').addEventListener('input', function () {
        search = this.value.trim().toLowerCase();
        renderList();
    });
    document.getElementById('unread-toggle').addEventListener('click', function () {
        unreadOnly = !unreadOnly;
        this.classList.toggle('btn-primary', unreadOnly);
        this.classList.toggle('btn-outline-secondary', !unreadOnly);
        renderList();
    });

    poll();
    setInterval(poll, 4000);
})();
</script>
@endpush
