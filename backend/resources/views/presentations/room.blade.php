@extends('layouts.public')

@section('title', $presentation->title . ' — Quantum Life')

@push('styles')
<style>
    /*
     * The broadcast room.
     *
     * Deliberately dark and cinematic rather than another white admin panel:
     * this is the first thing a prospect ever sees of the company, the video is
     * the point, and a dark surround is what makes a video read as an event
     * rather than an attachment. The accent follows the site's own colour so it
     * stays on brand wherever that is set.
     */
    :root {
        --room-bg:      #090C13;
        --room-panel:   #121724;
        --room-raised:  #182031;
        --room-line:    #222C40;
        --room-text:    #E9EDF6;
        --room-muted:   #8894AC;
        --room-accent:  var(--theme-default, #f27209);
        --room-live:    #FF4438;
    }

    body { background: var(--room-bg); color: var(--room-text); }

    .room { max-width:1320px; margin:0 auto; padding:18px 18px 64px; }

    /* ── Top bar ──────────────────────────────────────────────────────── */
    .room-bar {
        display:flex; flex-wrap:wrap; gap:12px; align-items:center;
        justify-content:space-between; margin-bottom:18px;
    }
    .room-bar__right { display:flex; align-items:center; gap:12px; }
    .who {
        display:flex; align-items:center; gap:8px;
        font-size:13px; color:var(--room-muted);
    }
    .who__avatar {
        width:28px; height:28px; border-radius:50%; flex:none;
        background:linear-gradient(135deg, var(--room-accent), #ffffff22);
        color:#fff; font-size:12px; font-weight:700;
        display:flex; align-items:center; justify-content:center;
    }

    .live-pill {
        display:inline-flex; align-items:center; gap:7px;
        background:rgba(255,68,56,.12); color:var(--room-live);
        border:1px solid rgba(255,68,56,.35);
        border-radius:999px; padding:5px 13px;
        font-size:11px; font-weight:700; letter-spacing:.1em;
    }
    .live-pill .dot {
        width:7px; height:7px; border-radius:50%; background:var(--room-live);
        box-shadow:0 0 0 0 rgba(255,68,56,.7); animation:pulse 1.8s infinite;
    }
    @keyframes pulse {
        0%   { box-shadow:0 0 0 0 rgba(255,68,56,.6); }
        70%  { box-shadow:0 0 0 7px rgba(255,68,56,0); }
        100% { box-shadow:0 0 0 0 rgba(255,68,56,0); }
    }

    /* ── Layout ───────────────────────────────────────────────────────── */
    .room-grid { display:grid; grid-template-columns:minmax(0,1fr) 360px; gap:20px; align-items:start; }
    @media (max-width:1024px) { .room-grid { grid-template-columns:minmax(0,1fr); } }

    /* ── Stage ────────────────────────────────────────────────────────── */
    .stage-wrap { position:relative; }
    /* A soft bloom behind the video so it sits in the page rather than on it. */
    .stage-wrap::before {
        content:""; position:absolute; inset:-8% -4% 40%;
        background:radial-gradient(60% 60% at 50% 40%, rgba(var(--rgb-primary,242,114,9), .22), transparent 70%);
        filter:blur(48px); z-index:0; pointer-events:none;
    }
    .stage {
        position:relative; z-index:1; background:#04060B; border-radius:16px;
        overflow:hidden; aspect-ratio:16/9;
        border:1px solid var(--room-line);
        box-shadow:0 24px 60px rgba(0,0,0,.55);
    }
    .stage video { width:100%; height:100%; object-fit:contain; background:#04060B; display:block; }

    .stage__overlay {
        position:absolute; inset:0; display:flex; flex-direction:column;
        align-items:center; justify-content:center; text-align:center; gap:10px;
        padding:28px; background:
            radial-gradient(80% 80% at 50% 45%, rgba(24,32,49,.55), rgba(4,6,11,.94));
    }
    .countdown {
        font-size:clamp(34px,7vw,68px); font-weight:700; letter-spacing:-.02em;
        color:#fff; font-variant-numeric:tabular-nums; line-height:1;
    }
    .overlay-note { color:var(--room-muted); font-size:14px; max-width:34ch; }
    .btn-join {
        margin-top:8px; border:0; border-radius:999px; padding:12px 30px;
        background:var(--room-accent); color:#fff; font-weight:600; font-size:15px;
        box-shadow:0 8px 26px rgba(0,0,0,.45); cursor:pointer;
        transition:transform .12s ease, filter .12s ease;
    }
    .btn-join:hover { transform:translateY(-1px); filter:brightness(1.08); }
    .btn-join:active { transform:translateY(0); }

    /* ── Control strip ────────────────────────────────────────────────── */
    .controls {
        display:flex; flex-wrap:wrap; gap:12px; align-items:center;
        margin-top:12px; padding:10px 14px;
        background:var(--room-panel); border:1px solid var(--room-line);
        border-radius:12px; font-size:13px; color:var(--room-muted);
    }
    .ctl {
        background:var(--room-raised); border:1px solid var(--room-line);
        color:var(--room-text); border-radius:8px; padding:6px 12px;
        font-size:12.5px; cursor:pointer; transition:border-color .12s, color .12s;
    }
    .ctl:hover { border-color:var(--room-accent); color:#fff; }
    .controls input[type=range] { accent-color:var(--room-accent); width:110px; }

    /* Shown only when the browser blocked sound. Playback has already begun,
       so this asks for the one thing still missing rather than for permission
       to start. */
    .sound-prompt {
        display:block; width:100%; margin-top:12px; padding:12px;
        border:1px solid rgba(var(--rgb-primary,242,114,9),.4);
        background:rgba(var(--rgb-primary,242,114,9),.14);
        color:var(--room-accent); border-radius:12px;
        font-weight:600; font-size:15px; cursor:pointer;
    }
    .pos-label { font-variant-numeric:tabular-nums; color:var(--room-text); }

    /* ── Details ──────────────────────────────────────────────────────── */
    /* Colour stated outright: the theme's own h1 rule wins over anything
       inherited from body, and dark-on-dark is invisible. */
    .room-title {
        font-size:clamp(20px,2.6vw,27px); font-weight:700; letter-spacing:-.015em;
        margin:20px 0 6px; color:var(--room-text);
    }
    .room-meta { color:var(--room-muted); font-size:13.5px; margin-bottom:14px; }
    .room-meta .pres-time__local { color:var(--room-muted); }
    .room-desc { color:#C3CCDD; line-height:1.65; white-space:pre-line; margin-bottom:0; }

    .cta {
        margin-top:20px; padding:20px 22px; border-radius:14px;
        background:linear-gradient(135deg, rgba(var(--rgb-primary,242,114,9),.16), rgba(255,255,255,.03));
        border:1px solid rgba(var(--rgb-primary,242,114,9),.32);
        display:flex; flex-wrap:wrap; gap:16px; align-items:center; justify-content:space-between;
    }
    .cta__title { font-weight:650; font-size:16px; margin-bottom:2px; color:var(--room-text); }
    .cta__sub { color:var(--room-muted); font-size:13px; margin:0; max-width:46ch; }
    .btn-cta {
        border:0; border-radius:999px; padding:11px 24px; white-space:nowrap;
        background:var(--room-accent); color:#fff; font-weight:600; font-size:14.5px;
        cursor:pointer; transition:transform .12s ease, filter .12s ease;
    }
    .btn-cta:hover { transform:translateY(-1px); filter:brightness(1.08); }

    /* ── Timed choices ────────────────────────────────────────────────────
       The panel rises once, when the first option becomes due; each option
       then fades in on its own cue. Transform and opacity only, so the
       reveal never costs a layout mid-playback. */
    .choices {
        margin-top:20px; padding:20px 22px; border-radius:14px;
        background:linear-gradient(135deg, rgba(var(--rgb-primary,242,114,9),.16), rgba(255,255,255,.03));
        border:1px solid rgba(var(--rgb-primary,242,114,9),.32);
        opacity:0; transform:translateY(10px);
        transition:opacity .45s ease, transform .45s ease;
    }
    .choices.is-in { opacity:1; transform:none; }
    .choices__title { font-weight:650; font-size:16px; color:var(--room-text); margin-bottom:2px; }
    .choices__sub { color:var(--room-muted); font-size:13px; margin:0 0 12px; max-width:52ch; }
    .choices__row { display:flex; flex-wrap:wrap; gap:10px; margin-top:12px; }
    .btn-choice {
        border:1px solid rgba(255,255,255,.14); border-radius:999px;
        padding:11px 22px; background:rgba(255,255,255,.06); color:var(--room-text);
        font-weight:600; font-size:14.5px; cursor:pointer; text-align:left;
        opacity:0; transform:translateY(6px);
        transition:opacity .35s ease, transform .35s ease, background .12s ease, filter .12s ease;
    }
    .btn-choice.is-in { opacity:1; transform:none; }
    .btn-choice:hover { background:rgba(255,255,255,.12); }
    /* The one that ends the journey is the one to look at — whether it opens
       beside the video or takes them straight there. */
    .btn-choice[data-kind="cta"] {
        background:var(--room-accent); border-color:transparent; color:#fff;
    }
    .btn-choice[data-kind="cta"]:hover { filter:brightness(1.08); }
    .btn-choice[disabled] { opacity:.5; cursor:default; }

    /* ── Chat ─────────────────────────────────────────────────────────── */
    .chat {
        display:flex; flex-direction:column; height:min(76vh,720px);
        background:var(--room-panel); border:1px solid var(--room-line);
        border-radius:16px; overflow:hidden;
        box-shadow:0 24px 60px rgba(0,0,0,.4);
    }
    @media (max-width:1024px) { .chat { height:min(60vh,520px); } }

    .chat__head { padding:14px 16px; border-bottom:1px solid var(--room-line); background:var(--room-raised); }
    .chat__head strong { font-size:14.5px; letter-spacing:-.01em; color:var(--room-text); }
    .chat__head p { margin:2px 0 0; font-size:12px; color:var(--room-muted); }

    .chat__log { flex:1; overflow-y:auto; padding:16px; display:flex; flex-direction:column; gap:12px; }
    .chat__log::-webkit-scrollbar { width:8px; }
    .chat__log::-webkit-scrollbar-thumb { background:var(--room-line); border-radius:4px; }

    .chat__empty { margin:auto; text-align:center; color:var(--room-muted); font-size:13px; max-width:26ch; }

    /* Flex column rather than display:block on the child — the theme's
       stylesheet was winning that fight and the timestamp ended up jammed
       against the message text. */
    .msg {
        max-width:86%; padding:10px 13px; border-radius:14px; font-size:14px;
        line-height:1.5; display:flex; flex-direction:column; gap:4px;
    }
    .msg--them { background:var(--room-raised); border:1px solid var(--room-line); color:var(--room-text); align-self:flex-start; border-bottom-left-radius:5px; }
    .msg--mine { background:var(--room-accent); color:#fff; align-self:flex-end; border-bottom-right-radius:5px; }
    .msg__who { font-size:11px; opacity:.72; display:block; letter-spacing:.01em; }
    .msg__body { display:block; white-space:pre-wrap; word-break:break-word; }
    .msg--ann {
        align-self:stretch; max-width:100%; font-size:13px;
        background:rgba(255,193,7,.1); border:1px solid rgba(255,193,7,.3); color:#F3D48B;
    }

    .chat__form { display:flex; gap:9px; padding:12px; border-top:1px solid var(--room-line); align-items:flex-end; }
    .chat__form textarea {
        flex:1; resize:none; border-radius:11px; padding:10px 13px; font-size:14px;
        background:var(--room-raised); border:1px solid var(--room-line);
        color:var(--room-text); max-height:110px;
    }
    .chat__form textarea::placeholder { color:#66718A; }
    .chat__form textarea:focus { outline:none; border-color:var(--room-accent); }
    .btn-send {
        border:0; border-radius:11px; padding:10px 18px; font-weight:600; font-size:14px;
        background:var(--room-accent); color:#fff; cursor:pointer; transition:filter .12s;
    }
    .btn-send:hover { filter:brightness(1.08); }

    :focus-visible { outline:2px solid var(--room-accent); outline-offset:2px; }

    @media (prefers-reduced-motion: reduce) {
        .live-pill .dot { animation:none; }
        .btn-join, .btn-cta { transition:none; }
        /* Still appear, just without the movement. */
        .choices, .btn-choice { transition:none; transform:none; }
    }
</style>
@endpush

@section('content')
<div class="room">

    <div class="room-bar">
        <a href="{{ url('/') }}">
            <img src="{{ \App\Support\Asset::v('assets/images/logo/q3_logo-sm.png') }}"
                 alt="Quantum Life" style="max-height:34px;width:auto;">
        </a>
        <div class="room-bar__right">
            <span class="live-pill" id="live-pill" style="{{ $presentation->isLive() ? '' : 'display:none;' }}">
                <span class="dot"></span>LIVE NOW
            </span>
            <span class="who">
                <span class="who__avatar">{{ Str::upper(Str::substr($attendee->name, 0, 1)) }}</span>
                {{ $attendee->name }}
            </span>
        </div>
    </div>

    <div class="room-grid">
        <div>
            <div class="stage-wrap">
                <div class="stage" id="stage">
                    {{-- Deliberately no `controls` attribute. A seek bar would let a
                         guest run ahead of the room, which is the one thing a
                         scheduled showing cannot allow. Volume and fullscreen are
                         offered in the strip underneath instead. --}}
                    {{-- controlsList and disablePictureInPicture close the easy
                         routes to a scrubber; the right-click menu is blocked in
                         JS and, whatever gets through, the player snaps back to
                         the room's position. Belt, braces and a third belt. --}}
                    <video id="player" playsinline preload="none"
                           @if($presentation->isOnDemand()) controls @endif
                           controlslist="nodownload noplaybackrate noremoteplayback"
                           disablepictureinpicture
                           @if($presentation->recording?->thumbnail_path)
                               poster="{{ route('recordings.poster', $presentation->recording) }}"
                           @endif
                    ></video>

                    <div class="stage__overlay" id="overlay">
                        <div class="countdown" id="overlay-clock">--:--</div>
                        <div class="overlay-note" id="overlay-note">Getting ready…</div>
                        {{-- Only appears if the browser refused to start on its
                             own. Normally the presentation just begins. --}}
                        <button type="button" class="btn-join" id="join-btn" style="display:none;">
                            Tap to start
                        </button>
                    </div>
                </div>
            </div>

            <button type="button" class="sound-prompt" id="sound-prompt" style="display:none;">
                Tap for sound
            </button>

            <div class="controls">
                <button type="button" class="ctl" id="mute-btn">Mute</button>
                <input type="range" id="volume" min="0" max="1" step="0.05" value="1" aria-label="Volume">
                <button type="button" class="ctl" id="fs-btn">Fullscreen</button>
                <span style="flex:1;"></span>
                <span class="pos-label" id="position-label"></span>
            </div>

            <h1 class="room-title">{{ $presentation->title }}</h1>
            <p class="room-meta">
                @include('partials.presentation-time', ['presentation' => $presentation])
                @if($host) &middot; Hosted with {{ $host->name }} @endif
            </p>

            @if($presentation->description)
                <p class="room-desc">{{ $presentation->description }}</p>
            @endif

            {{-- Choices placed on the video, each with the moment it appears.
                 Hidden until then, and revealed by the player rather than by a
                 page load — the point is that the ask arrives when the video
                 has just finished making the case for it. --}}
            @if($cues->isNotEmpty())
                <div class="choices" id="choices" hidden>
                    <div class="choices__title" id="choices-title">What would you like to do next?</div>
                    <p class="choices__sub" id="choices-sub" hidden></p>
                    <div class="choices__row">
                        @foreach($cues as $cue)
                            <button type="button" class="btn-choice" hidden
                                    data-cue="{{ $cue['id'] }}"
                                    data-at="{{ $cue['at'] }}"
                                    data-until="{{ $cue['until'] ?? '' }}"
                                    data-kind="{{ $cue['kind'] }}"
                                    data-headline="{{ $cue['headline'] }}"
                                    data-note="{{ $cue['note'] }}"
                                    data-external="{{ $cue['external'] ? '1' : '' }}">
                                {{ $cue['label'] }}
                            </button>
                        @endforeach
                    </div>
                </div>

            {{-- No cues: the showing's own call to action, shown throughout.
                 This reads its configuration rather than assuming recruitment,
                 so "no button" and the customer-facing asks actually work. --}}
            @elseif($cta->isVisible())
                <div class="cta">
                    <div>
                        <div class="cta__title">{{ $cta->headline }}</div>
                        @if($cta->note)
                            <p class="cta__sub">{{ $cta->note }}</p>
                        @endif
                    </div>
                    <button type="button" class="btn-cta" id="cta-button"
                            data-url="{{ $ctaUrl }}"
                            data-external="{{ $cta->opensInNewWindow() ? '1' : '' }}">
                        {{ $cta->label }}
                    </button>
                </div>
            @endif
        </div>

        <div class="chat">
            <div class="chat__head">
                <strong>Questions</strong>
                <p>Goes to the host{{ $host ? ' and '.$host->name : '' }}. Other guests cannot see this.</p>
            </div>
            <div class="chat__log" id="chat-log">
                <div class="chat__empty" id="chat-empty">
                    Ask anything as you watch — someone is here to answer.
                </div>
            </div>
            <form class="chat__form" id="chat-form">
                <textarea id="chat-input" rows="1" maxlength="2000" placeholder="Ask a question…"></textarea>
                <button class="btn-send" type="submit">Send</button>
            </form>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
(function () {
    var URLS = {
        state:     @json(route('presentations.state', $presentation)),
        heartbeat: @json(route('presentations.heartbeat', $presentation)),
        cta:       @json(route('presentations.cta', $presentation)),
        choose:    @json(route('presentations.choose', $presentation)),
        video:     @json(route('presentations.video', $presentation)),
        messages:  @json(route('presentations.messages', $presentation))
    };
    var CSRF = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

    var player   = document.getElementById('player');
    var overlay  = document.getElementById('overlay');
    var clockEl  = document.getElementById('overlay-clock');
    var noteEl   = document.getElementById('overlay-note');
    var joinBtn  = document.getElementById('join-btn');
    var posLabel = document.getElementById('position-label');
    var soundPrompt = document.getElementById('sound-prompt');
    var muteBtn  = document.getElementById('mute-btn');

    /*
     * The server's clock is the only clock. A guest's machine can be minutes
     * out, so the difference between the two is measured once and corrected
     * against from then on — never Date.now() on its own.
     */
    var skew = 0;
    var state = null;
    var playing = false;
    var preloaded = false;
    var lastId = 0;
    var seenAnnouncements = {};

    function serverNow() { return Date.now() + skew; }

    function clock(total) {
        total = Math.max(0, Math.round(total));
        var h = Math.floor(total / 3600), m = Math.floor((total % 3600) / 60), s = total % 60;
        var mm = (h > 0 && m < 10 ? '0' : '') + m;
        return (h > 0 ? h + ':' : '') + mm + ':' + (s < 10 ? '0' : '') + s;
    }

    function onDemand() { return state && state.format === 'on_demand'; }

    /**
     * Where this player should be.
     *
     * A scheduled showing is a shared moment, so the answer is the room's clock
     * and everybody gets the same one. An always-open share is the opposite:
     * it belongs to whoever opened it, so the answer is simply wherever they
     * are — resumed from where they stopped last time.
     */
    function target() {
        if (onDemand()) return player.currentTime || (state ? state.resume_at || 0 : 0);
        if (!state || !state.started_at) return 0;
        return (serverNow() - new Date(state.started_at).getTime()) / 1000;
    }

    /** The furthest point they have earned. Only relevant on-demand. */
    function furthest() {
        return Math.max(state ? state.furthest || 0 : 0, player.currentTime || 0);
    }

    function applyState(data) {
        var was = state && state.status;
        state = data;
        skew = new Date(data.server_now).getTime() - Date.now();

        document.getElementById('live-pill').style.display = data.status === 'live' ? '' : 'none';

        // Going live is handled in place — reloading would throw away the
        // buffer we just spent the countdown filling. Anything else (it ended,
        // it was cancelled) still warrants a fresh page.
        if (was && was !== data.status && !onDemand()) {
            if (data.status === 'live') start(false);
            else window.location.reload();
        }
    }

    function render() {
        if (!state) return;

        if (onDemand()) {
            if (!playing) {
                // No waiting room and no button to press: they opened the link,
                // so it plays. start() drops to muted if the browser refuses
                // sound, and only offers the button if even that is refused.
                clockEl.textContent = '';
                noteEl.textContent = state.resume_at ? 'Picking up where you left off…' : 'Starting…';
                joinBtn.textContent = state.resume_at ? 'Continue watching' : 'Start watching';
                start(false);
            } else {
                posLabel.textContent = clock(player.currentTime) + ' / ' + clock(state.duration);
            }
            return;
        }

        if (state.status === 'scheduled') {
            clockEl.textContent = clock((new Date(state.starts_at).getTime() - serverNow()) / 1000);
            noteEl.textContent  = 'until we begin';
            joinBtn.style.display = 'none';
            preload();
        } else if (state.status === 'live' && !playing) {
            clockEl.textContent = clock(target());
            noteEl.textContent = 'Starting…';
            start(false);
        } else if (state.status !== 'live') {
            overlay.style.display = 'flex';
            clockEl.textContent = '';
            noteEl.textContent = 'This presentation has finished.';
            joinBtn.style.display = 'none';
        }

        if (playing) posLabel.textContent = clock(player.currentTime) + ' / ' + clock(state.duration);
    }

    /**
     * Buffer the opening while people wait.
     *
     * The server opens the file a couple of minutes before the start for
     * exactly this: by the time the room fills, the first stretch is already in
     * the browser and playback begins instead of spinning.
     */
    function preload() {
        if (preloaded || !state || !state.can_preload) return;
        preloaded = true;
        player.preload = 'auto';
        player.src = URLS.video;
        player.load();
    }

    /**
     * Start playing, on our own if the browser allows it.
     *
     * Browsers block audible autoplay without a gesture, and a guest who has
     * been sitting on the countdown may not have given one. Rather than make
     * everybody press a button, try with sound, and on refusal fall back to
     * muted — which is always permitted — so the presentation always begins.
     * Then ask only for the sound, which is a far smaller thing to ask.
     */
    function start(fromGesture) {
        if (playing || !state) return;
        if (!onDemand() && state.status !== 'live') return;

        preload();

        // Pick up where they stopped rather than starting them over. A share
        // watched in two sittings is one viewing.
        player.currentTime = onDemand()
            ? Math.max(0, state.resume_at || 0)
            : Math.max(0, target());

        player.muted = false;

        player.play().then(function () {
            playing = true;
            overlay.style.display = 'none';
            joinBtn.style.display = 'none';
            soundPrompt.style.display = 'none';
        }).catch(function () {
            if (fromGesture) {
                // They tapped and it still refused; nothing left to try.
                noteEl.textContent = 'Your browser blocked playback. Tap again.';
                joinBtn.style.display = '';
                return;
            }

            // Sound was the problem, not playback. Start silently and ask.
            player.muted = true;
            player.play().then(function () {
                playing = true;
                overlay.style.display = 'none';
                soundPrompt.style.display = 'block';
                muteBtn.textContent = 'Unmute';
            }).catch(function () {
                // Even muted playback was refused — offer the button.
                noteEl.textContent = 'Ready to start.';
                joinBtn.style.display = '';
            });
        });
    }

    /**
     * Hold the playhead where the room is.
     *
     * Under a second is left alone: correcting is more noticeable than the
     * drift. One to five seconds is closed by nudging the playback rate, which
     * nobody perceives. Past five seconds the gap is too wide to close smoothly
     * -- usually a backgrounded tab -- so it jumps.
     */
    function correct() {
        // Nothing to correct on an always-open share: there is no room to be
        // out of step with.
        if (onDemand()) return;
        if (!playing || !state || state.status !== 'live') return;

        var drift = player.currentTime - target();
        var size  = Math.abs(drift);

        if (size > 5) {
            player.currentTime = Math.max(0, target());
            player.playbackRate = 1;
        } else if (size > 1) {
            player.playbackRate = drift < 0 ? 1.03 : 0.97;
        } else {
            player.playbackRate = 1;
        }
    }

    /*
     * Keep the room in charge of the playhead.
     *
     * A guest can right-click a video and turn the native controls on, which
     * hands them a scrub bar and a pause button. Blocking the context menu
     * stops the casual case; these listeners handle the rest, so even with
     * controls showing, seeking snaps back and pausing resumes. The room is a
     * shared moment — running ahead to the offer, or freezing on it, is the one
     * thing it cannot allow.
     */
    function guardPlayhead() {
        player.addEventListener('contextmenu', function (e) { e.preventDefault(); });

        player.addEventListener('seeking', function () {
            if (!playing || !state) return;

            /*
             * On an always-open share, going back over something is fine and
             * blocking it would be hostile — it is their video, at their pace.
             * Skipping *ahead* of what they have watched is not: the order the
             * case is made in is the whole point. So the ceiling is the
             * furthest they have actually reached.
             */
            if (onDemand()) {
                var ceiling = furthest() + 2;
                if (player.currentTime > ceiling) player.currentTime = ceiling;
                return;
            }

            if (state.status !== 'live') return;
            if (Math.abs(player.currentTime - target()) > 1.5) {
                player.currentTime = Math.max(0, target());
            }
        });

        player.addEventListener('pause', function () {
            // Pausing an always-open share is entirely reasonable.
            if (onDemand()) return;
            if (!playing || !state || state.status !== 'live') return;
            if (player.ended) return;
            // Resume on the next tick so we are not fighting the browser's own
            // pause during a buffer stall.
            setTimeout(function () {
                if (player.paused && playing) player.play().catch(function () {});
            }, 120);
        });

        player.addEventListener('ratechange', function () {
            // Only our own drift correction may touch the rate.
            if (player.playbackRate > 1.05 || player.playbackRate < 0.95) player.playbackRate = 1;
        });
    }

    function beat() {
        var body = new FormData();
        body.append('position', Math.max(0, Math.round(playing ? player.currentTime : target())));

        fetch(URLS.heartbeat, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' },
            body: body, credentials: 'same-origin'
        }).then(function (r) { return r.ok ? r.json() : null; })
          .then(function (d) { if (d) applyState(d); })
          .catch(function () { /* a dropped beat is not worth surfacing */ });
    }

    // ── Chat ──────────────────────────────────────────────────────────────
    var log = document.getElementById('chat-log');

    /** Drop the "ask anything" placeholder as soon as there is real content. */
    function clearEmpty() {
        var empty = document.getElementById('chat-empty');
        if (empty) empty.remove();
    }

    function bubble(m) {
        var el = document.createElement('div');
        el.className = 'msg ' + (m.mine ? 'msg--mine' : 'msg--them');
        var who = document.createElement('span');
        who.className = 'msg__who';
        who.textContent = m.from + ' · ' + m.at;

        // Its own element rather than a bare text node, so it can be given
        // room of its own and wrap properly on a long message.
        var body = document.createElement('span');
        body.className = 'msg__body';
        body.textContent = m.body;

        el.appendChild(who);
        el.appendChild(body);
        return el;
    }

    function announcement(a) {
        var el = document.createElement('div');
        el.className = 'msg msg--ann';
        el.textContent = a.body;
        return el;
    }

    function pollChat() {
        fetch(URLS.messages + (lastId ? '?after=' + lastId : ''), {
            credentials: 'same-origin', headers: { 'Accept': 'application/json' }
        }).then(function (r) { return r.ok ? r.json() : null; })
          .then(function (d) {
              if (!d) return;
              var atBottom = log.scrollHeight - log.scrollTop - log.clientHeight < 60;

              if ((d.announcements || []).length || d.messages.length) clearEmpty();

              (d.announcements || []).forEach(function (a) {
                  if (seenAnnouncements[a.id]) return;
                  seenAnnouncements[a.id] = true;
                  log.appendChild(announcement(a));
              });

              d.messages.forEach(function (m) {
                  if (m.id > lastId) lastId = m.id;
                  log.appendChild(bubble(m));
              });

              if (atBottom) log.scrollTop = log.scrollHeight;
          })
          .catch(function () {});
    }

    document.getElementById('chat-form').addEventListener('submit', function (e) {
        e.preventDefault();
        var input = document.getElementById('chat-input');
        var body  = input.value.trim();
        if (!body) return;

        input.value = '';
        var payload = new FormData();
        payload.append('body', body);

        fetch(URLS.messages, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' },
            body: payload, credentials: 'same-origin'
        }).then(function (r) { return r.ok ? r.json() : null; })
          .then(function (m) {
              if (!m) return;
              clearEmpty();
              if (m.id > lastId) lastId = m.id;
              log.appendChild(bubble(m));
              log.scrollTop = log.scrollHeight;
          })
          .catch(function () { input.value = body; });
    });

    // ── Controls ──────────────────────────────────────────────────────────
    joinBtn.addEventListener('click', function () { start(true); });

    soundPrompt.addEventListener('click', function () {
        player.muted = false;
        player.volume = 1;
        muteBtn.textContent = 'Mute';
        soundPrompt.style.display = 'none';
    });

    document.getElementById('mute-btn').addEventListener('click', function () {
        player.muted = !player.muted;
        this.textContent = player.muted ? 'Unmute' : 'Mute';
    });
    document.getElementById('volume').addEventListener('input', function () {
        player.volume = parseFloat(this.value);
        player.muted = false;
    });
    document.getElementById('fs-btn').addEventListener('click', function () {
        if (document.fullscreenElement) document.exitFullscreen();
        else if (document.getElementById('stage').requestFullscreen) document.getElementById('stage').requestFullscreen();
    });

    player.addEventListener('play', correct);
    guardPlayhead();

    var cta = document.getElementById('cta-button');
    if (cta) {
        cta.addEventListener('click', function () {
            var url = cta.getAttribute('data-url');

            if (cta.getAttribute('data-external') !== '1') {
                // Going there for good, so the click has to be logged before we
                // leave. keepalive lets the request outlive the page.
                fetch(URLS.cta, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' },
                    credentials: 'same-origin', keepalive: true
                }).catch(function () {});

                window.location = url;
                return;
            }

            // Open first: a popup blocked because it waited on a fetch is worse
            // than a log entry we might miss.
            window.open(url, '_blank', 'noopener,width=1100,height=800');
            fetch(URLS.cta, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' },
                credentials: 'same-origin'
            }).catch(function () {});
        });
    }

    /*
     * Choices that arrive on cue.
     *
     * Each option carries the second it becomes due. The panel rises once, the
     * first time anything is due, and options fade in individually after that —
     * so a video that offers three things at three different moments builds up
     * rather than dropping a wall of buttons on somebody.
     *
     * Driven off the playhead, not a wall clock: on a scheduled showing the two
     * agree, and on an always-open share the playhead is the only truth.
     */
    var choicesEl = document.getElementById('choices');
    var choiceBtns = choicesEl
        ? Array.prototype.slice.call(choicesEl.querySelectorAll('.btn-choice'))
        : [];
    var panelShown = false;

    function num(value) {
        if (value === null || value === undefined || value === '') return null;
        var n = parseInt(value, 10);
        return isNaN(n) ? null : n;
    }

    function renderChoices() {
        if (!choicesEl || !choiceBtns.length) return;

        var at = player.currentTime || 0;
        var anyDue = false;

        choiceBtns.forEach(function (btn) {
            var from  = num(btn.getAttribute('data-at')) || 0;
            var until = num(btn.getAttribute('data-until'));
            var due   = at >= from && (until === null || at < until);

            if (due && btn.hidden) {
                btn.hidden = false;
                // Next frame, so the browser has a hidden→visible state to
                // transition from rather than painting it already in place.
                requestAnimationFrame(function () { btn.classList.add('is-in'); });
            } else if (!due && !btn.hidden) {
                btn.hidden = true;
                btn.classList.remove('is-in');
            }

            if (due) anyDue = true;
        });

        if (anyDue && !panelShown) {
            panelShown = true;
            choicesEl.hidden = false;
            requestAnimationFrame(function () { choicesEl.classList.add('is-in'); });

            // Borrow the wording from whichever option is leading the moment.
            var lead = choiceBtns.find(function (b) { return !b.hidden; });
            var headline = lead && lead.getAttribute('data-headline');
            var note     = lead && lead.getAttribute('data-note');

            if (headline) document.getElementById('choices-title').textContent = headline;
            if (note) {
                var sub = document.getElementById('choices-sub');
                sub.textContent = note;
                sub.hidden = false;
            }
        } else if (!anyDue && panelShown) {
            panelShown = false;
            choicesEl.classList.remove('is-in');
            choicesEl.hidden = true;
        }
    }

    choiceBtns.forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (btn.disabled) return;

            var external = btn.getAttribute('data-external') === '1';

            /*
             * A window opened from inside a fetch callback is a popup as far as
             * the browser is concerned, and gets blocked. So open it now, while
             * we still hold the click, and point it at its real destination
             * once the server answers.
             */
            var opened = external
                ? window.open('', '_blank', 'noopener,width=1100,height=800')
                : null;

            btn.disabled = true;

            var body = new FormData();
            body.append('cue', btn.getAttribute('data-cue'));
            body.append('position', Math.max(0, Math.round(player.currentTime || 0)));

            fetch(URLS.choose, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' },
                body: body, credentials: 'same-origin'
            }).then(function (r) { return r.ok ? r.json() : null; })
              .then(function (d) {
                  if (!d || !d.url) {
                      btn.disabled = false;
                      if (opened) opened.close();
                      return;
                  }

                  if (external) {
                      if (opened) opened.location = d.url;
                      else window.open(d.url, '_blank', 'noopener');
                      btn.disabled = false;
                      return;
                  }

                  // A branch is the same journey continuing, so it replaces the
                  // page rather than stacking another tab on them.
                  window.location = d.url;
              })
              .catch(function () {
                  btn.disabled = false;
                  if (opened) opened.close();
              });
        });
    });

    fetch(URLS.state, { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (d) { applyState(d); render(); });

    setInterval(renderChoices, 500);
    setInterval(render, 500);
    setInterval(correct, 15000);
    beat();
    setInterval(beat, 20000);
    pollChat();
    setInterval(pollChat, 5000);
})();
</script>
@endpush
