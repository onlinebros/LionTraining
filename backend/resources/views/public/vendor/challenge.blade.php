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

        <div class="q3-cg-board" id="cg-board"
             data-seconds="30"
             data-pro-label="PlasmaGuard PRO">

            <div class="q3-cg-timer" aria-live="off">
                <span class="q3-cg-timer-n" id="cg-time">30</span><span class="q3-cg-timer-u">s</span>
            </div>

            <div class="q3-cg-arenas">
                <div class="q3-cg-arena q3-cg-arena--pro">
                    <div class="q3-cg-head">
                        <span class="q3-cg-who">PlasmaGuard PRO</span>
                        <span class="q3-cg-score" id="cg-pro-score">0</span>
                    </div>
                    <canvas id="cg-pro" aria-label="The PlasmaGuard PRO's ions clearing germs"></canvas>
                    <div class="q3-cg-load" title="Germs still on the surface"><span id="cg-pro-load"></span></div>
                    <div class="q3-cg-caption">Ions on their own, nonstop</div>
                </div>

                <div class="q3-cg-arena q3-cg-arena--you">
                    <div class="q3-cg-head">
                        <span class="q3-cg-who">You</span>
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
                    <button type="button" class="q3-sf-btn q3-sf-btn--gold" id="cg-start">Start the challenge</button>
                </div>

                <div class="q3-cg-panel q3-cg-panel--count" data-panel="count" hidden>
                    <div class="q3-cg-count" id="cg-count">3</div>
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
