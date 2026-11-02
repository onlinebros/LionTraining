@extends('microsite.layout')

@section('title', $account->siteTitle())
@section('body-class', $preview && request()->boolean('embed') ? 'editing' : '')

@php
    $live = ! $preview && $member !== null;
    $ref = $account->site_ref;
    $md = fn (?string $text) => \Illuminate\Support\Str::markdown((string) $text, ['html_input' => 'escape', 'allow_unsafe_links' => false]);
    $vendors = $sections->map(fn ($s) => $s->offering())->filter()->unique('id');
    // The first section opens the site when it is a hero.
    $hero = $sections->first()?->layout === 'hero' ? $sections->first() : null;
    $rest = $hero ? $sections->slice(1) : $sections;
    $h = $hero?->c() ?? [];
    $headline = ($h['headline'] ?? '') ?: $account->siteTitle();
    $words = preg_split('/\s+/', $headline);
    $tail = count($words) > 2 ? implode(' ', array_splice($words, -2)) : null;
@endphp

@section('body')
<div class="progress" aria-hidden="true"></div>
<header class="top">
    <div class="wrap">
        <span class="brand">
            @if($account->logo_path)
                <img class="client-logo" src="{{ $preview ? route('admin.proposal-studio.accounts.logo', $account) : route('microsite.logo', $ref) }}?v={{ $account->updated_at?->timestamp }}" alt="{{ $account->name }}">
            @else
                <span>{{ $account->name }}</span>
            @endif
            <span class="x" aria-hidden="true">×</span>
            <span class="q3"><img src="{{ \App\Support\Asset::v('assets/images/logo/q3-app-icon-192.png') }}" alt=""><span>Quantum 3 Solution</span></span>
        </span>
        @if($rest->count() > 1)
            <nav aria-label="Sections">
                @foreach($rest as $section)<a href="#s-{{ $section->id }}" data-nav="{{ $section->id }}">{{ \Illuminate\Support\Str::limit($section->title, 28) }}</a>@endforeach
                @if($live)<a href="#colleagues">Add a colleague</a>@endif
            </nav>
        @endif
        @if($live)
            <form method="POST" action="{{ route('microsite.sign-out', $ref) }}" class="who">
                @csrf {{ $member->name ?: $member->email }} · <button type="submit">Sign out</button>
            </form>
        @elseif($preview && ! request()->boolean('embed'))
            <span class="who">Preview: drafts shown and marked</span>
        @endif
    </div>
</header>

<main>
    <section class="hero" @if($hero) id="s-{{ $hero->id }}" data-track="{{ $hero->id }}" @if($preview) data-edit="{{ $hero->id }}" @endif @endif>
        <div class="orb o1"></div><div class="orb o2"></div><div class="orb o3"></div><div class="grid-bg"></div>
        <div class="wrap">
            @if($hero && $preview && ! $hero->isPublished())<span class="tag">Draft: not on the site yet</span>@endif
            <span class="eyebrow rv">{{ ($h['eyebrow'] ?? '') ?: 'Prepared for '.$account->name }}</span>
            <h1 class="rv" style="--i: 1">@if($tail){{ implode(' ', $words) }} <span class="grad">{{ $tail }}</span>@else<span class="grad">{{ $headline }}</span>@endif</h1>
            @if(($h['lead'] ?? '') !== '' || $account->site_intro)
                <p class="lead rv" style="--i: 2">{{ ($h['lead'] ?? '') ?: $account->site_intro }}</p>
            @endif
            @if(!empty($h['highlights']))
                <div class="chips rv" style="--i: 3">@foreach($h['highlights'] as $chip)<span class="chip"><i></i>{{ $chip }}</span>@endforeach</div>
            @endif
            <div class="meta rv" style="--i: 4">
                <span>Prepared for {{ $account->name }}</span>
                <span>{{ ($sections->max('published_at') ?? now())->format('F Y') }}</span>
                <span>Private: only invited people can open this page</span>
            </div>
        </div>
        <a class="cue" href="#s-{{ $rest->first()?->id }}" aria-label="Scroll to the first section"></a>
    </section>

    @foreach($rest as $section)
        @include('microsite._section')
    @endforeach

    @if($sections->isEmpty())
        <section class="sec"><div class="wrap"><p class="muted">We are preparing this site for you now. Come back soon.</p></div></section>
    @endif

    @if($progress->isNotEmpty())
        <section class="sec">
            <div class="wrap">
                <div class="sec-head rv"><span class="eyebrow">Coming next</span><h2>Still being prepared</h2><p class="lead">We are working on these for you, and will add them here.</p></div>
                <div class="chips rv">@foreach($progress as $title)<span class="chip"><i style="background: var(--text-dim); box-shadow: none;"></i>{{ $title }}</span>@endforeach</div>
            </div>
        </section>
    @endif

    @if($live)
        <section class="sec" id="colleagues">
            <div class="wrap">
                <div class="sec-head rv"><span class="eyebrow">Your team</span><h2>Add a colleague</h2><p class="lead">Please do not forward your link: it signs in as you. Tell us who else should see this, and we will send them their own once we have approved it.</p></div>
                <form method="POST" action="{{ route('microsite.request-access', $ref) }}" class="stack rv" style="max-width: 760px;">
                    @csrf
                    <div class="grid2">
                        <div><label for="rq-name">Their name</label><input id="rq-name" type="text" name="name" maxlength="255" value="{{ old('name') }}"></div>
                        <div><label for="rq-email">Their email</label><input id="rq-email" type="email" name="email" maxlength="255" required value="{{ old('email') }}"></div>
                    </div>
                    <div><label for="rq-note">Their role, or why they should see it <span class="muted">(optional)</span></label><input id="rq-note" type="text" name="note" maxlength="2000" value="{{ old('note') }}"></div>
                    <div><button class="btn" type="submit">Ask for access</button></div>
                </form>
            </div>
        </section>
    @endif
