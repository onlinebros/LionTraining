@extends('layouts.public')
@section('title', 'Claim Your Position — ' . $company->name . ' &amp; Quantum 3 Solution')
@section('body-class', 'dark-only q3-theme q3-auth')

@push('styles')
<style>
    /* Co-branding. The partner's mark sits beside ours, same visual weight —
       the visitor is being asked to trust a page they have never seen because
       their own company sent them to it, and a Quantum page with a small
       partner logo in the corner does not read that way. */
    .q3-cobrand {
        display: flex; align-items: center; justify-content: center;
        gap: 18px; margin-bottom: 22px;
    }
    .q3-cobrand img { max-height: 46px; max-width: 150px; object-fit: contain; }
    .q3-cobrand-x {
        width: 30px; height: 30px; flex-shrink: 0;
        display: flex; align-items: center; justify-content: center;
        border-radius: 50%; border: 1px solid var(--q3-border);
        color: var(--q3-text-dim); font-size: .8rem;
    }
    .q3-cobrand-name {
        font-weight: 650; font-size: 1.05rem; color: var(--q3-text);
        letter-spacing: .01em;
    }

    .q3-claim-note {
        background: var(--q3-surface-2); border: 1px solid var(--q3-border);
        border-left: 3px solid var(--q3-gold); border-radius: var(--q3-radius-sm);
        padding: 13px 16px; margin-bottom: 22px;
        font-size: .84rem; color: var(--q3-text-muted); line-height: 1.55;
    }
    .q3-claim-note--filled { border-left-color: var(--q3-success); }

    .q3-claim-count {
        text-align: center; font-size: .78rem; color: var(--q3-text-dim);
        margin-top: 18px; letter-spacing: .03em;
    }
    .q3-claim-count strong { color: var(--q3-gold-high); font-variant-numeric: tabular-nums; }

    /* Codes are read off paper and typed. Monospace and wide tracking make a
       B / 8 mix-up visible before the form is submitted. */
    .q3-code-input {
        font-family: var(--bs-font-monospace, monospace);
        letter-spacing: .14em; text-transform: uppercase;
    }
</style>
@endpush

@section('content')
<div class="q3-auth-shell">
    <div class="q3-auth-card">

        <div class="q3-cobrand">
            {{-- The claim page always renders on the dark theme, so the dark
                 mark is the one asked for. logoUrl() falls back to the other. --}}
            @if($logo = $company->logoUrl(dark: true))
                <img src="{{ $logo }}" alt="{{ $company->name }}">
            @else
                <span class="q3-cobrand-name">{{ $company->name }}</span>
            @endif

            <span class="q3-cobrand-x">&plus;</span>

            <img src="{{ \App\Support\Asset::v('assets/images/logo/q3_logo-sm.png') }}"
                 alt="Quantum 3 Solution">
        </div>

        <h1 class="q3-auth-title">{{ $company->headline ?: 'Claim your position' }}</h1>
        <p class="q3-auth-sub">
            {{ $company->intro ?: "A position has been reserved for you at Quantum 3 Solution as part of our partnership with {$company->name}. Enter the two details below to claim it." }}
        </p>

        @if(filled($prefill['activation_code'] ?? null))
            {{-- They followed a link from {{ $company->name }} that carried both
                 details. Say so rather than silently filling the boxes: a form
                 that is already answered when you arrive is unsettling unless
                 somebody tells you why. --}}
            <div class="q3-claim-note q3-claim-note--filled">
                We filled these in from your {{ $company->name }} link. Check they look right,
                then continue.
            </div>
        @else
            <div class="q3-claim-note">
                Both of these came from {{ $company->name }} — check the letter or email they sent you.
                Your position, and everyone already below it, is held exactly as it stands with them.
            </div>
        @endif

        @if($errors->any())
            <div class="alert alert-danger py-2">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ route('partner.claim.verify', $company->slug) }}">
            @csrf

            <div class="mb-3">
                <label class="form-label" for="external_user_id">{{ $company->identifier_label }}</label>
                <input class="form-control @error('external_user_id') is-invalid @enderror"
                       id="external_user_id" type="text" name="external_user_id"
                       value="{{ old('external_user_id', $prefill['external_user_id'] ?? '') }}"
                       placeholder="e.g. 10233" required autofocus
                       autocomplete="off" autocapitalize="characters" spellcheck="false">
            </div>

            <div class="mb-4">
                <label class="form-label" for="activation_code">{{ $company->activation_label }}</label>
                <input class="form-control q3-code-input @error('activation_code') is-invalid @enderror"
                       id="activation_code" type="text" name="activation_code"
                       value="{{ old('activation_code', $prefill['activation_code'] ?? '') }}"
                       placeholder="XXXX-XXXX" required
                       autocomplete="off" autocapitalize="characters" spellcheck="false">
            </div>

            <button class="btn btn-primary w-100" type="submit">Continue</button>
        </form>

        @if($counts['unclaimed'] > 0)
            <div class="q3-claim-count">
                <strong>{{ number_format($counts['unclaimed']) }}</strong>
                of {{ number_format($counts['total']) }} {{ $company->name }} positions are still unclaimed
            </div>
        @endif

        <div class="q3-auth-footer">
            Already claimed your position?
            <a class="ms-1" href="{{ route('login') }}">Sign in</a>
            @if($company->support_email)
                <div class="mt-2 small">
                    Can't find your details?
                    <a href="mailto:{{ $company->support_email }}">Contact {{ $company->name }}</a>
                </div>
            @endif
        </div>
    </div>
</div>
@endsection
