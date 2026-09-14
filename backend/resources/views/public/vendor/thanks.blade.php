@extends('layouts.public')
@section('title', 'Thank you for your order')
@section('body-class', 'q3-theme q3-storefront')

{{--
    Where the vendor's "after payment" redirect lands, if they configure one.

    The wording is deliberately careful. This page is reached by a browser
    redirect the customer controls, so it is NOT proof of payment and the
    controller does not mark anything converted from it. It confirms what we
    know — the enquiry and its reference — and says the vendor will confirm the
    order itself. Claiming "payment received" here would be a lie roughly one
    time in every abandoned checkout.
--}}

@push('styles')
<link rel="stylesheet" type="text/css" href="{{ \App\Support\Asset::v('assets/css/q3-storefront.css') }}">
@endpush

@section('content')

@php
    $siteName = \App\Models\SiteSetting::get('site_name');
    $q3Logo   = \App\Support\Asset::v('assets/images/logo/q3_logo-web.png');
@endphp

<div class="q3-sf-centre">
    <div class="q3-sf-panel">
        <img class="q3-sf-panel-logo" src="{{ $q3Logo }}" alt="{{ $siteName }}">

        <div class="q3-sf-mark">&check;</div>

        <h1>Thank you, {{ $lead->first_name }}</h1>
        <p>
            Your enquiry for the <strong>{{ $lead->productName() }}</strong> is recorded against
            {{ $lead->member?->name ?? 'your partner' }}.
        </p>

        <div class="q3-sf-ref">{{ $lead->public_ref }}</div>

        <div class="q3-sf-steps">
            <h2>What happens next</h2>
            <ol>
                <li>{{ $vendor['name'] }} confirms your order and payment directly by email.</li>
                <li>They arrange shipping and provide tracking.</li>
                <li>{{ $lead->member?->name ?? 'Your partner' }} stays your point of contact for
                    anything to do with the system.</li>
            </ol>
        </div>

        <p class="q3-sf-note">
            {{ $vendor['name'] }} is the seller of record for this order — they take payment, ship, and
            provide the warranty. {{ $siteName }} never sees or stores your card details.
            Questions about payment or delivery go to
            <a href="mailto:{{ $vendor['support_email'] ?? '' }}">{{ $vendor['support_email'] ?? $vendor['name'] }}</a>,
            quoting {{ $lead->public_ref }}.
        </p>
    </div>
</div>
@endsection
