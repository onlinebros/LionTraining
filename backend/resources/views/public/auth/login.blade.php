@extends('layouts.public')
@section('title', 'Sign In — Quantum Life')
@section('body-class', 'dark-only q3-theme q3-auth')

@section('content')
<div class="q3-auth-shell">
    <div class="q3-auth-card">

        <a href="{{ route('home') }}">
            <img class="q3-auth-logo" src="{{ \App\Support\Asset::v('assets/images/logo/q3_logo-sm.png') }}" alt="Quantum Life">
        </a>

        <h1 class="q3-auth-title">Sign in to your account</h1>
        <p class="q3-auth-sub">Welcome back. Enter your credentials to continue.</p>

        @if($errors->any())
            <div class="alert alert-danger py-2">{{ $errors->first() }}</div>
        @endif

        @if(session('status'))
            <div class="alert alert-success py-2">{{ session('status') }}</div>
        @endif

        <form method="POST" action="{{ route('login.post') }}">
            @csrf

            <div class="mb-3">
                <label class="form-label" for="email">Email Address</label>
                <input class="form-control @error('email') is-invalid @enderror"
                       id="email" type="email" name="email" value="{{ old('email') }}"
                       placeholder="you@example.com" required autofocus autocomplete="email">
            </div>

            <div class="mb-3">
                <label class="form-label d-flex justify-content-between align-items-center" for="password">
                    <span>Password</span>
                    <a href="{{ route('password.request') }}" class="link">Forgot password?</a>
                </label>
                <input class="form-control" id="password" type="password" name="password"
                       placeholder="••••••••" required autocomplete="current-password">
            </div>

            <div class="form-check mb-4">
                <input class="form-check-input" id="remember" type="checkbox" name="remember">
                <label class="form-check-label" for="remember">Remember me</label>
            </div>

            <button class="btn btn-primary w-100" type="submit">Sign In</button>
        </form>

        <div class="q3-auth-footer">
            @if(\App\Support\Registration::openToPublic())
                Don't have an account?
                <a class="ms-1" href="{{ route('register') }}">Create account</a>
            @else
                {{-- No "create account" link while the platform is invitation
                     only: the route is closed, and offering it would send
                     people to a page that can only turn them away. --}}
                Accounts are created by invitation from an existing partner.
            @endif
        </div>
    </div>
</div>
@endsection
