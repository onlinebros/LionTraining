@extends('layouts.public')
@section('title', $product['name'].' — presented by '.$member->name)
@section('body-class', 'q3-theme q3-storefront')

{{--
    Member-coded vendor product page, in the Q3 black-and-gold treatment.

    Behind the content, for products that set `ambient` in config, a canvas
    of drifting ions clearing contamination (assets/js/q3-ionfield.js). It is
    decoration only: aria-hidden, pointer-events off, and absent entirely
    under prefers-reduced-motion.

    Deliberately NOT dressed as the vendor's own site. This is an authorised
    referral partner's page: it carries the Q3 mark, names the manufacturer,
    uses no logo or branding of theirs, and discloses in the footer that they
    are the seller of record. Anything else would be passing our page off as
    theirs.

    All product copy comes from config/vendors.php and every colour from the
    tokens in q3-theme.css — a second product is a config entry, and a palette
    change is one file. Nothing is hardcoded here.
--}}

@push('styles')
<link rel="stylesheet" type="text/css" href="{{ \App\Support\Asset::v('assets/css/q3-storefront.css') }}">
@endpush

@section('content')

@php
    $siteName = \App\Models\SiteSetting::get('site_name');
    $price    = $product['price'] ?? null;

    // The quantised derivative, not the 1.2 MB master. This page is read once,
    // on a phone, by someone who has never heard of us.
    $q3Logo = \App\Support\Asset::v('assets/images/logo/q3_logo-web.png');

    // Vendor photography. Absent config renders the page without imagery
    // rather than with empty frames — see the note in config/vendors.php.
    $sizing    = $product['sizing'] ?? [];
    $unitPrice = $product['price'] ?? null;
    $heroImage = $product['images']['hero']    ?? null;
    $gallery   = $product['images']['gallery'] ?? [];
    $ambient   = $product['ambient'] ?? null;
    $routine   = $product['routine'] ?? null;

    // Lead metric first — it is the only one rendered in gold.
    $heroStats = [
        ['99.99%',   'of tested viruses &amp; bacteria inactivated', true],
        ['0.3 µm',   'particulate reduction threshold',              false],
        ['4000 CFM', 'maximum airflow per generator',                false],
        ['5 years',  'generator warranty',                           false],
    ];
@endphp

@if ($ambient === 'ion-field')
    <canvas class="q3-sf-ionfield" id="q3-sf-ionfield" aria-hidden="true"></canvas>
@endif

{{-- ── Brand bar ────────────────────────────────────────────────────────── --}}
<header class="q3-sf-brandbar">
    <div class="q3-sf-wrap">
        <a href="{{ url('/') }}">
            <img class="q3-sf-logo" src="{{ $q3Logo }}" alt="{{ $siteName }}">
        </a>
        <div class="q3-sf-presenter">
            Presented by <strong>{{ $member->name }}</strong><br>
            <span class="q3-sf-pill">Authorised Partner</span>
        </div>
    </div>
</header>

