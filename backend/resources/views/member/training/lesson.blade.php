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
.content-block { margin-bottom: 2rem; }
.content-block .block-title {
    font-weight: 700; font-size: 1.05rem; margin-bottom: .75rem;
    padding-bottom: .4rem; border-bottom: 2px solid var(--theme-default);
    display: inline-block;
}
.lesson-rich-text { line-height: 1.8; }
.lesson-rich-text img { max-width: 100%; border-radius: 6px; }
.download-block {
    display: flex; align-items: center; gap: 14px;
    padding: 14px 18px; border: 1px solid #e8eaf0; border-radius: 8px;
    background: #f8f9fc; transition: border-color .2s;
}
.download-block:hover { border-color: var(--theme-default); }
.download-icon {
    width: 44px; height: 44px; background: var(--theme-default);
    border-radius: 10px; display: flex; align-items: center; justify-content: center; flex-shrink: 0;
}
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

<div class="row">
    <div class="col-lg-9">
        @foreach($blocks as $block)
        <div class="content-block">

            @if($block->title)
                <div class="block-title">{{ $block->title }}</div>
            @endif

            @if($block->type === 'video')
                @if($block->isEmbeddable())
                    <div class="ratio ratio-16x9 mb-2" style="max-width:720px;">
                        <iframe src="{{ $block->embedUrl() }}" allowfullscreen
                                allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture">
                        </iframe>
                    </div>
                @elseif($block->video_url)
                    <video src="{{ $block->video_url }}" controls
                           style="max-width:720px;width:100%;border-radius:8px;">
                        Your browser does not support video.
                    </video>
                @endif

            @elseif($block->type === 'text')
                <div class="lesson-rich-text">{!! $block->body !!}</div>

            @elseif($block->type === 'download')
                <a href="{{ route('member.training.download', $block) }}" class="download-block text-decoration-none text-dark">
                    <div class="download-icon">
                        <i data-feather="download" style="width:20px;height:20px;color:#fff;"></i>
                    </div>
                    <div class="flex-grow-1">
                        <div class="fw-semibold">{{ $block->title ?: $block->file_name }}</div>
                        @if($block->file_name && $block->title)
                            <small class="text-muted">{{ $block->file_name }}</small>
                        @endif
                        <small class="text-muted d-block">{{ $block->formattedFileSize() }}</small>
                    </div>
                    <span class="btn btn-primary btn-sm">Download</span>
                </a>
            @endif

        </div>
        @endforeach
    </div>

    {{-- Lesson nav sidebar --}}
    @if($siblings->isNotEmpty())
    <div class="col-lg-3">
        <div class="card" style="position:sticky;top:20px;">
            <div class="card-header py-2"><h6 class="mb-0">In this category</h6></div>
            <div class="card-body p-0">
                <ul class="list-unstyled mb-0">
                    @foreach($siblings as $sib)
                    @php $sibLocked = !$sib->userCanAccess($user); @endphp
                    <li>
                        <a href="{{ $sibLocked ? '#' : route('member.training.lesson', $sib->slug) }}"
                           class="d-flex align-items-center gap-2 px-3 py-2 text-decoration-none border-bottom
                                  {{ $sib->id === $lesson->id ? 'bg-light fw-semibold' : 'text-dark' }}"
                           style="{{ $sibLocked ? 'opacity:.6;' : '' }}">
                            @if($sib->id === $lesson->id)
                                <i data-feather="play-circle" style="width:14px;height:14px;color:var(--theme-default);flex-shrink:0;"></i>
                            @elseif($sibLocked)
                                <i data-feather="lock" style="width:14px;height:14px;flex-shrink:0;color:#aaa;"></i>
                            @else
                                <i data-feather="circle" style="width:14px;height:14px;flex-shrink:0;color:#aaa;"></i>
                            @endif
                            <span class="small">{{ $sib->title }}</span>
                        </a>
                    </li>
                    @endforeach
                </ul>
            </div>
        </div>
    </div>
    @endif
</div>

{{-- Prev / Next navigation --}}
@if($prev || $next)
<div class="d-flex justify-content-between mt-4 pt-3 border-top">
    @if($prev)
        <a href="{{ route('member.training.lesson', $prev->slug) }}" class="btn btn-outline-secondary">
            <i data-feather="arrow-left" style="width:14px;height:14px;"></i> {{ $prev->title }}
        </a>
    @else
        <span></span>
    @endif
    @if($next)
        <a href="{{ route('member.training.lesson', $next->slug) }}" class="btn btn-primary">
            {{ $next->title }} <i data-feather="arrow-right" style="width:14px;height:14px;"></i>
        </a>
    @endif
</div>
@endif

@endif

@endsection
