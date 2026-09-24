@extends('layouts.public')
@section('title', 'Forgot Password — Quantum Life')
@section('body-class', 'dark-only q3-theme q3-auth')

@section('content')
<div class="q3-auth-shell">
    <div class="q3-auth-card">

        <a href="{{ route('home') }}">
            <img class="q3-auth-logo" src="{{ \App\Support\Asset::v('assets/images/logo/q3_logo-sm.png') }}" alt="Quantum Life">
        </a>

        <h1 class="q3-auth-title">Reset your password</h1>
        <p class="q3-auth-sub">Enter the email you sign in with and we'll send you a link to choose a new password.</p>

        @if($errors->any())
            <div class="alert alert-danger py-2">{{ $errors->first() }}</div>
        @endif

        @if(session('status'))
            <div class="alert alert-success py-2">{{ session('status') }}</div>
        @endif

        <form method="POST" action="{{ route('password.email') }}">
            @csrf

            <div class="mb-4">
                <label class="form-label" for="email">Email Address</label>
                <input class="form-control @error('email') is-invalid @enderror"
                       id="email" type="email" name="email" value="{{ old('email') }}"
                       placeholder="you@example.com" required autofocus autocomplete="email">
            </div>

            <button class="btn btn-primary w-100" type="submit">Email Reset Link</button>
        </form>

        <div class="q3-auth-footer">
            Remembered it?
            <a class="ms-1" href="{{ route('login') }}">Back to sign in</a>
        </div>
    </div>
</div>
@endsection
