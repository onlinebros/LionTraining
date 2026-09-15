@extends('layouts.public')
@section('title', 'Complete your order — '.$lead->public_ref)
@section('body-class', 'q3-theme q3-storefront')

{{--
    Stage two: where it ships, what it costs, and paying for it.

    The lead already exists — it was written the moment we knew who they were.
    Everything on this page is an upgrade to a record that is already safe, which
    is why abandoning here costs the partner a follow-up call rather than a lead.

    One URL, two states: no address yet, so ask for one; address present, so show
    the quote. A customer who leaves and returns lands wherever they got to.

    Payment opens only once the address is settled: verified by FedEx, or
    confirmed by the buyer. See partials/address-check.
--}}

@push('styles')
<link rel="stylesheet" type="text/css" href="{{ \App\Support\Asset::v('assets/css/q3-storefront.css') }}">
@endpush

@section('content')

@php
    $siteName = \App\Models\SiteSetting::get('site_name');
    $q3Logo   = \App\Support\Asset::v('assets/images/logo/q3_logo-web.png');
    $hasAddress = filled($lead->postal_code);
    $money = fn ($minor) => '$'.number_format(((int) $minor) / 100, 2);
@endphp

<header class="q3-sf-brandbar">
    <div class="q3-sf-wrap">
        <img class="q3-sf-logo" src="{{ $q3Logo }}" alt="{{ $siteName }}">
        <div class="q3-sf-presenter">
            Order <strong>{{ $lead->public_ref }}</strong><br>
            <span class="q3-sf-suggest-note">
                @if ($lead->member) Referred by {{ $lead->member->name }} @endif
            </span>
        </div>
    </div>
</header>

