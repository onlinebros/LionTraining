@extends('layouts.public')
@section('title', $payment->isPaid() ? 'Thank you for your order' : 'Your order status')
@section('body-class', 'q3-theme q3-storefront')

{{--
    Where the vendor's "after payment" redirect lands, if they configure one.

    It is also where Stripe sends a buyer who backed out of Affirm or Klarna,
    or whose bank payment has only started — and this page used to tell all of
    them "your order is placed". They believed it.

    So nothing here is decided by the redirect. The controller reads the
    intent's real status from the vendor's Stripe, and only a payment Stripe
    reports as succeeded is called an order. Every other state says plainly
    that nothing is ordered and nothing ships until the funds are received.
    The words come from PaymentGuidance, which the buyer email shares.
--}}

@push('styles')
<link rel="stylesheet" type="text/css" href="{{ \App\Support\Asset::v('assets/css/q3-storefront.css') }}">
@endpush

@section('content')

@php
    $siteName = \App\Models\SiteSetting::get('site_name');
    $q3Logo   = \App\Support\Asset::v('assets/images/logo/q3_logo-web.png');
    $guide    = \App\Services\Vendor\PaymentGuidance::for($payment, $lead, $vendor ?? []);
    $mark     = ['ok' => '&check;', 'warn' => '!', 'alert' => '&times;'][$guide->tone];
@endphp

<div class="q3-sf-centre">
    <div class="q3-sf-panel">
        <img class="q3-sf-panel-logo" src="{{ $q3Logo }}" alt="{{ $siteName }}">

        <div class="q3-sf-mark">{!! $mark !!}</div>

        <h1>{{ $guide->title }}</h1>
        @if ($guide->tone === 'ok')
            <p>{{ $guide->intro }}</p>
        @else
            <p class="{{ $guide->tone === 'alert' ? 'q3-sf-alert' : 'q3-sf-warn' }}" role="status">{{ $guide->intro }}</p>
        @endif

        <div class="q3-sf-ref">{{ $lead->public_ref }}</div>

        @if ($guide->option)
            <p class="q3-sf-formnote">Payment option you chose: <strong>{{ $guide->option }}</strong></p>
        @endif

        <div class="q3-sf-steps">
            <h2>{{ $guide->stepsHeading }}</h2>
            <ol>
                @foreach ($guide->steps as $step)
                    <li>{{ $step }}</li>
                @endforeach
                @if ($payment->isPaid() && $lead->stripe_receipt_url)
                    <li><a href="{{ $lead->stripe_receipt_url }}" target="_blank" rel="noopener">View your receipt</a></li>
                @endif
            </ol>
        </div>

        @if ($guide->canRetry)
            <p style="margin-top: 24px;">
                <a class="q3-sf-btn q3-sf-btn--gold" href="{{ route('vendor.order', $lead->public_ref) }}">Return to checkout</a>
            </p>
        @endif

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
