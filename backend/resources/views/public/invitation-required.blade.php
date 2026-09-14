@extends('layouts.public')

@section('title', 'Invitation Required — Quantum Life')
@section('body-class', 'dark-only q3-theme q3-auth')

@section('content')
<div class="q3-auth-shell">
    <div class="q3-auth-card text-center">

        <a href="{{ route('home') }}">
            <img class="q3-auth-logo" src="{{ \App\Support\Asset::v('assets/images/logo/q3_logo-sm.png') }}"
                 alt="Quantum Life">
        </a>

        <h1 class="q3-auth-title">Invitation only</h1>

        {{-- Tell them how to get in, not that they were refused. Someone
             reading this has already decided they want an account. --}}
        <p class="q3-auth-sub">{{ $notice }}</p>

        <p class="text-muted" style="font-size:.85rem;">
            If a partner has already invited you, use the link they sent —
            it signs you up under their sponsorship.
        </p>

        <a href="{{ route('login') }}" class="btn btn-primary w-100 mt-3">I already have an account</a>

        <div class="q3-auth-footer">
            <a href="{{ route('home') }}">Back to home</a>
        </div>
    </div>
</div>
@endsection