</main>

<footer>
    <div class="wrap">
        Prepared for {{ $account->name }} by Quantum 3 Solution, an independent referral partner.
        @foreach($vendors as $offering){{ $offering->name }} is made and sold by {{ $offering->vendor_name }}, which provides the formal quote and is the seller of record. @endforeach
        @if($vendors->isNotEmpty())Statements about product performance are the maker's, quoted from their material.@endif
        @if($account->logo_path){{ $account->name }}'s name and logo are shown for them only, on this private site.@endif
    </div>
</footer>

@if(session('success'))<div class="flash ok" role="status">{{ session('success') }}</div>@endif
@if(session('error'))<div class="flash err" role="alert">{{ session('error') }}</div>@endif
@if($errors->any())<div class="flash err" role="alert">{{ $errors->first() }}</div>@endif
@endsection

@push('scripts')
<script>
(function () {
    var reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var progress = document.querySelector('.progress');

    // Reveal on scroll.
    var io = new IntersectionObserver(function (entries) {
        entries.forEach(function (e) { if (e.isIntersecting) { e.target.classList.add('in'); io.unobserve(e.target); countUp(e.target); } });
    }, { rootMargin: '0px 0px -8% 0px', threshold: 0.12 });
    document.querySelectorAll('.rv, .step').forEach(function (el) { io.observe(el); });

    // Numbers in claims count up to exactly what the quote says.
    function countUp(root) {
        if (reduce) return;
        root.querySelectorAll('.n').forEach(function (el) {
            var final = el.textContent, clean = final.replace(/,/g, ''), to = parseFloat(clean);
            if (!isFinite(to) || to === 0) return;
            var decimals = (clean.split('.')[1] || '').length, commas = final.indexOf(',') !== -1, start = null;
            function fmt(v) { var s = v.toFixed(decimals); return commas ? s.replace(/\B(?=(\d{3})+(?!\d))/g, ',') : s; }
            function tick(t) {
                if (start === null) start = t;
                var p = Math.min(1, (t - start) / 1400), eased = 1 - Math.pow(1 - p, 3);
                el.textContent = p < 1 ? fmt(to * eased) : final;
                if (p < 1) requestAnimationFrame(tick);
            }
            requestAnimationFrame(tick);
        });
    }

    // Scroll progress, the timeline's fill and the current section in the nav.
    var timelines = document.querySelectorAll('.timeline'), navLinks = document.querySelectorAll('[data-nav]');
    function onScroll() {
        var h = document.documentElement, max = h.scrollHeight - h.clientHeight;
        if (progress) progress.style.setProperty('--p', max > 0 ? (h.scrollTop / max).toFixed(4) : 0);
        timelines.forEach(function (t) {
            var r = t.getBoundingClientRect(), vh = window.innerHeight;
            t.style.setProperty('--fill', Math.max(0, Math.min(1, (vh * 0.65 - r.top) / r.height)).toFixed(3));
        });
        var current = null;
        document.querySelectorAll('section[data-track]').forEach(function (s) { if (s.getBoundingClientRect().top < 140) current = s.dataset.track; });
        navLinks.forEach(function (a) { a.classList.toggle('on', a.dataset.nav === current); });
    }
    window.addEventListener('scroll', onScroll, { passive: true }); onScroll();

    // A soft light follows the pointer over cards.
    document.querySelectorAll('.card').forEach(function (card) {
        card.addEventListener('pointermove', function (e) {
            var r = card.getBoundingClientRect();
            card.style.setProperty('--mx', (e.clientX - r.left) + 'px'); card.style.setProperty('--my', (e.clientY - r.top) + 'px');
        });
    });

    // Rings fill from empty when they come into view.
    document.querySelectorAll('[data-ring] .bar').forEach(function (bar) {
        if (reduce) return;
        var target = bar.getAttribute('stroke-dashoffset'); bar.setAttribute('stroke-dashoffset', '502.65');
        new IntersectionObserver(function (es, o) { if (es[0].isIntersecting) { bar.setAttribute('stroke-dashoffset', target); o.disconnect(); } }, { threshold: 0.4 }).observe(bar);
    });

    @if($live)
    // Ticking a step of the action plan without leaving the page.
    document.querySelectorAll('form[data-tick]').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var row = form.closest('.pstep'), input = form.querySelector('[name=done]'), done = input.value === '1';
            row.classList.toggle('done', done);
            fetch(form.action, { method: 'POST', body: new FormData(form), headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
                .then(function (r) { if (!r.ok) throw r; return r.json(); })
                .then(function (s) {
                    input.value = done ? '0' : '1';
                    form.querySelector('button').setAttribute('aria-pressed', done ? 'true' : 'false');
                    var plan = form.closest('.plan');
                    plan.querySelector('[data-done]').textContent = s.done + '/' + s.total;
                    plan.querySelector('.bar').setAttribute('stroke-dashoffset', (502.65 * (1 - s.done / Math.max(1, s.total))).toFixed(2));
                })
                .catch(function () { row.classList.toggle('done', !done); });
        });
    });

    // Reading time per section, so we know what mattered to them.
    var seen = {}, visible = {}, last = Date.now();
    var watch = new IntersectionObserver(function (es) { es.forEach(function (e) { visible[e.target.dataset.track] = e.isIntersecting; }); }, { threshold: 0.35 });
    document.querySelectorAll('[data-track]').forEach(function (s) { watch.observe(s); });
    setInterval(function () {
        var now = Date.now(), secs = (now - last) / 1000; last = now;
        if (document.visibilityState !== 'visible' || secs > 5) return;
        Object.keys(visible).forEach(function (id) { if (visible[id]) seen[id] = (seen[id] || 0) + secs; });
    }, 1000);
    function flush() {
        var out = {}, any = false;
        Object.keys(seen).forEach(function (id) { var s = Math.round(seen[id]); if (s > 0) { out[id] = s; any = true; } });
        if (!any) return;
        seen = {};
        var data = new FormData(); data.append('_token', @json(csrf_token())); data.append('seconds', JSON.stringify(out));
        navigator.sendBeacon(@json(route('microsite.reading', $ref)), data);
    }
    setInterval(flush, 20000);
    document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'hidden') flush(); });
    window.addEventListener('pagehide', flush);
    @endif

    @if($preview && request()->boolean('embed'))
    // Inside the builder: clicking a section opens it there, and the builder can scroll us.
    document.addEventListener('click', function (e) {
        var s = e.target.closest('[data-edit]');
        if (!s) return;
        e.preventDefault();
        window.parent.postMessage({ type: 'ms-select', id: Number(s.dataset.edit) }, location.origin);
    }, true);
    window.addEventListener('message', function (e) {
        if (e.origin !== location.origin || !e.data || e.data.type !== 'ms-focus') return;
        document.querySelectorAll('.picked').forEach(function (p) { p.classList.remove('picked'); });
        var s = document.querySelector('[data-edit="' + e.data.id + '"]');
        // Scroll only this frame; scrollIntoView would scroll the builder page too.
        if (s) { s.classList.add('picked'); window.scrollTo({ top: s.getBoundingClientRect().top + scrollY - 64, behavior: e.data.instant ? 'instant' : 'smooth' }); }
    });
    window.parent.postMessage({ type: 'ms-ready' }, location.origin);
    @endif
})();
</script>
@endpush