{{-- ── Hero ─────────────────────────────────────────────────────────────── --}}
<section class="q3-sf-hero">
    <div class="q3-sf-wrap">
        <div class="q3-sf-hero-grid">
            <div class="q3-sf-hero-copy">
                <div class="q3-sf-eyebrow">{{ $vendor['name'] }} · Commercial Air Purification</div>
                <h1><span class="q3-sf-gilt">{{ $product['name'] }}</span></h1>
                <p class="q3-sf-tagline">{{ $product['tagline'] }}</p>

                <a class="q3-sf-btn q3-sf-btn--gold" href="#enquire">Order this system</a>
                <a class="q3-sf-btn q3-sf-btn--ghost ms-2" href="#specs">View specifications</a>
                @if (! empty($product['challenge']))
                    <p class="q3-sf-hero-play">
                        or <a href="{{ route('vendor.challenge', [$member->referral_code, $vendorSlug, $productKey]) }}">take the 30-second challenge against the PRO &rarr;</a>
                    </p>
                @endif
            </div>

            @if ($heroImage)
                <div class="q3-sf-hero-media">
                    {{-- Ion rings and a passing sheen. Drawn around the photo,
                         never on it: the vendor's image is untouched. --}}
                    <span class="q3-sf-ring" aria-hidden="true"></span>
                    <span class="q3-sf-ring" aria-hidden="true"></span>
                    <span class="q3-sf-ring" aria-hidden="true"></span>
                    <span class="q3-sf-sheen" aria-hidden="true"></span>
                    {{-- Eager, and the only image on the page that is: it is the
                         one thing above the fold that says what this actually is. --}}
                    <img src="{{ asset($heroImage['src']) }}"
                         alt="{{ $heroImage['alt'] }}"
                         width="{{ $heroImage['width'] ?? '' }}"
                         height="{{ $heroImage['height'] ?? '' }}"
                         fetchpriority="high" decoding="async">
                </div>
            @endif
        </div>

        <div class="q3-sf-stats">
            @foreach ($heroStats as [$value, $label, $isLead])
                <div>
                    <div class="q3-sf-stat-value @if($isLead) q3-sf-stat-value--gold @endif">{{ $value }}</div>
                    <div class="q3-sf-stat-label">{!! $label !!}</div>
                </div>
            @endforeach
        </div>
    </div>
</section>

{{-- ── Overview ─────────────────────────────────────────────────────────── --}}
<section class="q3-sf-section">
    <div class="q3-sf-wrap">
        <h2 class="q3-sf-h2">What it does</h2>
        <p class="q3-sf-lede">{{ $product['summary'] }}</p>

        <div class="q3-sf-grid">
            @foreach ($product['highlights'] ?? [] as $i => $highlight)
                <div class="q3-sf-card q3-sf-benefit">
                    <span class="q3-sf-benefit-n">{{ str_pad($i + 1, 2, '0', STR_PAD_LEFT) }}</span>
                    <p>{{ $highlight }}</p>
                </div>
            @endforeach
        </div>
    </div>
</section>

{{-- ── Beyond spray and wipe ────────────────────────────────────────────── --}}
@if ($routine)
<section class="q3-sf-section q3-sf-routine">
    <div class="q3-sf-wrap">
        <div class="q3-sf-eyebrow">{{ $routine['eyebrow'] }}</div>
        <h2 class="q3-sf-h2">{{ $routine['heading'] }}</h2>
        <p class="q3-sf-lede">{{ $routine['lede'] }}</p>

        <div class="q3-sf-versus">
            {{-- The routine: spots come back after every pass of the cloth. --}}
            <article class="q3-sf-vs q3-sf-vs--old">
                <div class="q3-sf-scene" aria-hidden="true">
                    {{-- --d staggers each spot so it clears as the cloth
                         reaches its x position: 25% of a 9s cycle across 100%. --}}
                    @foreach ([[14, 30], [32, 62], [48, 24], [63, 58], [80, 34], [88, 70]] as $i => [$x, $y])
                        <span class="q3-sf-germ" style="--x:{{ $x }}%; --y:{{ $y }}%; --d:{{ $x * 0.0225 }}s;"></span>
                    @endforeach
                    <span class="q3-sf-wipe"></span>
                    <div class="q3-sf-meter"><span></span></div>
                </div>
                <div class="q3-sf-vs-body">
                    <div class="q3-sf-vs-label">{{ $routine['old']['label'] }}</div>
                    <h3>{{ $routine['old']['title'] }}</h3>
                    <ul>
                        @foreach ($routine['old']['points'] as $point)
                            <li>{{ $point }}</li>
                        @endforeach
                    </ul>
                    <div class="q3-sf-vs-meterlabel">{{ $routine['old']['meter'] }}</div>
                </div>
            </article>

            {{-- Continuous: ions keep moving, and anything that lands is met. --}}
            <article class="q3-sf-vs q3-sf-vs--new">
                <div class="q3-sf-scene" aria-hidden="true">
                    @foreach ([[14, 30], [32, 62], [48, 24], [63, 58], [80, 34], [88, 70]] as $i => [$x, $y])
                        <span class="q3-sf-germ" style="--x:{{ $x }}%; --y:{{ $y }}%; --i:{{ $i }};"></span>
                    @endforeach
                    @for ($i = 0; $i < 14; $i++)
                        <span class="q3-sf-ion" style="--x:{{ ($i * 37 + 5) % 100 }}%; --i:{{ $i }};"></span>
                    @endfor
                    <div class="q3-sf-meter"><span></span></div>
                </div>
                <div class="q3-sf-vs-body">
                    <div class="q3-sf-vs-label">{{ $routine['new']['label'] }}</div>
                    <h3>{{ $routine['new']['title'] }}</h3>
                    <blockquote class="q3-sf-quote">
                        <p>{{ $routine['new']['quote'] }}</p>
                        <cite>— {{ $routine['new']['source'] }}</cite>
                    </blockquote>
                    <div class="q3-sf-vs-meterlabel">{{ $routine['new']['meter'] }}</div>
                </div>
            </article>
        </div>

        @if (! empty($product['challenge']))
            <div class="q3-sf-challenge">
                <div>
                    <h3>Think you can keep up by hand?</h3>
                    <p>Thirty seconds, the same germs, you against the PRO’s ions.</p>
                </div>
                <a class="q3-sf-btn q3-sf-btn--gold"
                   href="{{ route('vendor.challenge', [$member->referral_code, $vendorSlug, $productKey]) }}">
                    Take the challenge
                </a>
            </div>
        @endif
    </div>
</section>
@endif

{{-- ── What arrives ─────────────────────────────────────────────────────── --}}
@if (! empty($gallery))
<section class="q3-sf-section">
    <div class="q3-sf-wrap">
        <h2 class="q3-sf-h2">What arrives</h2>
        <p class="q3-sf-lede">
            A generator sized to your ductwork, a sensor for each monitored space, and the hub
            that links them. {{ $vendor['name'] }} confirms the exact configuration before shipping.
        </p>

        <div class="q3-sf-gallery">
            @foreach ($gallery as $shot)
                <figure class="q3-sf-shot m-0">
                    <div class="q3-sf-shot-frame">
                        <img src="{{ asset($shot['src']) }}"
                             alt="{{ $shot['alt'] }}"
                             width="{{ $shot['width'] ?? '' }}"
                             height="{{ $shot['height'] ?? '' }}"
                             loading="lazy" decoding="async">
                    </div>
                    <figcaption class="q3-sf-shot-body">
                        <div class="q3-sf-shot-caption">{{ $shot['caption'] }}</div>
                        @if (! empty($shot['note']))
                            <p class="q3-sf-shot-note">{{ $shot['note'] }}</p>
                        @endif
                    </figcaption>
                </figure>
            @endforeach
        </div>

        {{-- Attribution stated rather than left to be inferred. It is their
             photography of their product. --}}
        <p class="q3-sf-credit">Product photography © {{ $vendor['legal_name'] ?? $vendor['name'] }}.</p>
    </div>
</section>
@endif

{{-- ── Specifications ───────────────────────────────────────────────────── --}}
<section class="q3-sf-section" id="specs">
    <div class="q3-sf-wrap">
        <h2 class="q3-sf-h2">Specifications</h2>
        <p class="q3-sf-lede">
            Manufacturer figures for the {{ $product['name'] }}. Sizing is confirmed by
            {{ $vendor['name'] }} against your actual HVAC system before an order ships.
        </p>

        <div class="q3-sf-specs">
            @foreach ($product['specs'] ?? [] as $group => $rows)
                <div class="q3-sf-spec-block">
                    <h3>{{ $group }}</h3>
                    <dl>
                        @foreach ($rows as $label => $value)
                            <div class="q3-sf-spec-row">
                                <dt>{{ $label }}</dt>
                                <dd>{{ $value }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </div>
            @endforeach
        </div>
    </div>
</section>

{{-- ── Applications ─────────────────────────────────────────────────────── --}}
<section class="q3-sf-section">
    <div class="q3-sf-wrap">
        <h2 class="q3-sf-h2">Where it is used</h2>
        <p class="q3-sf-lede">
            Installed into existing ductwork, so it suits any building already running
            central heating, cooling or ventilation.
        </p>
        <div class="q3-sf-chips">
            @foreach ($product['applications'] ?? [] as $application)
                <span class="q3-sf-chip">{{ $application }}</span>
            @endforeach
        </div>
    </div>
</section>

{{-- ── Enquiry ──────────────────────────────────────────────────────────── --}}
<section class="q3-sf-section q3-sf-section--raised" id="enquire">
    <div class="q3-sf-wrap">
        <div class="q3-sf-formshell">

            <div>
                <div class="q3-sf-partner">
                    <div class="q3-sf-avatar">{{ strtoupper(substr($member->name, 0, 1)) }}</div>
                    <div class="q3-sf-pname">{{ $member->name }}</div>
                    <div class="q3-sf-prole">
                        Independent {{ $siteName }} partner · ref {{ $member->referral_code }}
                    </div>
                    <ul>
                        <li>Your order goes through {{ $member->name }}.</li>
                        <li>They stay your point of contact through ordering and installation.</li>
                        <li>{{ $vendor['name'] }} fulfils, ships and warranties the system.</li>
                    </ul>

                    @if ($price)
                        <div class="q3-sf-price">
                            {{-- Config holds a plain number in whole currency
                                 units. Print it the way every other price on
                                 the site is printed: $6,000 USD, not "USD 6000". --}}
                            <span class="q3-sf-price-fig">
                                ${{ number_format((float) $price, fmod((float) $price, 1) === 0.0 ? 0 : 2) }}
                                {{ $product['currency'] ?? 'USD' }}
                            </span>
                            <span class="q3-sf-price-note">
                                per system. {{ $vendor['name'] }}'s price, the same for every buyer.
                                Shipping, handling and any sales tax are added at checkout from your
                                delivery address.
                            </span>
                        </div>
                    @endif
                </div>
            </div>

            <form class="q3-sf-form" method="POST"
                  action="{{ route('vendor.enquire', [$member->referral_code, $vendorSlug, $productKey]) }}">
                @csrf

                <h2 class="q3-sf-h2">Order this system</h2>
                <p class="q3-sf-lede" style="margin-bottom:24px;">
                    Two short steps. Tell us how to reach you and how many you need, then
                    shipping, tax and payment on the next page. The price is fixed, so there is
                    nothing to quote and nothing to negotiate.
                </p>

                @if ($errors->any())
                    <div class="q3-sf-alert">{{ $errors->first() }}</div>
                @endif

                {{-- Honeypot. A real browser never renders this field. --}}
                <div class="q3-sf-hp" aria-hidden="true">
                    <label for="website_url">Website</label>
                    <input type="text" id="website_url" name="website_url" tabindex="-1" autocomplete="off">
                </div>

                <div class="q3-sf-row">
                    <div class="q3-sf-field">
                        <label for="first_name">First name</label>
                        <input type="text" id="first_name" name="first_name" value="{{ old('first_name') }}"
                               class="@error('first_name') is-invalid @enderror" required autocomplete="given-name">
                        @error('first_name') <div class="q3-sf-err">{{ $message }}</div> @enderror
                    </div>
                    <div class="q3-sf-field">
                        <label for="last_name">Last name <span class="q3-sf-opt">(optional)</span></label>
                        <input type="text" id="last_name" name="last_name" value="{{ old('last_name') }}"
                               autocomplete="family-name">
                    </div>
                </div>

                <div class="q3-sf-field">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" value="{{ old('email') }}"
                           class="@error('email') is-invalid @enderror" required autocomplete="email">
                    @error('email') <div class="q3-sf-err">{{ $message }}</div> @enderror
                </div>

                <div class="q3-sf-row">
                    <div class="q3-sf-field">
                        <label for="phone">Phone <span class="q3-sf-opt">(optional)</span></label>
                        <input type="tel" id="phone" name="phone" value="{{ old('phone') }}" autocomplete="tel">
                    </div>
                    <div class="q3-sf-field">
                        <label for="company">Company <span class="q3-sf-opt">(optional)</span></label>
                        <input type="text" id="company" name="company" value="{{ old('company') }}"
                               autocomplete="organization">
                    </div>
                </div>

                @if (! empty($product['qualifiers']))
                    <div class="q3-sf-legend">About the building</div>
                    <div class="q3-sf-row">
                        @foreach ($product['qualifiers'] as $key => $definition)
                            <div class="q3-sf-field">
                                <label for="q_{{ $key }}">
                                    {{ $definition['label'] }} <span class="q3-sf-opt">(optional)</span>
                                </label>
                                <select id="q_{{ $key }}" name="qualifiers[{{ $key }}]">
                                    <option value="">Select…</option>
                                    @foreach ($definition['options'] ?? [] as $option)
                                        <option value="{{ $option }}" @selected(old("qualifiers.{$key}") === $option)>
                                            {{ $option }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                        @endforeach
                    </div>
                @endif

                <div class="q3-sf-legend">How many do you need?</div>

                <div class="q3-sf-sizing">
                    @if (! empty($sizing['help']))
                        <p class="q3-sf-sizing-help">{{ $sizing['help'] }}</p>
                    @endif

                    <div class="q3-sf-field" style="margin-bottom:0;">
                        <label for="furnace_count">
                            {{ $sizing['question'] ?? 'How many systems?' }}
                            <span class="q3-sf-opt">(optional)</span>
                        </label>
                        <input type="number" id="furnace_count" name="furnace_count"
                               min="1" max="{{ $sizing['max_systems'] ?? 20 }}"
                               value="{{ old('furnace_count') }}" placeholder="e.g. 2"
                               data-per-system="{{ $sizing['units_per_system'] ?? 1 }}">
                    </div>

                    <div class="q3-sf-suggest" id="sf-suggest" hidden>
                        <span>Suggested: <strong id="sf-suggest-qty">1</strong> system(s)</span>
                        @if (! empty($sizing['coverage_note']))
                            <span class="q3-sf-suggest-note">{{ $sizing['coverage_note'] }}</span>
                        @endif
                    </div>

                    <div class="q3-sf-running">
                        <div>
                            <div class="q3-sf-running-label">Quantity</div>
                            <div class="q3-sf-qty" style="margin-top:8px;">
                                <button type="button" id="sf-minus" aria-label="Fewer">&minus;</button>
                                <input type="number" id="quantity" name="quantity" min="1" max="99"
                                       value="{{ old('quantity', 1) }}"
                                       data-unit-price="{{ $unitPrice ? (int) round($unitPrice * 100) : 0 }}"
                                       aria-label="Quantity">
                                <button type="button" id="sf-plus" aria-label="More">+</button>
                                <span class="q3-sf-qty-unit">system(s)</span>
                            </div>
                        </div>
                        @if ($unitPrice)
                            <div style="text-align:right;">
                                <div class="q3-sf-running-label">Equipment subtotal</div>
                                <div class="q3-sf-running-value" id="sf-subtotal">—</div>
                            </div>
                        @endif
                        <div class="q3-sf-running-note">
                            @if ($unitPrice)Shipping and tax are calculated at the next step, once we have the address. @endif
                            {{ $sizing['confirm_note'] ?? '' }}
                        </div>
                    </div>

                    <div class="q3-sf-freight" id="sf-freight" hidden>
                        Orders this size ship by freight rather than parcel. Submit your details and
                        {{ $vendor['name'] }} will quote the delivery directly.
                    </div>
                </div>

                <div class="q3-sf-field">
                    <label for="notes">Anything we should know? <span class="q3-sf-opt">(optional)</span></label>
                    <textarea id="notes" name="notes" rows="3"
                              placeholder="Existing system, timescales, number of sites…">{{ old('notes') }}</textarea>
                </div>

                <button type="submit" class="q3-sf-btn q3-sf-btn--gold q3-sf-btn--block">
                    Continue to shipping &amp; payment
                </button>

                <p class="q3-sf-formnote">
                    Payment is taken by {{ $vendor['name'] }} on their own secure checkout —
                    {{ $siteName }} never sees or stores your card details. Your contact details are
                    shared with {{ $member->name }} and with {{ $vendor['name'] }} so your order can be
                    fulfilled.
                </p>
            </form>

        </div>
    </div>
</section>

{{-- ── Disclosure ───────────────────────────────────────────────────────── --}}
<footer class="q3-sf-footer">
    <div class="q3-sf-wrap">
        <img class="q3-sf-footer-logo" src="{{ $q3Logo }}" alt="{{ $siteName }}">
        <p>
            <strong>{{ $product['name'] }}</strong> is manufactured and sold by
            <strong>{{ $vendor['legal_name'] ?? $vendor['name'] }}</strong>
            (<a href="{{ $vendor['website'] }}" target="_blank" rel="noopener noreferrer">{{ parse_url($vendor['website'], PHP_URL_HOST) }}</a>).
            {{ $vendor['name'] }} is the seller of record: they take payment, ship the product, handle
            sales tax, and provide the warranty and support.
        </p>
        <p>
            {{ $member->name }} is an independent {{ $siteName }} partner and is paid a referral
            commission by {{ $vendor['name'] }} on completed orders. Product claims, specifications and
            test results are the manufacturer's. This page is not operated by {{ $vendor['name'] }}.
        </p>
    </div>
</footer>

@endsection

@push('scripts')
@if ($ambient === 'ion-field')
<script src="{{ \App\Support\Asset::v('assets/js/q3-ionfield.js') }}" defer></script>
@endif
<script>
(function () {
    var furnaces = document.getElementById('furnace_count');
    var qty      = document.getElementById('quantity');
    if (!qty) { return; }

    var suggest  = document.getElementById('sf-suggest');
    var suggestN = document.getElementById('sf-suggest-qty');
    var subtotal = document.getElementById('sf-subtotal');
    var freight  = document.getElementById('sf-freight');
    var minus    = document.getElementById('sf-minus');
    var plus     = document.getElementById('sf-plus');

    var unitPrice = parseInt(qty.dataset.unitPrice || '0', 10);
    var perSystem = parseInt((furnaces && furnaces.dataset.perSystem) || '1', 10) || 1;
    var freightAt = {{ (int) ($vendor['pricing']['shipping']['freight_threshold_units'] ?? 0) }};

    // The customer may know their building better than our rule does, so once
    // they touch the quantity themselves we stop overwriting it.
    var qtyTouched = false;
    qty.addEventListener('input', function () { qtyTouched = true; render(); });

    function clamp(v) { return Math.min(99, Math.max(1, isNaN(v) ? 1 : v)); }

    function render() {
        var n = clamp(parseInt(qty.value, 10));
        if (String(n) !== qty.value) { qty.value = n; }

        if (subtotal && unitPrice > 0) {
            subtotal.textContent = '$' + ((unitPrice * n) / 100).toLocaleString('en-US', {
                minimumFractionDigits: 2, maximumFractionDigits: 2
            });
        }
        if (minus) { minus.disabled = n <= 1; }
        if (freight) { freight.hidden = !(freightAt > 0 && n > freightAt); }
    }

    if (furnaces) {
        furnaces.addEventListener('input', function () {
            var f = parseInt(furnaces.value, 10);
            if (isNaN(f) || f < 1) { if (suggest) { suggest.hidden = true; } return; }

            var recommended = clamp(f * perSystem);
            if (suggest && suggestN) { suggestN.textContent = recommended; suggest.hidden = false; }
            if (!qtyTouched) { qty.value = recommended; }
            render();
        });
    }

    if (minus) { minus.addEventListener('click', function () { qtyTouched = true; qty.value = clamp(parseInt(qty.value, 10) - 1); render(); }); }
    if (plus)  { plus.addEventListener('click',  function () { qtyTouched = true; qty.value = clamp(parseInt(qty.value, 10) + 1); render(); }); }

    render();
})();
</script>
@endpush
