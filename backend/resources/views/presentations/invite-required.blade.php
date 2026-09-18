@extends('layouts.public')

@section('title', 'Invitation needed' . ' — Quantum Life')

@section('content')
<div style="max-width:520px;margin:0 auto;padding:56px 16px;text-align:center;">
    <a href="{{ url('/') }}">
        <img src="{{ \App\Support\Asset::v('assets/images/logo/q3_logo-sm.png') }}"
             alt="Quantum Life" style="max-height:40px;width:auto;margin-bottom:28px;">
    </a>

    <div class="card">
        <div class="card-body p-4">
            <h4 class="mb-2">You need an invitation link</h4>

            <p class="text-muted mb-3">
                @if($unknownCode)
                    That invitation link is not valid — it may have been mistyped or copied
                    incompletely.
                @else
                    This presentation is only open to people who were personally invited.
                @endif
            </p>

            <p class="text-muted mb-0">
                Ask the person who told you about it to send you their own link. Theirs will look
                like the one you have, with their code on the end.
            </p>
        </div>
    </div>
</div>
@endsection
