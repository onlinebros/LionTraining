@extends('layouts.member')

@section('title', $lesson->title . ' — Training')
@section('page-title', $lesson->title)

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('member.training') }}">Training</a></li>
    @foreach($breadcrumb as $crumb)
        <li class="breadcrumb-item">
            <a href="{{ route('member.training.category', $crumb->slug) }}">{{ $crumb->name }}</a>
        </li>
    @endforeach
    <li class="breadcrumb-item active">{{ $lesson->title }}</li>
@endsection

@push('styles')
<style>
.content-block { margin-bottom: 2.25rem; }

.lesson-rich-text {
    line-height: 1.85;
    font-size: .97rem;
}
.lesson-rich-text img { max-width: 100%; border-radius: 6px; margin: .5rem 0; }
.lesson-rich-text p:last-child { margin-bottom: 0; }

/* Video placeholder */
.video-placeholder {
    max-width: 720px;
    background: linear-gradient(135deg, var(--q3-surface-2) 0%, var(--q3-black) 100%);
    border: 1px solid var(--q3-border-gold-soft);
    border-radius: var(--q3-radius);
    padding: 4rem 2rem;
    text-align: center;
    color: var(--q3-text);
    position: relative;
    overflow: hidden;
}
.video-placeholder::before {
    content: '';
    position: absolute;
    inset: 0;
    background: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23ffffff' fill-opacity='0.03'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
}
.video-placeholder-icon {
    width: 72px; height: 72px;
    background: var(--q3-gold-tint-2);
    border: 1px solid var(--q3-border-gold);
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    margin: 0 auto 1.25rem;
}
.video-placeholder h5 { color: var(--q3-text); font-weight: 600; margin-bottom: .5rem; }
.video-placeholder p { color: var(--q3-text-muted); font-size: .9rem; margin: 0; }
.video-placeholder .badge-coming-soon {
    display: inline-block;
    background: var(--q3-gold-tint-2);
    border: 1px solid var(--q3-border-gold);
    color: var(--q3-gold-high);
    font-size: .75rem;
    font-weight: 600;
    padding: .3rem .85rem;
    border-radius: 50px;
    margin-bottom: 1.25rem;
    letter-spacing: .04em;
    text-transform: uppercase;
}

/* Download block */
.download-block {
    display: flex; align-items: center; gap: 14px;
    padding: 14px 18px;
    border: 1px solid var(--q3-border);
    border-radius: var(--q3-radius);
    background: var(--q3-surface-2);
    transition: border-color .2s, box-shadow .2s;
    max-width: 560px;
}
.download-block:hover {
    border-color: var(--q3-gold-soft);
    box-shadow: 0 0 0 1px var(--q3-border-gold);
}
.download-icon {
    width: 48px; height: 48px;
    background: var(--q3-gold-tint-2);
    border: 1px solid var(--q3-border-gold);
    border-radius: var(--q3-radius-sm);
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
}

/* Sidebar nav */
.lesson-sidebar { position: sticky; top: 20px; }
.lesson-nav-item {
    display: flex; align-items: center; gap: 10px;
    padding: 9px 14px;
    border-bottom: 1px solid var(--q3-border);
    text-decoration: none;
    color: var(--q3-text-body);
    font-size: .875rem;
    transition: background .15s;
}
.lesson-nav-item:hover { background: var(--q3-gold-tint); color: var(--q3-gold-high); }
.lesson-nav-item.active { background: var(--q3-gold-tint-2); font-weight: 600; color: var(--q3-gold-high); }
.lesson-nav-item.locked { opacity: .55; cursor: default; }
</style>
@endpush

@section('content')

@if($lesson->description)
<div class="alert alert-light mb-4">{{ $lesson->description }}</div>
@endif

@if($blocks->isEmpty())
<div class="text-muted text-center py-5">
    <i data-feather="inbox" style="width:40px;height:40px;opacity:.3;"></i>
    <p class="mt-2">Content coming soon.</p>
</div>
@else

