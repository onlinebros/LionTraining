@extends('layouts.public')

@section('title', $title . ' — Quantum Life')
@section('body-class', 'dark-only q3-theme q3-auth')

@section('content')
<div class="q3-auth-shell">
    <div class="q3-auth-card text-center">

        <a href="{{ route('home') }}">
            <img class="q3-auth-logo" src="{{ \App\Support\Asset::v('assets/images/logo/q3_logo-sm.png') }}"
                 alt="Quantum Life">
        </a>

        <h3 class="mb-3">Not open yet</h3>

        {{-- Deliberately different copy from the member page: this reader
             has no account, and wording about building a team means
             nothing to them. --}}
        <p class="text-muted mb-4">{{ $notice }}</p>

        <a href="{{ route('home') }}" class="btn btn-primary">Back to home</a>

    </div>
</div>
@endsection
