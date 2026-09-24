@extends('layouts.public')
@section('title', 'Can you keep up with the '.$product['name'].'? — presented by '.$member->name)
@section('body-class', 'q3-theme q3-storefront')

{{--
    The challenge: the visitor wiping germs by hand, side by side with the
    product's ions clearing the same germs. Both arenas get the identical
    spawn sequence, so the only difference is who is doing the cleaning.

    It is a game and says so. It prints no figure about the product and makes
    no claim of its own — the manufacturer's test results live on the product
    page, verbatim, and the result screen points there.

    Game logic is assets/js/q3-challenge.js; styles are q3-storefront.css §15.
--}}

@push('meta')
@php
    $ogTitle = 'Can you beat the PlasmaGuard PRO?';
    $ogDesc  = '30 seconds. Same germs. You tap — the PRO’s ions do the rest. Think you can keep up?';
    $ogImage = url(\App\Support\Asset::v('assets/images/og/plasmaguard-challenge.jpg'));
@endphp
{{-- What a phone or a social feed shows when this link is shared: the
     challenge, not the site's app icon. The image is 1200×630, built from
     the game's own look — no manufacturer photography in it. --}}
<meta name="description" content="{{ $ogDesc }}">
<meta property="og:type" content="website">
<meta property="og:site_name" content="Quantum 3 Solution">
<meta property="og:title" content="{{ $ogTitle }}">
<meta property="og:description" content="{{ $ogDesc }}">
<meta property="og:url" content="{{ url()->current() }}">
<meta property="og:image" content="{{ $ogImage }}">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:image:alt" content="Can you beat the PlasmaGuard PRO? A 30-second germ challenge.">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="{{ $ogTitle }}">
<meta name="twitter:description" content="{{ $ogDesc }}">
<meta name="twitter:image" content="{{ $ogImage }}">
@endpush

@push('styles')
<link rel="stylesheet" type="text/css" href="{{ \App\Support\Asset::v('assets/css/q3-storefront.css') }}">
@endpush

@section('content')

@php
    $siteName   = \App\Models\SiteSetting::get('site_name');
    $q3Logo     = \App\Support\Asset::v('assets/images/logo/q3_logo-web.png');
    $productUrl = route('vendor.product', [$member->referral_code, $vendorSlug, $productKey]);
    $orderUrl   = $productUrl.'#enquire';
@endphp

<header class="q3-sf-brandbar">
    <div class="q3-sf-wrap">
        <a href="{{ $productUrl }}">
            <img class="q3-sf-logo" src="{{ $q3Logo }}" alt="{{ $siteName }}">
        </a>
        <div class="q3-sf-presenter">
            Presented by <strong>{{ $member->name }}</strong><br>
            <span class="q3-sf-pill">Authorised Partner</span>
        </div>
    </div>
</header>

<section class="q3-sf-section q3-cg">
    <div class="q3-sf-wrap">
        <div class="q3-sf-eyebrow">The challenge</div>
        <h1 class="q3-cg-title">Can you keep up with the <span class="q3-sf-gilt">PlasmaGuard&nbsp;PRO</span>?</h1>
        <p class="q3-sf-lede">
            Same room, same germs, same moment. On one side the PRO's ions go after them on their
            own. On the other, it's just you. Tap every germ before it spreads — for thirty seconds.
        </p>

        <div class="q3-cg-board" id="cg-board" data-phase="intro"
             data-seconds="30"
             data-pro-label="PlasmaGuard PRO">

            <div class="q3-cg-timer" aria-live="off">
                <span class="q3-cg-timer-n" id="cg-time">30</span><span class="q3-cg-timer-u">s</span>
            </div>

            <div class="q3-cg-arenas">
                <div class="q3-cg-arena q3-cg-arena--pro">
                    <div class="q3-cg-head">
                        <span class="q3-cg-who">PlasmaGuard PRO <span class="q3-cg-tag">Automatic</span></span>
                        <span class="q3-cg-score" id="cg-pro-score">0</span>
                    </div>
                    <canvas id="cg-pro" aria-label="The PlasmaGuard PRO's ions clearing germs"></canvas>
                    <div class="q3-cg-load" title="Germs still on the surface"><span id="cg-pro-load"></span></div>
                    <div class="q3-cg-caption">Ions on their own, nonstop</div>
                </div>

                <div class="q3-cg-arena q3-cg-arena--you">
                    <div class="q3-cg-head">
                        <span class="q3-cg-who">You <span class="q3-cg-tag q3-cg-tag--you">You play here</span></span>
                        <span class="q3-cg-score" id="cg-you-score">0</span>
                    </div>
                    <canvas id="cg-you" aria-label="Your surface: tap each germ to wipe it"></canvas>
                    <div class="q3-cg-load" title="Germs still on the surface"><span id="cg-you-load"></span></div>
                    <div class="q3-cg-caption">Tap each germ to wipe it</div>
                </div>
            </div>

            {{-- Intro, countdown and result all live in one overlay; the
                 script swaps which panel shows. --}}
            <div class="q3-cg-overlay" id="cg-overlay">
                <div class="q3-cg-panel" data-panel="intro">
                    <div class="q3-sf-eyebrow">30 seconds · fewest germs left wins</div>
                    <h2>You vs. the PRO</h2>
                    <ul>
                        <li>Germs appear on both surfaces at the same time.</li>
                        <li>Tap yours to wipe them. The PRO's ions handle its side.</li>
                        <li>Leave one too long and it splits in two.</li>
                        <li>10 points a germ — but whoever leaves fewer behind wins.</li>
                    </ul>
                    {{-- Who is taking the PRO on. Goes to the sharing partner's
                         CRM for follow-up (ChallengeToCrm); the line under the
                         button says so, in plain words. --}}
                    <form class="q3-cg-entry" id="cg-entry" novalidate
                          action="{{ route('vendor.challenge.enter', [$member->referral_code, $vendorSlug, $productKey]) }}"
                          data-result="{{ route('vendor.challenge.result', [$member->referral_code, $vendorSlug, $productKey]) }}">
                        <div class="q3-sf-hp" aria-hidden="true">
                            <label for="cg-website">Website</label>
                            <input type="text" id="cg-website" name="website_url" tabindex="-1" autocomplete="off">
                        </div>
                        <div class="q3-cg-fields">
                            <input type="text" name="first_name" id="cg-name" maxlength="60" required
                                   placeholder="First name" autocomplete="given-name" aria-label="First name">
                            <input type="email" name="email" id="cg-email" maxlength="190" required
                                   placeholder="Email" autocomplete="email" inputmode="email" aria-label="Email">
                        </div>
                        <div class="q3-cg-err" id="cg-err" role="alert" hidden></div>
                        <button type="submit" class="q3-sf-btn q3-sf-btn--gold q3-sf-btn--block" id="cg-start">Start the challenge</button>
                        <p class="q3-cg-consent">
                            Your name and email go to {{ $member->name }}, the independent partner who shared this
                            page, and the {{ $siteName }} team, so they can follow up about the PlasmaGuard PRO.
                        </p>
                    </form>
                </div>

                <div class="q3-cg-panel q3-cg-panel--count" data-panel="count" hidden>
                    <div class="q3-cg-count" id="cg-count">3</div>
                </div>

                <div class="q3-cg-panel q3-cg-panel--count" data-panel="over" hidden>
                    <div class="q3-cg-count q3-cg-over">Time’s up!</div>
                </div>

                <div class="q3-cg-panel" data-panel="result" hidden>
                    <div class="q3-sf-eyebrow">Final score</div>
                    <h2 id="cg-verdict">The PRO wins.</h2>
                    <div class="q3-cg-final">
                        <div>
                            <div class="q3-cg-final-n q3-cg-final-n--pro" id="cg-final-pro">0</div>
                            <div class="q3-cg-final-l">PlasmaGuard PRO</div>
                            <div class="q3-cg-final-s" id="cg-left-pro"></div>
                        </div>
                        <div>
                            <div class="q3-cg-final-n" id="cg-final-you">0</div>
                            <div class="q3-cg-final-l">You</div>
                            <div class="q3-cg-final-s" id="cg-left-you"></div>
                        </div>
                    </div>
                    <p class="q3-cg-sum" id="cg-summary"></p>
                    <a class="q3-sf-btn q3-sf-btn--gold q3-sf-btn--block" href="{{ $orderUrl }}">Order the PlasmaGuard PRO</a>
                    <div class="q3-cg-again">
                        <button type="button" class="q3-sf-btn q3-sf-btn--ghost" id="cg-again">Play again</button>
                        <a class="q3-sf-btn q3-sf-btn--ghost" href="{{ $productUrl }}">Back to the product</a>
                    </div>
                </div>
            </div>
        </div>

        <p class="q3-cg-note">
            A game, not a lab result — it illustrates the idea, it doesn't measure anything.
            {{ $vendor['name'] }}'s own test figures are on the <a href="{{ $productUrl }}#specs">product page</a>.
        </p>
    </div>
</section>

<footer class="q3-sf-footer">
    <div class="q3-sf-wrap">
        <img class="q3-sf-footer-logo" src="{{ $q3Logo }}" alt="{{ $siteName }}">
        <p>
            <strong>{{ $product['name'] }}</strong> is manufactured and sold by
            <strong>{{ $vendor['legal_name'] ?? $vendor['name'] }}</strong>.
            {{ $member->name }} is an independent {{ $siteName }} partner and is paid a referral
            commission by {{ $vendor['name'] }} on completed orders. This page is not operated by
            {{ $vendor['name'] }}.
        </p>
    </div>
</footer>

@endsection

@push('scripts')
<script src="{{ \App\Support\Asset::v('assets/js/q3-challenge.js') }}" defer></script>
@endpush
