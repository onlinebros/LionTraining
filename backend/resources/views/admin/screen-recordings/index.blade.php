@extends('layouts.admin')

@section('title', 'Video Library')
@section('page-title', 'Video Library')

@section('breadcrumb')
    <li class="breadcrumb-item active">Video Library</li>
@endsection

@push('styles')
<style>
    .rec-thumb {
        width: 104px; height: 58px; border-radius: 6px; object-fit: cover;
        background: var(--q3-black); flex: none;
    }
    .rec-thumb-empty {
        display: flex; align-items: center; justify-content: center; color: var(--q3-text-muted);
    }
</style>
@endpush

@section('content')

@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
@if(session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif

<div class="row g-3 mb-1">
    <div class="col-6 col-lg-3">
        <div class="card"><div class="card-body py-3">
            <h6 class="mb-0">{{ $stats['total'] }}</h6><small class="text-muted">Recordings</small>
        </div></div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card"><div class="card-body py-3">
            <h6 class="mb-0">{{ $stats['published'] }}</h6><small class="text-muted">Published</small>
        </div></div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card"><div class="card-body py-3">
            <h6 class="mb-0">
                {{ $stats['bytes'] > 1073741824
                    ? round($stats['bytes'] / 1073741824, 2).' GB'
                    : round($stats['bytes'] / 1048576, 1).' MB' }}
            </h6>
            <small class="text-muted">Stored</small>
        </div></div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card"><div class="card-body py-3">
            <h6 class="mb-0 text-truncate">{{ $stats['disk'] }}</h6>
            <small class="text-muted">Storage disk</small>
        </div></div>
    </div>
</div>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <h5 class="mb-1">Screen recordings</h5>
            <small class="text-muted">
                Videos recorded in the studio or uploaded. Each one can be shown as a presentation or used as a funnel step.
            </small>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('admin.screen-recordings.upload') }}" class="btn btn-outline-primary">
                <i data-feather="upload" style="width:16px;height:16px;"></i> Upload a video
            </a>
            <a href="{{ route('admin.screen-recordings.combine') }}" class="btn btn-outline-primary">
                <i data-feather="layers" style="width:16px;height:16px;"></i> Combine videos
            </a>
            <a href="{{ route('admin.screen-recordings.studio') }}" class="btn btn-primary">
                <i data-feather="video" style="width:16px;height:16px;"></i> New recording
            </a>
        </div>
    </div>

    <div class="card-body">
        <form method="GET" class="row g-2 mb-3">
            <div class="col-md-4">
                <input type="text" name="q" value="{{ request('q') }}" class="form-control"
                       placeholder="Search title or description">
            </div>
            <div class="col-md-3">
                <select name="category" class="form-select">
                    <option value="">All categories</option>
                    @foreach($categories as $category)
                        <option value="{{ $category->id }}" @selected(request('category') == $category->id)>
                            {{ $category->name }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <select name="state" class="form-select">
                    <option value="">Published and drafts</option>
                    <option value="published" @selected(request('state') === 'published')>Published only</option>
                    <option value="draft" @selected(request('state') === 'draft')>Drafts only</option>
                </select>
            </div>
            <div class="col-md-2 d-grid">
                <button class="btn btn-outline-secondary">Filter</button>
            </div>
        </form>

        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th>Recording</th>
                        <th>Capture</th>
                        <th>Length</th>
                        <th>Size</th>
                        <th>Who can watch</th>
                        <th>Used in</th>
                        <th class="text-end">Action</th>
                    </tr>
                </thead>
                <tbody>
                @forelse($recordings as $recording)
                    <tr>
                        <td>
                            <div class="d-flex gap-2 align-items-center">
                                @if($recording->thumbnail_path)
                                    <img class="rec-thumb" alt=""
                                         src="{{ route('recordings.poster', $recording) }}">
                                @else
                                    <div class="rec-thumb rec-thumb-empty">
                                        <i data-feather="film" style="width:18px;height:18px;"></i>
                                    </div>
                                @endif
                                <div class="min-width-0">
                                    <a href="{{ route('admin.screen-recordings.show', $recording) }}"
                                       class="fw-semibold text-decoration-none d-block text-truncate"
                                       style="max-width:320px;">{{ $recording->title }}</a>
                                    <small class="text-muted">
                                        {{ $recording->author?->name ?? 'Unknown' }} ·
                                        {{ $recording->created_at->diffForHumans() }}
                                        @if($recording->category) · {{ $recording->category->name }} @endif
                                    </small>
                                    <div>
                                        @if($recording->status === \App\Models\ScreenRecording::STATUS_RENDERING)
                                            <span class="badge bg-info">Building…</span>
                                        @elseif($recording->status === \App\Models\ScreenRecording::STATUS_UPLOADING)
                                            <span class="badge bg-info">Uploading</span>
                                        @elseif($recording->status === \App\Models\ScreenRecording::STATUS_FAILED)
                                            <span class="badge bg-danger">Storage failed</span>
                                        @elseif($recording->is_published)
                                            <span class="badge bg-success">Published</span>
                                        @else
                                            <span class="badge bg-secondary">Draft</span>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        </td>
                        <td><small>{{ $recording->sourceLabel() }}</small></td>
                        <td>{{ $recording->formattedDuration() }}</td>
                        <td>{{ $recording->formattedSize() }}</td>
                        <td><small>{{ $recording->visibilityLabel() }}</small></td>
                        <td>
                            @if($recording->presentations_count)
                                <span class="badge bg-light text-dark">
                                    {{ $recording->presentations_count }}
                                    {{ Str::plural('presentation', $recording->presentations_count) }}
                                </span>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td class="text-end" style="white-space:nowrap;">
                            <a href="{{ route('admin.screen-recordings.show', $recording) }}"
                               class="btn btn-sm btn-outline-secondary">Manage</a>
                            <form method="POST" action="{{ route('admin.screen-recordings.destroy', $recording) }}"
                                  class="d-inline"
                                  onsubmit="return confirm('Delete this recording and its video file? This cannot be undone.');">
                                @csrf @method('DELETE')
                                <button class="btn btn-sm btn-outline-danger">Delete</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="text-center text-muted py-5">
                            <div class="mb-2">No recordings yet.</div>
                            <a href="{{ route('admin.screen-recordings.studio') }}" class="btn btn-sm btn-primary">
                                Record the first one
                            </a>
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>

        {{ $recordings->links() }}
    </div>
</div>
@endsection
