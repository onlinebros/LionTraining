@extends('layouts.public')

@section('title', $presentation->title . ' — Quantum Life')
@section('meta-description', Str::limit(strip_tags((string) $presentation->description), 150) ?: 'Register for this presentation.')

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
            @if($presentation->hasEnded())
                <span class="when when--done"><span class="dot"></span>This presentation has finished</span>
            @elseif($presentation->isLive())
                <span class="when when--live"><span class="dot"></span>Happening now — join in</span>
            @else
                <span class="when">
                    <span class="dot"></span>
                    @include('partials.presentation-time', ['presentation' => $presentation])
                </span>
            @endif

            <h1 class="reg__title">{{ $presentation->title }}</h1>

            @if($presentation->description)
                <p class="reg__desc">{{ $presentation->description }}</p>
            @endif

            @if($host)
                <div class="host">
                    <span class="host__avatar">{{ Str::upper(Str::substr($host->name, 0, 1)) }}</span>
                    <span class="host__text">
                        <strong>{{ $host->name }}</strong> invited you.<br>
                        They will be here to answer your questions.
                    </span>
                </div>
            @endif

            @if(session('error'))
                <div class="closed mb-3" style="color:#FF7A6E;border-color:rgba(255,68,56,.35);">
                    {{ session('error') }}
                </div>
            @endif

            @if(! $presentation->is_open)
                <div class="closed">Registration for this presentation is closed.</div>
            @else
                <form method="POST" action="{{ route('presentations.register', $presentation) }}">
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

                    @if($presentation->collect_phone)
                        <div class="field">
                            <label for="phone">Phone number</label>
                            <input type="tel" name="phone" id="phone" required maxlength="40"
                                   value="{{ old('phone') }}" autocomplete="tel" placeholder="(555) 010-0100">
                            @error('phone')<div class="err">{{ $message }}</div>@enderror
                        </div>
                    @endif

                    <button class="btn-go" type="submit">
                        {{ $presentation->isLive() ? 'Join now' : 'Save my spot' }}
                    </button>

                    {{-- Kept deliberately short. How the presentation itself is
                         described is a commercial decision and is made elsewhere;
                         what stays here is the data notice — who sees that you
                         attended, and what the email is used for. --}}
                    <p class="note">
                        @if($host) {{ $host->name }} will be able to see that you attended. @endif
                        We will use your email to send you the replay and to follow up about it.
                    </p>
                </form>
            @endif
        </div>
    </div>
</div>
@endsection
