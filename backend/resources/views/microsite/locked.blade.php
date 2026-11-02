@extends('microsite.layout')

@section('title', 'Sign in')

@section('body')
<main class="hero" style="min-height: 100vh;">
    <div class="orb o1"></div><div class="orb o2"></div><div class="grid-bg"></div>
    <div class="wrap" style="max-width: 640px;">
        <span class="brand rv" style="margin-bottom: 30px;">
            @if($account->logo_path)
                <img class="client-logo" src="{{ route('microsite.logo', $ref) }}?v={{ $account->updated_at?->timestamp }}" alt="{{ $account->name }}">
            @else
                <span>{{ $account->name }}</span>
            @endif
            <span class="x" aria-hidden="true">×</span>
            <span class="q3"><img src="{{ \App\Support\Asset::v('assets/images/logo/q3-app-icon-192.png') }}" alt=""><span>Quantum 3 Solution</span></span>
        </span>
        <span class="eyebrow rv">Private site</span>
        <h1 class="rv" style="font-size: clamp(36px, 6vw, 60px); margin: 16px 0 18px;">Prepared for <span class="grad">{{ $account->name }}</span></h1>
        <p class="lead rv">Open it with the link in the email we sent you. If the link has expired, enter your email address and we will send a new one.</p>
        <form method="POST" action="{{ route('microsite.link', $ref) }}" class="stack rv" style="margin-top: 26px;">
            @csrf
            <div>
                <label for="ml-email">Your email address</label>
                <input id="ml-email" type="email" name="email" required maxlength="255" autocomplete="email">
            </div>
            <div><button class="btn" type="submit">Email me a new link</button></div>
        </form>
        <p class="muted rv" style="margin-top: 22px;">Not on the list? Ask a colleague who is to add you from inside the site.</p>
    </div>
</main>
@if(session('success'))<div class="flash ok" role="status">{{ session('success') }}</div>@endif
@if(session('error'))<div class="flash err" role="alert">{{ session('error') }}</div>@endif
@endsection

@push('scripts')
<script>document.querySelectorAll('.rv').forEach(function (el, i) { setTimeout(function () { el.classList.add('in'); }, 80 * i); });</script>
@endpush
