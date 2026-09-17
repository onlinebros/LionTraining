@extends('layouts.public')
@section('title', 'Your Details — Quantum 3 Solution')
@section('body-class', 'dark-only q3-theme q3-auth')

@push('styles')
<style>
    .q3-verified {
        display: flex; align-items: center; gap: 12px;
        background: var(--q3-surface-2); border: 1px solid var(--q3-border);
        border-left: 3px solid var(--q3-success);
        border-radius: var(--q3-radius-sm); padding: 13px 16px; margin-bottom: 24px;
    }
    .q3-verified-tick {
        width: 26px; height: 26px; flex-shrink: 0; border-radius: 50%;
        background: var(--q3-success); color: #fff;
        display: flex; align-items: center; justify-content: center; font-size: .8rem;
    }
    .q3-verified-id   { font-weight: 600; font-size: .92rem; color: var(--q3-text); }
    .q3-verified-sub  { font-size: .79rem; color: var(--q3-text-muted); }

    .q3-merge-offer {
        background: var(--q3-surface-2); border: 1px solid var(--q3-border-gold);
        border-radius: var(--q3-radius-sm); padding: 18px 20px; margin-bottom: 26px;
    }
    .q3-merge-title { font-size: 1rem; font-weight: 650; color: var(--q3-text); margin-bottom: 8px; }
    .q3-merge-body  { font-size: .86rem; color: var(--q3-text-muted); line-height: 1.55; margin-bottom: 14px; }
    .q3-merge-alt   { font-size: .78rem; color: var(--q3-text-dim); margin: 12px 0 0; }

</style>
@endpush

@section('content')
<div class="q3-auth-shell">
    <div class="q3-auth-card q3-auth-card--wide">

        <img class="q3-auth-logo" src="{{ \App\Support\Asset::v('assets/images/logo/q3_logo-sm.png') }}"
             alt="Quantum 3 Solution">

        <div class="q3-verified">
            <span class="q3-verified-tick">&check;</span>
            <div>
                <div class="q3-verified-id">
                    {{ $company->identifier_label }} {{ $spot->external_user_id }} confirmed
                </div>
                <div class="q3-verified-sub">
                    Your {{ $company->name }} position is held. Finish below and it becomes your
                    Quantum 3 Solution account.
                </div>
            </div>
        </div>

        @if($existing)
            {{-- They are already a Quantum partner. One account and one team is
                 almost always what they want; creating a second is how a
                 founder ends up with their organisation split across two
                 logins and a support ticket. --}}
            <div class="q3-merge-offer">
                <h2 class="q3-merge-title">You already have a Quantum 3 Solution account</h2>
                <p class="q3-merge-body">
                    You are signed in as <strong>{{ $existing->name }}</strong>
                    ({{ $existing->email }}). Add this {{ $company->name }} position to that
                    account and everyone below it joins your existing team — one login, one
                    organisation.
                </p>
                <form method="POST" action="{{ route('partner.claim.merge', $company->slug) }}">
                    @csrf
                    <button class="btn btn-primary w-100" type="submit">
                        Add it to my {{ $existing->name }} account
                    </button>
                </form>
                <p class="q3-merge-alt">
                    Or set up a separate account below. Your {{ $company->name }} team stays with
                    the new account, not with the one you are signed in as.
                </p>
            </div>
        @endif

        <h1 class="q3-auth-title">Your details</h1>
        <p class="q3-auth-sub">
            {{ $company->name }} sent us your position and nothing else — no name, no email, no
            phone number. Everything below is yours to give us, and this is your account with us,
            so use the email address and password you want to sign in with.
        </p>

        @if($errors->any())
            <div class="alert alert-danger py-2">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ route('partner.claim.store', $company->slug) }}">
            @csrf

            <div class="mb-3">
                <label class="form-label" for="name">Full Name</label>
                <input class="form-control @error('name') is-invalid @enderror"
                       id="name" type="text" name="name"
                       value="{{ old('name') }}"
                       placeholder="Your full name" required autofocus autocomplete="name">
            </div>

            <div class="mb-3">
                <label class="form-label" for="email">Email Address</label>
                <input class="form-control @error('email') is-invalid @enderror"
                       id="email" type="email" name="email" value="{{ old('email') }}"
                       placeholder="you@example.com" required autocomplete="email">
            </div>

            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label" for="password">Password</label>
                    <input class="form-control @error('password') is-invalid @enderror"
                           id="password" type="password" name="password"
                           placeholder="Min. 8 characters" required autocomplete="new-password">
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label" for="password_confirmation">Confirm Password</label>
                    <input class="form-control" id="password_confirmation" type="password"
                           name="password_confirmation" placeholder="Re-enter password"
                           required autocomplete="new-password">
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label" for="phone">Phone</label>
                <input class="form-control @error('phone') is-invalid @enderror"
                       id="phone" type="tel" name="phone"
                       value="{{ old('phone') }}"
                       placeholder="(555) 555-0100" autocomplete="tel">
            </div>

            <div class="q3-auth-rule">Where we send things</div>

            <div class="mb-3">
                <label class="form-label" for="address_line1">Address</label>
                <input class="form-control" id="address_line1" type="text" name="address_line1"
                       value="{{ old('address_line1') }}" placeholder="Street address"
                       autocomplete="address-line1">
            </div>

            <div class="mb-3">
                <input class="form-control" id="address_line2" type="text" name="address_line2"
                       value="{{ old('address_line2') }}" placeholder="Apartment, suite (optional)"
                       autocomplete="address-line2">
            </div>

            <div class="row">
                <div class="col-md-5 mb-3">
                    <label class="form-label" for="city">City</label>
                    <input class="form-control" id="city" type="text" name="city"
                           value="{{ old('city') }}" autocomplete="address-level2">
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label" for="state">State</label>
                    <input class="form-control" id="state" type="text" name="state"
                           value="{{ old('state') }}" autocomplete="address-level1">
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label" for="postal_code">ZIP</label>
                    <input class="form-control" id="postal_code" type="text" name="postal_code"
                           value="{{ old('postal_code') }}" autocomplete="postal-code">
                </div>
            </div>

            <input type="hidden" name="country" value="{{ old('country', 'US') }}">

            <div class="q3-auth-rule">Almost there</div>

            <div class="form-check mb-4">
                <input class="form-check-input @error('terms') is-invalid @enderror"
                       type="checkbox" id="terms" name="terms" value="1" {{ old('terms') ? 'checked' : '' }}>
                <label class="form-check-label" for="terms">
                    I agree to the
                    <a href="{{ config('registration.terms_url') }}" target="_blank" rel="noopener">Terms of Service</a>
                    and <a href="{{ config('registration.privacy_url') }}" target="_blank" rel="noopener">Privacy Policy</a>
                </label>
            </div>

            <button class="btn btn-primary w-100" type="submit">Claim my position</button>
        </form>

        <div class="q3-auth-footer">
            <a href="{{ route('partner.claim', $company->slug) }}">&larr; Use a different {{ $company->identifier_label }}</a>
        </div>
    </div>
</div>
@endsection
