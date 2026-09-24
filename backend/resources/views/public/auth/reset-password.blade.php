@extends('layouts.public')
@section('title', 'Choose a New Password — Quantum Life')
@section('body-class', 'dark-only q3-theme q3-auth')

@section('content')
<div class="q3-auth-shell">
    <div class="q3-auth-card">

        <a href="{{ route('home') }}">
            <img class="q3-auth-logo" src="{{ \App\Support\Asset::v('assets/images/logo/q3_logo-sm.png') }}" alt="Quantum Life">
        </a>

        <h1 class="q3-auth-title">Choose a new password</h1>
        <p class="q3-auth-sub">At least 8 characters.</p>

        @if($errors->any())
            <div class="alert alert-danger py-2">
                {{ $errors->first() }}
                @if($errors->has('email'))
                    <a class="d-block mt-1" href="{{ route('password.request') }}">Request a new link</a>
                @endif
            </div>
        @endif

        <form method="POST" action="{{ route('password.update') }}">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">

            <div class="mb-3">
                <label class="form-label" for="email">Email Address</label>
                <input class="form-control" id="email" type="email" name="email"
                       value="{{ old('email', $email) }}" required autocomplete="email">
            </div>

            <div class="mb-3">
                <label class="form-label" for="password">New Password</label>
                <input class="form-control @error('password') is-invalid @enderror" id="password" type="password"
                       name="password" required autofocus autocomplete="new-password" minlength="8">
            </div>

            <div class="mb-4">
                <label class="form-label" for="password_confirmation">Confirm New Password</label>
                <input class="form-control" id="password_confirmation" type="password"
                       name="password_confirmation" required autocomplete="new-password" minlength="8">
            </div>

            <button class="btn btn-primary w-100" type="submit">Reset Password</button>
        </form>

        <div class="q3-auth-footer">
            <a href="{{ route('login') }}">Back to sign in</a>
        </div>
    </div>
</div>
@endsection
