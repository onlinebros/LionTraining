@extends('layouts.public')

@section('title', $funnel->title . ' — Quantum Life')
@section('meta-description', Str::limit(strip_tags((string) $funnel->description), 150) ?: 'Start here.')

@push('styles')
@include('partials.guest-door-styles')
@endpush

@section('content')
<div class="reg">
    <div class="reg__inner">
        <a href="{{ url('/') }}" class="reg__logo">
            <img src="{{ \App\Support\Asset::v('assets/images/logo/q3_logo-sm.png') }}"
                 alt="Quantum Life" style="max-height:42px;width:auto;">
        </a>

        <div class="reg__card">
            <span class="when"><span class="dot"></span>Start whenever you like</span>

            <h1 class="reg__title">{{ $funnel->title }}</h1>

            @if($funnel->description)
                <p class="reg__desc">{{ $funnel->description }}</p>
            @endif

            @if($host)
                <div class="host">
                    <span class="host__avatar">{{ Str::upper(Str::substr($host->name, 0, 1)) }}</span>
                    <span class="host__text">
                        <strong>{{ $host->name }}</strong> invited you.<br>
                        They will be here to answer your questions as you go.
                    </span>
                </div>
            @endif

            {{-- Only the opening video is named. The rest depends on what they
                 pick, and promising a fixed running order would be a lie. --}}
            @if($funnel->entry)
                <ul class="steps">
                    <li>
                        <span class="steps__n">1</span>
                        <span>
                            {{ $funnel->entry->title }}
                            <span class="steps__len">· {{ $funnel->entry->formattedDuration() }}</span>
                        </span>
                    </li>
                    <li>
                        <span class="steps__n">2</span>
                        <span>You choose what you want to see next.</span>
                    </li>
                </ul>
            @endif

            @if(session('error'))
                <div class="closed mb-3" style="color:#FF7A6E;border-color:rgba(255,68,56,.35);">
                    {{ session('error') }}
                </div>
            @endif

            <form method="POST" action="{{ route('funnels.register', $funnel) }}">
                @csrf
                <input type="hidden" name="code" value="{{ $code }}">

                <div class="field">
                    <label for="name">Your name</label>
                    <input type="text" name="name" id="name" required maxlength="120"
                           value="{{ old('name') }}" autocomplete="name" placeholder="Jane Miller">
                    @error('name')<div class="err">{{ $message }}</div>@enderror
                </div>

                <div class="field">
                    <label for="email">Email address</label>
                    <input type="email" name="email" id="email" required maxlength="190"
                           value="{{ old('email') }}" autocomplete="email" placeholder="jane@example.com">
                    @error('email')<div class="err">{{ $message }}</div>@enderror
                </div>

                <button class="btn-go" type="submit">Start watching</button>

                <p class="note">
                    @if($host) {{ $host->name }} will be able to see what you watch and choose. @endif
                    We will use your email to send you whatever you ask for and to follow up about it.
                </p>
            </form>
        </div>
    </div>
</div>
@endsection
