@extends('layouts.public')
@section('title', 'Continuing to secure checkout')
@section('body-class', 'q3-theme q3-storefront')

{{--
    The bridge between our capture page and the vendor's checkout.

    A visible step rather than a bare redirect: the customer has just typed
    their details into a Q3 page, and dropping them without warning onto a
    payment page branded for a company they may not recognise is how a checkout
    gets abandoned. Carrying the Q3 mark across the handoff is the point — it
    is the last thing they see before the vendor's own chrome takes over.
--}}

@push('styles')
<link rel="stylesheet" type="text/css" href="{{ \App\Support\Asset::v('assets/css/q3-storefront.css') }}">
@endpush

@if ($checkoutUrl)
    @push('scripts')
    <script>
        // Redirect from script rather than a meta refresh so the manual link
        // still works for anyone who lands here with JS disabled or blocked.
        setTimeout(function () {
            window.location.href = @json($checkoutUrl);
        }, 2500);
    </script>
    @endpush
@endif

@section('content')

@php
    $siteName = \App\Models\SiteSetting::get('site_name');
    $q3Logo   = \App\Support\Asset::v('assets/images/logo/q3_logo-web.png');
@endphp

<div class="q3-sf-centre">
    <div class="q3-sf-panel">
        <img class="q3-sf-panel-logo" src="{{ $q3Logo }}" alt="{{ $siteName }}">

        @if ($checkoutUrl)
            <div class="q3-sf-spin"></div>
            <h1>Taking you to {{ $vendor['name'] }}</h1>
            <p>
                Your details are saved. {{ $vendor['name'] }} takes the payment on their own secure
                checkout — {{ $siteName }} never sees your card details.
            </p>

            <div class="q3-sf-ref">{{ $lead->public_ref }}</div>

            <div>
                <a class="q3-sf-btn q3-sf-btn--gold" href="{{ $checkoutUrl }}" rel="noopener">
                    Continue to checkout
                </a>
            </div>

            <p class="q3-sf-note">
                Not redirected automatically? Use the button above.<br>
                Quote reference <strong>{{ $lead->public_ref }}</strong> in any correspondence — it is
                how your order is matched back to {{ $lead->member?->name ?? 'your partner' }}.
            </p>
        @else
            <h1>Thanks — we have your details</h1>
            <p>
                Online checkout for this product is not open yet.
                {{ $lead->member?->name ?? 'Your partner' }} will be in touch shortly to complete your
                order with {{ $vendor['name'] }} directly.
            </p>

            <div class="q3-sf-ref">{{ $lead->public_ref }}</div>

            <div class="q3-sf-warn">
                Keep this reference. It links your enquiry to
                {{ $lead->member?->name ?? 'your partner' }} and to {{ $vendor['name'] }}'s order system.
            </div>
        @endif

    </div>
</div>
@endsection
