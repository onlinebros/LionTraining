@extends('layouts.member')

@section('title', $title)
@section('page-title', $title)

@section('breadcrumb')
    <li class="breadcrumb-item active">{{ $title }}</li>
@endsection

@section('content')
<div class="row justify-content-center">
    <div class="col-md-8 col-lg-6">
        <div class="card mb-0">
            <div class="card-body text-center py-5">

                <div class="mb-4">
                    <i class="icofont icofont-clock-time" style="font-size:3rem;color:var(--theme-default);"></i>
                </div>

                <h4 class="mb-3">{{ $title }} opens at launch</h4>

                <p class="text-muted mb-4">{{ $notice }}</p>

                @if ($endsAt)
                    {{-- Only rendered when a date is actually published. A
                         countdown that resets is worse than no countdown, so
                         config('prelaunch.ends_at') stays unset while the date
                         might still slip. --}}
                    <div class="mb-4">
                        <div class="fs-4 fw-bold text-primary">{{ $endsAt->format('j F Y') }}</div>
                        <div class="text-muted small">
                            {{ $endsAt->isFuture() ? $endsAt->diffForHumans(null, true) . ' to go' : 'Opening now' }}
                        </div>
                    </div>
                @endif

                <div class="d-flex gap-2 justify-content-center flex-wrap">
                    <a href="{{ route('member.dashboard') }}" class="btn btn-primary">Back to dashboard</a>
                    <a href="{{ route('member.referrals') }}" class="btn btn-outline-primary">Invite your team</a>
                </div>

            </div>
        </div>
    </div>
</div>
@endsection