<section class="q3-sf-section">
    <div class="q3-sf-wrap">
        <div class="q3-sf-formshell">

            {{-- ── What they are buying ─────────────────────────────────── --}}
            <div>
                <div class="q3-sf-partner">
                    <div class="q3-sf-pname">{{ $lead->productName() }}</div>
                    <div class="q3-sf-prole">{{ $lead->quantity }} × system · {{ $vendor['name'] }}</div>
                    <ul>
                        <li>{{ $lead->fullName() }} — {{ $lead->email }}</li>
                        @if ($lead->phone)<li>{{ $lead->phone }}</li>@endif
                        @if (! empty($lead->qualifiers['furnace_count']))
                            <li>{{ $lead->qualifiers['furnace_count'] }} furnace / air handler(s)</li>
                        @endif
                    </ul>
                </div>

                @if ($quote && $quote['quotable'])
                    <div class="q3-sf-card q3-sf-card--accent" style="margin-top:18px;">
                        <div class="q3-sf-spec-row">
                            <dt>Equipment</dt><dd>{{ $money($quote['subtotal']) }}</dd>
                        </div>
                        <div class="q3-sf-spec-row">
                            <dt>Shipping &amp; handling</dt>
                            <dd>{{ $money($quote['shipping'] + $quote['handling']) }}</dd>
                        </div>
                        <div class="q3-sf-spec-row">
                            <dt>Tax</dt><dd>{{ $money($quote['tax']) }}</dd>
                        </div>
                        <div class="q3-sf-spec-row" style="border-top:1px solid var(--q3-border-gold);">
                            <dt style="color:var(--q3-text);font-weight:600;">Total</dt>
                            <dd class="q3-sf-price-fig" style="font-size:1.25rem;">{{ $money($quote['total']) }}</dd>
                        </div>
                        <p class="q3-sf-shot-note" style="margin-top:14px;">
                            {{ $quote['cartons'] }} carton(s) via {{ $quote['shipping_quote']->describe() }}.
                            Tax is calculated by {{ $vendor['name'] }} for the delivery address.
                        </p>
                    </div>
                @endif
            </div>

            {{-- ── Address, then payment ────────────────────────────────── --}}
            <div class="q3-sf-form">

                @if ($error)
                    <div class="q3-sf-warn" style="margin-bottom:20px;">
                        We could not price this order automatically.
                        {{ $lead->member?->name ?? 'Your partner' }} will be in touch to complete it —
                        your details are saved under {{ $lead->public_ref }}.
                    </div>
                @elseif ($quote && ! $quote['quotable'])
                    <div class="q3-sf-warn" style="margin-bottom:20px;">
                        {{ $quote['reason'] }}<br>
                        {{ $vendor['name'] }} will quote the delivery for an order this size directly.
                    </div>
                @endif

                <h2 class="q3-sf-h2">{{ $hasAddress ? 'Delivery address' : 'Where should it ship?' }}</h2>
                <p class="q3-sf-lede" style="margin-bottom:22px;">
                    {{ $hasAddress
                        ? 'Check this is right before paying.'
                        : 'We need the delivery address to calculate shipping and tax.' }}
                </p>

                @if ($errors->any())
                    <div class="q3-sf-alert">{{ $errors->first() }}</div>
                @endif

                <form method="POST" action="{{ route('vendor.order.address', $lead->public_ref) }}">
                    @csrf

                    <div class="q3-sf-field">
                        <label for="company">Company <span class="q3-sf-opt">(optional)</span></label>
                        <input type="text" id="company" name="company"
                               value="{{ old('company', $lead->company) }}" autocomplete="organization">
                    </div>

                    <div class="q3-sf-field">
                        <label for="address_line1">Street address</label>
                        <input type="text" id="address_line1" name="address_line1"
                               value="{{ old('address_line1', $lead->address_line1) }}"
                               class="@error('address_line1') is-invalid @enderror" required autocomplete="address-line1">
                        @error('address_line1') <div class="q3-sf-err">{{ $message }}</div> @enderror
                    </div>

                    <div class="q3-sf-field">
                        <label for="address_line2">Suite / unit <span class="q3-sf-opt">(optional)</span></label>
                        <input type="text" id="address_line2" name="address_line2"
                               value="{{ old('address_line2', $lead->address_line2) }}" autocomplete="address-line2">
                        @error('address_line2') <div class="q3-sf-err">{{ $message }}</div> @enderror
                    </div>

                    <div class="q3-sf-row">
                        <div class="q3-sf-field">
                            <label for="city">City</label>
                            <input type="text" id="city" name="city" value="{{ old('city', $lead->city) }}"
                                   class="@error('city') is-invalid @enderror" required autocomplete="address-level2">
                            @error('city') <div class="q3-sf-err">{{ $message }}</div> @enderror
                        </div>
                        <div class="q3-sf-field">
                            <label for="state">State</label>
                            <input type="text" id="state" name="state" value="{{ old('state', $lead->state) }}"
                                   class="@error('state') is-invalid @enderror" required autocomplete="address-level1">
                            @error('state') <div class="q3-sf-err">{{ $message }}</div> @enderror
                        </div>
                    </div>

                    <div class="q3-sf-row">
                        <div class="q3-sf-field">
                            <label for="postal_code">ZIP code</label>
                            <input type="text" id="postal_code" name="postal_code"
                                   value="{{ old('postal_code', $lead->postal_code) }}"
                                   class="@error('postal_code') is-invalid @enderror" required autocomplete="postal-code">
                            @error('postal_code') <div class="q3-sf-err">{{ $message }}</div> @enderror
                        </div>
                        <div class="q3-sf-field">
                            <label for="quantity">Quantity</label>
                            <input type="number" id="quantity" name="quantity" min="1" max="99"
                                   value="{{ old('quantity', $lead->quantity) }}">
                        </div>
                    </div>

                    <div class="q3-sf-field">
                        <label for="notes">Delivery notes <span class="q3-sf-opt">(optional)</span></label>
                        <textarea id="notes" name="notes" rows="2"
                                  placeholder="Loading dock, access hours, site contact…">{{ old('notes', $lead->notes) }}</textarea>
                    </div>

                    <button type="submit" class="q3-sf-btn q3-sf-btn--ghost q3-sf-btn--block">
                        {{ $hasAddress ? 'Update address & re-quote' : 'Calculate shipping & tax' }}
                    </button>
                </form>

                @if ($hasAddress)
                    @include('public.vendor.partials.address-check')
                @endif

                @if ($quote && $quote['quotable'])
                    @php
                        $pk       = $vendor['stripe']['key'] ?? null;
                        $testMode = ($vendor['stripe']['mode'] ?? 'test') !== 'live';
                    @endphp

                    <div class="q3-sf-legend">Payment</div>

                    @if (! $addressReady)
                        {{-- The Pay endpoint refuses too; this just says why. --}}
                        <div class="q3-sf-warn">
                            Settle the delivery address above to continue to payment.
                        </div>
                    @elseif (! $pk)
                        <div class="q3-sf-warn">
                            Card payment is not configured for {{ $vendor['name'] }} yet.
                            {{ $lead->member?->name ?? 'Your partner' }} will be in touch to complete
                            your order — your details are saved under {{ $lead->public_ref }}.
                        </div>
                    @else
                        @if ($testMode)
                            {{-- Impossible to miss. A real customer must never reach a
                                 test-mode form thinking they have bought something. --}}
                            <div class="q3-sf-testbadge">
                                Test mode — no real payment will be taken. Use card
                                <code>4242 4242 4242 4242</code>, any future expiry and CVC.
                            </div>
                        @endif

                        <div id="q3-pay-error" class="q3-sf-alert" hidden></div>
                        <div id="q3-payment-element"></div>

                        <button type="button" id="q3-pay-button"
                                class="q3-sf-btn q3-sf-btn--gold q3-sf-btn--block" style="margin-top:18px;">
                            Pay {{ $money($quote['total']) }}
                        </button>
                    @endif

                    <p class="q3-sf-formnote">
                        Payment is taken by {{ $vendor['name'] }} on their own Stripe account —
                        {{ $siteName }} never sees or stores your card details.
                        {{ $vendor['name'] }} ships the system and provides the warranty.
                    </p>
                @endif
            </div>

        </div>
    </div>
</section>

