@extends('layouts.admin')

@section('title', 'Video Asset: ' . $videoAsset->title)
@section('page-title', 'Video Asset')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('admin.video-assets.index') }}">Video Library</a></li>
    <li class="breadcrumb-item active">{{ Str::limit($videoAsset->title, 40) }}</li>
@endsection

@section('content')
@if(session('success'))
    <div class="alert alert-success">{{ session('success') }}</div>
@endif
@if(session('error'))
    <div class="alert alert-danger">{{ session('error') }}</div>
@endif

<div class="row">
    {{-- Left: Video info & edit --}}
    <div class="col-md-8">
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">{{ $videoAsset->title }}</h5>
                <div class="d-flex gap-2">
                    @if($videoAsset->isOnVimeo())
                        <a href="{{ $videoAsset->vimeo_url }}" target="_blank" class="btn btn-sm btn-outline-info">
                            View on Vimeo
                        </a>
                    @endif
                    <form method="POST" action="{{ route('admin.video-assets.destroy', $videoAsset) }}"
                          onsubmit="return confirm('Delete this video asset?')">
                        @csrf @method('DELETE')
                        <button class="btn btn-sm btn-outline-danger">Delete</button>
                    </form>
                </div>
            </div>
            <div class="card-body">
                <form method="POST" action="{{ route('admin.video-assets.update', $videoAsset) }}">
                    @csrf @method('PUT')
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Title</label>
                        <input type="text" name="title" class="form-control" value="{{ old('title', $videoAsset->title) }}" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Description</label>
                        <textarea name="description" class="form-control" rows="3">{{ old('description', $videoAsset->description) }}</textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Vimeo Privacy</label>
                        <select name="vimeo_privacy" class="form-select">
                            <option value="disable" {{ $videoAsset->vimeo_privacy === 'disable' ? 'selected' : '' }}>
                                Disable (embed-only on our site)
                            </option>
                            <option value="anybody" {{ $videoAsset->vimeo_privacy === 'anybody' ? 'selected' : '' }}>
                                Anybody (public)
                            </option>
                            <option value="password" {{ $videoAsset->vimeo_privacy === 'password' ? 'selected' : '' }}>
                                Password protected
                            </option>
                            <option value="nobody" {{ $videoAsset->vimeo_privacy === 'nobody' ? 'selected' : '' }}>
                                Nobody (private)
                            </option>
                        </select>
                        <div class="form-text">
                            "Disable" means the video can only be embedded on whitelisted domains and cannot be
                            watched directly on Vimeo — recommended for course content security.
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Admin Notes</label>
                        <textarea name="notes" class="form-control" rows="2">{{ old('notes', $videoAsset->notes) }}</textarea>
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm">Save Changes</button>
                </form>
            </div>
        </div>

        {{-- Assign to content block --}}
        <div class="card mb-3">
            <div class="card-header"><h6 class="mb-0">Assign to Training Content Block</h6></div>
            <div class="card-body">
                @if($videoAsset->contentBlock)
                    <div class="alert alert-info mb-3">
                        Currently assigned to:
                        <strong>{{ $videoAsset->contentBlock->lesson?->title ?? 'N/A' }}</strong>
                        → block #{{ $videoAsset->content_block_id }}
                    </div>
                @endif
                <form method="POST" action="{{ route('admin.video-assets.assign-block', $videoAsset) }}">
                    @csrf
                    <div class="d-flex gap-2">
                        <select name="content_block_id" class="form-select" required>
                            <option value="">— select a content block —</option>
                            @foreach($contentBlocks as $block)
                                <option value="{{ $block->id }}">
                                    [{{ $block->lesson?->title ?? 'No lesson' }}] {{ $block->title ?? 'Block #' . $block->id }}
                                </option>
                            @endforeach
                        </select>
                        <button type="submit" class="btn btn-outline-primary btn-sm text-nowrap">Assign</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    {{-- Right: Status panel --}}
    <div class="col-md-4">
        {{-- File info --}}
        <div class="card mb-3">
            <div class="card-header"><h6 class="mb-0">File Info</h6></div>
            <div class="card-body">
                <table class="table table-sm mb-0">
                    <tr>
                        <th class="text-muted small">Source</th>
                        <td class="small">{{ $videoAsset->source }}</td>
                    </tr>
                    <tr>
                        <th class="text-muted small">Size</th>
                        <td class="small">{{ $videoAsset->formattedSize() }}</td>
                    </tr>
                    <tr>
                        <th class="text-muted small">Mime</th>
                        <td class="small">{{ $videoAsset->mime_type ?? '—' }}</td>
                    </tr>
                    <tr>
                        <th class="text-muted small">Local File</th>
                        <td class="small">
                            @if($videoAsset->isDownloaded())
                                <span class="text-success">✓ On server</span>
                                <div class="text-muted" style="word-break:break-all;font-size:11px">
                                    {{ $videoAsset->local_path }}
                                </div>
                            @else
                                <span class="text-warning">Not downloaded</span>
                            @endif
                        </td>
                    </tr>
                    @if($videoAsset->source_url)
                    <tr>
                        <th class="text-muted small">Source URL</th>
                        <td class="small" style="word-break:break-all">
                            <a href="{{ $videoAsset->source_url }}" target="_blank" class="text-muted small">
                                {{ Str::limit($videoAsset->source_url, 50) }}
                            </a>
                        </td>
                    </tr>
                    @endif
                </table>
            </div>
        </div>

        {{-- Vimeo status --}}
        <div class="card mb-3">
            <div class="card-header"><h6 class="mb-0">Vimeo Upload</h6></div>
            <div class="card-body">
                @if($videoAsset->vimeo_status === 'uploaded')
                    <div class="alert alert-success mb-2 py-2">
                        <i data-feather="check-circle" data-width="14"></i> Uploaded
                    </div>
                    <p class="small mb-1">
                        Video ID: <code>{{ $videoAsset->vimeo_video_id }}</code>
                    </p>
                    <p class="small mb-1">
                        URL: <a href="{{ $videoAsset->vimeo_url }}" target="_blank">{{ $videoAsset->vimeo_url }}</a>
                    </p>
                    <p class="small mb-1">
                        Embed: <code class="small">{{ $videoAsset->vimeo_embed_url }}</code>
                    </p>
                    <p class="small text-muted">
                        Uploaded: {{ $videoAsset->vimeo_uploaded_at?->diffForHumans() ?? '—' }}
                    </p>
                @elseif($videoAsset->vimeo_status === 'failed')
                    <div class="alert alert-danger mb-2 py-2">Upload failed</div>
                    <p class="small text-danger">{{ $videoAsset->vimeo_upload_error }}</p>
                    <form method="POST" action="{{ route('admin.video-assets.upload-vimeo', $videoAsset) }}">
                        @csrf
                        <button class="btn btn-sm btn-primary w-100">Retry Upload</button>
                    </form>
                @elseif($videoAsset->vimeo_status === 'uploading')
                    <div class="alert alert-warning mb-0">Currently uploading…</div>
                @else
                    @if($videoAsset->isDownloaded())
                        <form method="POST" action="{{ route('admin.video-assets.upload-vimeo', $videoAsset) }}">
                            @csrf
                            <button class="btn btn-sm btn-primary w-100">
                                <i data-feather="upload-cloud" data-width="14"></i> Upload to Vimeo
                            </button>
                        </form>
                        <p class="text-muted small mt-2 mb-0">
                            This will upload the local file to your Vimeo account with the
                            <strong>{{ $videoAsset->vimeo_privacy ?? 'disable' }}</strong> privacy setting.
                        </p>
                    @else
                        <p class="text-muted small">Download the file first before uploading to Vimeo.</p>
                    @endif
                @endif
            </div>
        </div>
    </div>
</div>
@endsection
