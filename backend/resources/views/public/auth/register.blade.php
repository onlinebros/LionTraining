@extends('layouts.public')
@section('title', 'Create Account — Quantum Life')
@section('body-class', 'dark-only q3-theme q3-auth')

@section('content')
<div class="q3-auth-shell">
    <div class="q3-auth-card">

        <a href="{{ route('home') }}">
            <img class="q3-auth-logo" src="{{ \App\Support\Asset::v('assets/images/logo/q3_logo-sm.png') }}" alt="Quantum Life">
        </a>

        <h1 class="q3-auth-title">Create your account</h1>
        <p class="q3-auth-sub">Join Quantum Life to manage or receive sponsorships.</p>

        @if($errors->any())
            <div class="alert alert-danger py-2">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ route('register.post') }}">
            @csrf

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

            <div class="form-check mb-4">
                <input class="form-check-input" type="checkbox" id="terms" required>
                <label class="form-check-label" for="terms">
                    I agree to the <a href="{{ config('registration.terms_url') }}" target="_blank" rel="noopener">Terms of Service</a>
                    and <a href="{{ config('registration.privacy_url') }}" target="_blank" rel="noopener">Privacy Policy</a>
                </label>
                <div class="invalid-feedback">You must agree before continuing.</div>
            </div>

            <button class="btn btn-primary w-100" type="submit">Create Account</button>
        </form>

        <div class="q3-auth-footer">
            Already have an account?
            <a class="ms-1" href="{{ route('login') }}">Sign in</a>
        </div>
    </div>
</div>
@endsection
