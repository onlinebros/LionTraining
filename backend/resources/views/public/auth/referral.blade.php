@extends('layouts.public')
@section('title', 'You\'ve Been Invited — Quantum 3 Solution')
@section('body-class', 'dark-only q3-theme q3-auth')

@push('styles')
<style>
    /* Invitation-specific chrome. Colours come from the Q3 tokens in
       q3-theme.css — nothing hardcoded here. */
    .q3-invite-badge {
        display: inline-flex; align-items: center; gap: 6px;
        background: var(--q3-gold-tint-2); color: var(--q3-gold-high);
        border: 1px solid var(--q3-border-gold);
        border-radius: 999px; padding: 5px 14px;
        font-size: .72rem; font-weight: 600; letter-spacing: .12em;
        text-transform: uppercase; margin-bottom: 18px;
    }
    .q3-sponsor-card {
        background: var(--q3-surface-2);
        border: 1px solid var(--q3-border);
        border-radius: var(--q3-radius-sm);
        padding: 14px 18px; margin-bottom: 24px;
        display: flex; align-items: center; gap: 14px;
    }
    .q3-sponsor-card .q3-sponsor-name { font-weight: 600; font-size: .95rem; color: var(--q3-text); }
    .q3-sponsor-card .q3-sponsor-sub  { font-size: .8rem; color: var(--q3-text-muted); }
</style>
@endpush

@section('content')
<div class="q3-auth-shell">
    <div class="q3-auth-card q3-auth-card--wide">

        <a href="{{ route('home') }}">
            <img class="q3-auth-logo" src="{{ \App\Support\Asset::v('assets/images/logo/q3_logo-sm.png') }}" alt="Quantum 3 Solution">
        </a>

        <div class="text-center">
            <span class="q3-invite-badge">
                <i data-feather="star" style="width:12px;height:12px;"></i>
                You've been invited
            </span>
        </div>

        <div class="q3-sponsor-card">
            <div class="q3-avatar q3-avatar-lg">{{ strtoupper(substr($sponsor->name, 0, 1)) }}</div>
            <div>
                <div class="q3-sponsor-name">{{ $sponsor->name }}</div>
                <div class="q3-sponsor-sub">is inviting you to join Quantum 3 Solution as their sponsored member.</div>
            </div>
        </div>

        <h1 class="q3-auth-title">Create your account</h1>
        <p class="q3-auth-sub">Fill in your details to accept the invitation and get started.</p>

        @if($errors->any())
            <div class="alert alert-danger py-2">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ route('join.post', $sponsor->referral_code) }}">
            @csrf
            <input type="hidden" name="referral_code" value="{{ $sponsor->referral_code }}">

            <div class="mb-3">
                <label class="form-label" for="name">Full Name</label>
                <input class="form-control @error('name') is-invalid @enderror"
                       id="name" type="text" name="name" value="{{ old('name') }}"
                       placeholder="Your full name" required autofocus autocomplete="name">
            </div>

            <div class="mb-3">
                <label class="form-label" for="email">Email Address</label>
                <input class="form-control @error('email') is-invalid @enderror"
                       id="email" type="email" name="email" value="{{ old('email') }}"
                       placeholder="you@example.com" required autocomplete="email">
            </div>

            <div class="mb-3">
                <label class="form-label" for="password">Password</label>
                <input class="form-control @error('password') is-invalid @enderror"
                       id="password" type="password" name="password"
                       placeholder="Min. 8 characters" required autocomplete="new-password">
            </div>

            <div class="mb-3">
                <label class="form-label" for="password_confirmation">Confirm Password</label>
                <input class="form-control" id="password_confirmation" type="password"
                       name="password_confirmation" placeholder="Re-enter password"
                       required autocomplete="new-password">
            </div>

            <div class="q3-auth-rule">Almost there</div>

            <div class="form-check mb-4">
                <input class="form-check-input" type="checkbox" id="terms" required>
                <label class="form-check-label" for="terms">
                    I agree to the <a href="#">Terms &amp; Conditions</a>
                </label>
                <div class="invalid-feedback">You must agree before continuing.</div>
            </div>

            <button class="btn btn-primary w-100" type="submit">Accept Invitation &amp; Join</button>
        </form>

        <div class="q3-auth-footer">
            Already have an account?
            <a class="ms-1" href="{{ route('login') }}">Sign in</a>
        </div>
    </div>
</div>
@endsection
