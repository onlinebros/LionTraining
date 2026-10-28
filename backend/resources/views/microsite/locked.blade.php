@extends('layouts.portal')

@section('title', 'Sign in')

@include('microsite._brand')

@section('content')
<div class="wrap" style="padding-block: 56px;">
    <div class="sheet" style="max-width: 560px;">
        <span class="eyebrow">Private site</span>
        <h1 style="font-size: 32px; margin: 8px 0 14px;">Prepared for {{ $account->name }}</h1>
        <p>Open it with the link in the email we sent you. If the link has expired, enter your email address and we will send a new one.</p>
        <p class="muted">Not on the list? Ask a colleague who is to add you from inside the site.</p>
        @if(session('error'))<div class="flash err">{{ session('error') }}</div>@endif
        @if(session('success'))<div class="flash ok">{{ session('success') }}</div>@endif
        <form method="POST" action="{{ route('microsite.link', $ref) }}" style="margin-top: 20px;" class="stack">
            @csrf
            <div>
                <label for="ml-email">Your email address</label>
                <input id="ml-email" type="email" name="email" required maxlength="255" autocomplete="email">
            </div>
            <div><button class="btn" type="submit">Email me a new link</button></div>
        </form>
    </div>
</div>
@endsection