<footer class="q3-sf-footer">
    <div class="q3-sf-wrap">
        <p>
            <strong>{{ $lead->productName() }}</strong> is sold and fulfilled by
            <strong>{{ $vendor['legal_name'] ?? $vendor['name'] }}</strong>, who are the seller of
            record for this order. {{ $lead->member?->name ?? 'Your partner' }} is an independent
            {{ $siteName }} partner. Quote reference {{ $lead->public_ref }}.
        </p>
    </div>
</footer>

@endsection

@if ($quote && ($quote['quotable'] ?? false) && $addressReady && ! empty($vendor['stripe']['key']))
@push('scripts')
@php
    /*
     * Billing details for confirmPayment, built with empty fields REMOVED rather
     * than sent as null — Stripe.js throws on a null where it expects a string.
     *
     * Shipping is deliberately NOT passed here. It is set on the PaymentIntent
     * server-side with the vendor's restricted key, and Stripe refuses to let a
     * publishable key change what a secret key wrote: "The shipping information
     * on this PaymentIntent was last set with a restricted key and therefore
     * cannot be changed with a publishable key."
     *
     * Server-side is also where it belongs. The delivery address is what the
     * vendor ships to and what tax was calculated on; it is not the browser's to
     * revise at the moment of payment.
     */
    $filled = static fn ($v) => $v !== null && $v !== '' && $v !== [];

    /*
     * The Payment Element is told never to ask for billing country or postcode,
     * because we already hold the delivery address. Anything hidden with 'never'
     * must then be passed explicitly at confirm time, or the payment is rejected.
     */
    $stripeBilling = [
        'address' => array_filter([
            'country'     => $lead->country ?: 'US',
            'postal_code' => $lead->postal_code,
        ], $filled),
    ];
@endphp
<script src="https://js.stripe.com/basil/stripe.js"></script>
<script>
(function () {
    var mount  = document.getElementById('q3-payment-element');
    var button = document.getElementById('q3-pay-button');
    var errBox = document.getElementById('q3-pay-error');
    if (!mount || !button) { return; }

    // The vendor's publishable key: the charge belongs on their account, so the
    // card fields talk to their Stripe, not ours.
    var stripe = Stripe(@json($vendor['stripe']['key']));

    /*
     * Deferred mode — the Element renders from an amount alone and no
     * PaymentIntent exists until someone actually submits. Creating one per page
     * view would fill the vendor's dashboard with abandoned intents.
     */
    var elements = stripe.elements({
        mode: 'payment',
        amount: {{ (int) $quote['total'] }},
        currency: '{{ strtolower($product['currency'] ?? 'usd') }}',
        appearance: {
            theme: 'night',
            variables: {
                colorPrimary:    '#D4AF37',
                colorBackground: '#1A1A1D',
                colorText:       '#F5F1E8',
                colorDanger:     '#B4483F',
                fontFamily:      'Inter, system-ui, sans-serif',
                borderRadius:    '8px',
                spacingUnit:     '4px'
            }
        }
    });

    elements.create('payment', {
        // We already have the shipping address; asking again is friction, and
        // two addresses that disagree is a support ticket.
        fields: { billingDetails: { address: { country: 'never', postalCode: 'never' } } },
        defaultValues: { billingDetails: {
            name:  @json($lead->fullName()),
            email: @json($lead->email)
        }}
    }).mount(mount);

    function fail(message) {
        errBox.textContent = message;
        errBox.hidden = false;
        button.disabled = false;
        button.textContent = @json('Pay '.$money($quote['total']));
    }

    var billing  = @json($stripeBilling);

    /*
     * One try/catch around the whole flow. Stripe.js reports problems two ways:
     * a declined card comes back as `result.error`, but an invalid parameter is
     * THROWN. An uncaught throw inside an async click handler is silent — no
     * message, and the button stays on "Processing…" indefinitely. That is
     * exactly how this failed in testing.
     */
    async function pay() {
        errBox.hidden = true;
        button.disabled = true;
        button.textContent = 'Processing…';

        // Validates the card fields before anything is created server-side.
        var submitResult = await elements.submit();
        if (submitResult.error) { return fail(submitResult.error.message); }

        var response = await fetch(@json(route('vendor.order.pay', $lead->public_ref)), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                'Accept': 'application/json'
            }
        });

        var payload = {};
        try { payload = await response.json(); } catch (e) { /* non-JSON, e.g. an expired-session page */ }

        if (!response.ok || !payload.client_secret) {
            return fail(payload.error || 'We could not start this payment. Please refresh the page and try again.');
        }

        var result = await stripe.confirmPayment({
            elements: elements,
            clientSecret: payload.client_secret,
            confirmParams: {
                return_url: payload.return_url,
                payment_method_data: { billing_details: billing }
            }
        });

        // Reached only on an immediate error; success redirects to return_url.
        if (result.error) { fail(result.error.message); }
    }

    button.addEventListener('click', function () {
        pay().catch(function (err) {
            // In the console for whoever is debugging; a plain message for the
            // customer, who needs to know it did not go through.
            console.error('Q3 checkout: payment could not be started', err);
            fail('We could not complete the payment. Please try again, or contact us quoting ' + @json($lead->public_ref) + '.');
        });
    });
})();
</script>
@endpush
@endif
