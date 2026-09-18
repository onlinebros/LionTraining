@extends('layouts.public')

@section('title', $recording->title . ' — Quantum Life')
@section('meta-description', Str::limit(strip_tags((string) $recording->description), 150) ?: 'Training video')

@push('styles')
<style>
    .watch-wrap { max-width: 1000px; margin: 0 auto; padding: 32px 16px 64px; }
    .watch-player { background:#0b1020; border-radius:12px; overflow:hidden; }
    .watch-player video { width:100%; display:block; max-height:78vh; background:#0b1020; }
</style>
@endpush

@section('content')
<div class="watch-wrap">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <a href="{{ url('/') }}">
            <img src="{{ \App\Support\Asset::v('assets/images/logo/q3_logo-sm.png') }}"
                 alt="Quantum Life" style="max-height:36px;width:auto;">
        </a>
        @auth
            <a href="{{ auth()->user()->isAdmin() ? route('admin.screen-recordings.show', $recording) : route('member.training') }}"
               class="btn btn-sm btn-outline-secondary">
                {{ auth()->user()->isAdmin() ? 'Manage recording' : 'Back to training' }}
            </a>
        @endauth
    </div>

    <div class="watch-player mb-3">
        <video controls playsinline preload="metadata"
               @if($recording->thumbnail_path) poster="{{ route('recordings.poster', $recording) }}" @endif
               src="{{ $playback }}">
            Your browser cannot play this video.
        </video>
    </div>

    <h4 class="mb-1">{{ $recording->title }}</h4>
    <p class="text-muted mb-3">
        {{ $recording->formattedDuration() }}
        · Recorded {{ $recording->created_at->format('M j, Y') }}
        @if($recording->author) · {{ $recording->author->name }} @endif
    </p>

    @if($recording->description)
        <p style="white-space:pre-line;">{{ $recording->description }}</p>
    @endif

    @unless($recording->is_published)
        <div class="alert alert-warning mt-3">
            This recording is still a draft — members cannot see it yet.
        </div>
    @endunless
</div>
@endsection
