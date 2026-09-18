@extends('layouts.admin')

@section('title', 'Upload a Video')
@section('page-title', 'Upload a Video')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('admin.screen-recordings.index') }}">Video Library</a></li>
    <li class="breadcrumb-item active">Upload</li>
@endsection

@push('styles')
<style>
    .drop {
        border: 2px dashed var(--q3-border-strong); border-radius: 12px; background: var(--q3-surface-2);
        padding: 44px 24px; text-align: center; cursor: pointer;
        transition: border-color .12s, background .12s;
    }
    .drop:hover, .drop.is-over { border-color: var(--theme-default); background: rgba(var(--rgb-primary), .05); }
    .drop svg { width: 34px; height: 34px; color: var(--q3-text-dim); margin-bottom: 10px; }
    .drop__title { font-weight: 600; color: var(--q3-text); }
    .drop__hint { font-size: 13px; color: var(--q3-text-muted); margin-top: 4px; }
    .picked { display: none; background: var(--q3-surface); border: 1px solid var(--q3-border); border-radius: 10px; padding: 16px; }
    .picked__name { font-weight: 600; word-break: break-all; }
    .bar { height: 8px; background: var(--q3-surface-3); border-radius: 999px; overflow: hidden; margin-top: 10px; }
    .bar__fill { height: 100%; width: 0; background: var(--theme-default); transition: width .25s; }
</style>
@endpush

@section('content')
<div class="row g-3">
    <div class="col-xl-7">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-1">Choose a video</h5>
                <small class="text-muted">
                    Already recorded somewhere else — Zoom, a phone, a camera. Once it is in, it
                    behaves exactly like anything recorded in the studio: trim it, combine it,
                    or schedule it as a presentation.
                </small>
            </div>
            <div class="card-body">
                @unless($inspector)
                    <div class="alert alert-warning">
                        Video tools are not installed on this server, so uploads cannot be checked
                        or given a thumbnail. Ask your developer to install ffmpeg.
                    </div>
                @endunless

                <div class="drop" id="drop" role="button" tabindex="0">
                    <svg><use href="{{ asset('assets/svg/icon-sprite.svg#stroke-file') }}"></use></svg>
                    <div class="drop__title">Drop a video here, or click to choose one</div>
                    <div class="drop__hint">
                        MP4 works best. MOV, WebM and M4V are fine too.
                        Up to {{ round($maxBytes / 1073741824, 1) }} GB.
                    </div>
                    <input type="file" id="file" accept="video/*,.mp4,.mov,.m4v,.webm" hidden>
                </div>

                <div class="picked mt-3" id="picked">
                    <div class="d-flex justify-content-between align-items-start gap-3">
                        <div>
                            <div class="picked__name" id="file-name"></div>
                            <small class="text-muted" id="file-meta"></small>
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="clear-btn">Change</button>
                    </div>
                    <div class="bar"><div class="bar__fill" id="progress"></div></div>
                    <small class="text-muted d-block mt-1" id="progress-text"></small>
                </div>

                <div id="status" class="alert alert-light py-2 mt-3" style="display:none;"></div>

                <div class="mt-3 d-flex gap-2">
                    <button class="btn btn-primary" id="upload-btn" disabled>Upload</button>
                    <a href="{{ route('admin.screen-recordings.index') }}" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </div>
        </div>
    </div>

    <div class="col-xl-5">
        <div class="card">
            <div class="card-header"><h5 class="mb-0">Details</h5></div>
            <div class="card-body">
                <div class="mb-3">
                    <label class="form-label" for="field-title">Title</label>
                    <input type="text" id="field-title" class="form-control" maxlength="200"
                           placeholder="September opportunity call">
                    <small class="text-muted">Left blank, the file name is used.</small>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="field-category">Training category</label>
                    <select id="field-category" class="form-select">
                        <option value="">Unfiled</option>
                        @foreach($categories as $category)
                            <option value="{{ $category->id }}">{{ $category->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="form-label" for="field-description">Description</label>
                    <textarea id="field-description" class="form-control" rows="3"
                              placeholder="What this video covers"></textarea>
                </div>
                <small class="text-muted d-block mt-3">
                    Uploads arrive as drafts — nobody sees them until you publish. The length and
                    size are measured from the file itself once it lands.
                </small>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
    window.UPLOAD_CONFIG = {
        csrf: document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
        createUrl: @json(route('admin.screen-recordings.store')),
        chunkBytes: {{ (int) $chunkBytes }},
        maxBytes: {{ (int) $maxBytes }}
    };
</script>
<script src="{{ \App\Support\Asset::v('assets/js/chunked-uploader.js') }}"></script>
<script src="{{ \App\Support\Asset::v('assets/js/recording-upload.js') }}"></script>
@endpush
