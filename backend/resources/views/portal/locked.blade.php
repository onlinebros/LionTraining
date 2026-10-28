@extends('layouts.portal')

@section('title', 'Sign in')

@section('content')
<div class="wrap" style="padding-block: 56px;">
    <div class="sheet" style="max-width: 560px;">
        <span class="eyebrow">Private proposal</span>
        <h1 style="font-size: 32px; margin: 8px 0 14px;">{{ $gone ? 'This proposal is not available' : 'Open your proposal' }}</h1>
        @if($gone)
            <p class="muted">It may have been withdrawn while we update it. Reply to our last email and we will send you a new link.</p>
        @else
            <p>This page was prepared for {{ $prospect->company_name }}. Open it with the link in the email we sent you. If the link has expired, enter your email address and we will send a new one.</p>
            @if(session('error'))<div class="flash err">{{ session('error') }}</div>@endif
            @if(session('success'))<div class="flash ok">{{ session('success') }}</div>@endif
            <form method="POST" action="{{ route('portal.link', $ref) }}" style="margin-top: 20px;" class="stack">
                @csrf
                <div>
                    <label for="pl-email">Your email address</label>
                    <input id="pl-email" type="email" name="email" required maxlength="255" autocomplete="email">
                </div>
                <div><button class="btn" type="submit">Email me a new link</button></div>
            </form>
        @endif
    </div>
</div>
@endsection