<div class="row g-4">
    {{-- Main content --}}
    <div class="col-lg-8">

        @foreach($blocks as $block)
        <div class="content-block">

            @if($block->title && $block->type !== 'video')
                <h5 class="fw-bold mb-3 pb-2" style="border-bottom:2px solid var(--theme-default);display:inline-block;">
                    {{ $block->title }}
                </h5>
            @endif

            {{-- VIDEO --}}
            @if($block->type === 'video')
                @php
                    $asset    = $block->videoAsset;
                    $hasVimeo = $asset && $asset->isOnVimeo();
                @endphp

                @if($hasVimeo)
                    {{-- Vimeo embed --}}
                    <div class="ratio ratio-16x9 mb-1" style="max-width:720px;border-radius:10px;overflow:hidden;">
                        <iframe src="{{ $asset->vimeo_embed_url }}" allowfullscreen
                                allow="autoplay; fullscreen; picture-in-picture">
                        </iframe>
                    </div>

                @elseif($block->isEmbeddable())
                    {{-- YouTube or other embeddable --}}
                    <div class="ratio ratio-16x9 mb-1" style="max-width:720px;border-radius:10px;overflow:hidden;">
                        <iframe src="{{ $block->embedUrl() }}" allowfullscreen
                                allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture">
                        </iframe>
                    </div>

                @elseif($asset || $block->video_url)
                    {{-- Video downloaded locally but not yet on Vimeo — show placeholder --}}
                    <div class="video-placeholder mb-1">
                        <div class="video-placeholder-icon">
                            <i data-feather="film" style="width:32px;height:32px;color:var(--q3-gold);"></i>
                        </div>
                        <div class="badge-coming-soon">Video Coming Soon</div>
                        <h5>Lesson Video</h5>
                        <p>This video is being processed and will be available here shortly.<br>
                            Check back soon or continue reading the lesson content below.</p>
                    </div>
                @endif

            {{-- TEXT --}}
            @elseif($block->type === 'text')
                <div class="lesson-rich-text">{!! $block->body !!}</div>

            {{-- DOWNLOAD --}}
            @elseif($block->type === 'download')
                <a href="{{ route('member.training.download', $block) }}" class="download-block text-decoration-none">
                    <div class="download-icon">
                        <i data-feather="file-text" style="width:22px;height:22px;color:var(--q3-gold);"></i>
                    </div>
                    <div class="flex-grow-1 min-w-0">
                        <div class="fw-semibold" style="font-size:.95rem;">
                            {{ $block->title ?: $block->file_name }}
                        </div>
                        @if($block->file_name && $block->title && $block->file_name !== $block->title)
                            <small class="text-muted d-block">{{ $block->file_name }}</small>
                        @endif
                        <small class="text-muted">{{ $block->formattedFileSize() }}</small>
                    </div>
                    <span class="btn btn-primary btn-sm flex-shrink-0">
                        <i data-feather="download" style="width:14px;height:14px;"></i>
                        Download
                    </span>
                </a>
            @endif

        </div>
        @endforeach

        {{-- Prev / Next navigation --}}
        @if($prev || $next)
        <div class="d-flex justify-content-between mt-4 pt-3 border-top">
            @if($prev)
                <a href="{{ route('member.training.lesson', $prev->slug) }}" class="btn btn-outline-secondary">
                    <i data-feather="arrow-left" style="width:14px;height:14px;"></i>
                    {{ Str::limit($prev->title, 35) }}
                </a>
            @else
                <span></span>
            @endif
            @if($next)
                <a href="{{ route('member.training.lesson', $next->slug) }}" class="btn btn-primary">
                    {{ Str::limit($next->title, 35) }}
                    <i data-feather="arrow-right" style="width:14px;height:14px;"></i>
                </a>
            @endif
        </div>
        @endif
    </div>

    {{-- Lesson list sidebar --}}
    @if($siblings->isNotEmpty())
    <div class="col-lg-4">
        <div class="card lesson-sidebar">
            <div class="card-header py-2 d-flex align-items-center gap-2">
                <i data-feather="list" style="width:15px;height:15px;"></i>
                <h6 class="mb-0">{{ $lesson->category->name }}</h6>
            </div>
            <div class="card-body p-0" style="max-height:520px;overflow-y:auto;">
                @foreach($siblings as $sib)
                @php
                    $sibLocked = !$sib->userCanAccess($user);
                    $isCurrent = $sib->id === $lesson->id;
                @endphp
                <a href="{{ $sibLocked || $isCurrent ? '#' : route('member.training.lesson', $sib->slug) }}"
                   class="lesson-nav-item {{ $isCurrent ? 'active' : '' }} {{ $sibLocked ? 'locked' : '' }}"
                   @if($sibLocked || $isCurrent) tabindex="-1" @endif>
                    @if($isCurrent)
                        <i data-feather="play-circle" style="width:14px;height:14px;color:var(--theme-default);flex-shrink:0;"></i>
                    @elseif($sibLocked)
                        <i data-feather="lock" style="width:14px;height:14px;flex-shrink:0;color:var(--q3-text-dim);"></i>
                    @else
                        <i data-feather="circle" style="width:14px;height:14px;flex-shrink:0;color:var(--q3-text-dim);"></i>
                    @endif
                    <span>{{ $sib->title }}</span>
                </a>
                @endforeach
            </div>
        </div>
    </div>
    @endif
</div>

@endif

@endsection
