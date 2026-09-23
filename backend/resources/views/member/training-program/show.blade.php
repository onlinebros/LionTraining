@extends('layouts.member')

@section('title', 'Training Program')
@section('page-title', 'Training Program')

@section('breadcrumb')
    <li class="breadcrumb-item active">Training Program</li>
@endsection

@section('content')
@php
    $price    = '$' . number_format($amount / 100, 2);
    $siteName = \App\Models\SiteSetting::get('site_name');
@endphp

<div class="row justify-content-center">
    <div class="col-lg-8">

        @if(session('status'))
            <div class="alert alert-success py-2 small">{{ session('status') }}</div>
        @endif

        <div class="card">
            <div class="card-body p-4">

                <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
                    <h4 class="mb-0">{{ $line->name() }}</h4>
                    @if($held)
                        <span class="badge badge-light-success">You have this</span>
                    @elseif($open)
                        <span class="badge badge-light-primary">{{ $price }} per {{ $interval }}</span>
                    @else
                        <span class="badge badge-light-warning">Opening soon</span>
                    @endif
                </div>

                <p class="mb-2">
                    Quantum VISION is the Foundational HEART of The Quantum Solution.
                </p>
                <p class="mb-4">
                    The Rediscovery of the HEART Institute awakens the Creative Genius Code that is
                    dormant in 98% of adults.
                </p>

                {{-- ── They already have it ─────────────────────────────── --}}
                @if($held)
                    <p>
                        The training program is part of your account. Manage the subscription from
                        <a href="{{ route('member.billing.index') }}">Billing</a>.
                    </p>

                {{-- ── On sale ──────────────────────────────────────────
                     The card is captured on the billing screen, where the
                     enrollment options and the charge dates live. This page
                     never takes payment details itself. --}}
                @elseif($open)
                    <p>
                        The monthly tuition is <strong>{{ $price }}</strong>. Adding it does not change
                        anything else about your account: your referral link, product pages, contacts
                        and commissions work the same either way.
                    </p>
                    <p class="text-muted small">
                        You choose when your first charge happens on the next page &mdash; either when
                        the program opens, or once your paid commissions reach
                        ${{ number_format($threshold) }}. Nothing is charged until you confirm.
                    </p>
                    <a href="{{ route('member.billing.start') }}" class="btn btn-primary">
                        Join the training program
                    </a>

                {{-- ── Not open yet ─────────────────────────────────────
                     The only state a partner can be in today. No card, no
                     subscription, nothing to cancel — just a list to mail. --}}
                @else
                    <div class="alert alert-light-primary py-3">
                        <strong>The training program is not open yet.</strong>
                        It is a separate, optional add-on to your partner account. Nothing is being
                        charged, no payment details are needed, and your account works fully without
                        it &mdash; your referral link, product pages, contacts and commissions are all
                        unaffected.
                    </div>

                    <p class="text-muted small mb-4">
                        When it opens it will be <strong>{{ $price }} per {{ $interval }}</strong>, and
                        joining will be your choice. You will be able to see the price and when your
                        first charge would happen before you confirm anything.
                    </p>

                    @if($interested)
                        <p class="mb-2">
                            <i data-feather="check" style="width:15px;height:15px;"></i>
                            You asked to be told when it opens
                            <span class="text-muted small">({{ $user->training_interest_at->format('j M Y') }})</span>.
                        </p>
                        <form method="POST" action="{{ route('member.training-program.interest') }}">
                            @csrf
                            <input type="hidden" name="remove" value="1">
                            <button class="btn btn-outline-secondary btn-sm" type="submit">
                                Take me off the list
                            </button>
                        </form>
                    @else
                        <form method="POST" action="{{ route('member.training-program.interest') }}">
                            @csrf
                            <button class="btn btn-primary" type="submit">Tell me when it opens</button>
                        </form>
                    @endif
                @endif

            </div>
        </div>

        <div class="card">
            <div class="card-body p-4">
                <h6 class="fw-bold mb-2">What you already have, free</h6>
                <p class="text-muted small mb-0">
                    Your partner account costs nothing and needs no card. Your referral link, your own
                    copy of the {{ $siteName }} website, your product pages, your contacts and your
                    commission statements are not part of this subscription and never will be.
                </p>
            </div>
        </div>

    </div>
</div>
@endsection
