@extends('layouts.admin')

@section('title', 'Recording Studio')
@section('page-title', 'Recording Studio')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('admin.screen-recordings.index') }}">Video Library</a></li>
    <li class="breadcrumb-item active">Studio</li>
@endsection

@push('styles')
<style>
    .stage {
        position: relative;
        background: var(--q3-black);
        border-radius: 10px;
        overflow: hidden;
        aspect-ratio: 16 / 9;
    }
    .stage canvas { width: 100%; height: 100%; display: block; object-fit: contain; }
    .stage .stage__placeholder {
        position: absolute; inset: 0;
        display: flex; flex-direction: column; align-items: center; justify-content: center;
        color: var(--q3-text-muted); text-align: center; padding: 24px; gap: 8px;
    }
    .stage .countdown {
        position: absolute; inset: 0; display: none;
        align-items: center; justify-content: center;
        background: rgba(5, 5, 5, 0.72); color: #fff;
        font-size: clamp(48px, 12vw, 140px); font-weight: 700;
    }
    .rec-dot {
        display: inline-block; width: 10px; height: 10px; border-radius: 50%;
        background: #e42e2c; margin-right: 6px; animation: recPulse 1.2s infinite;
    }
    @keyframes recPulse { 0%,100% { opacity: 1 } 50% { opacity: .25 } }

    /* Corner picker — a miniature of the frame, so the choice is literal. */
    .corner-picker {
        display: grid; grid-template-columns: 1fr 1fr; gap: 8px;
        aspect-ratio: 16 / 9; background: var(--q3-surface-2); border-radius: 8px; padding: 8px;
    }
    .corner-picker button {
        border: 1px dashed var(--q3-border-strong); background: var(--q3-surface); border-radius: 6px;
        display: flex; align-items: center; justify-content: center;
        font-size: 11px; color: var(--q3-text-muted); cursor: pointer; transition: all .12s;
    }
    .corner-picker button:hover { border-color: var(--theme-default); color: var(--theme-default); }
    .corner-picker button.active {
        border-style: solid; border-color: var(--theme-default);
        background: rgba(var(--rgb-primary), .12); color: var(--theme-default); font-weight: 600;
    }
    .studio-panel .form-label { font-weight: 500; }

    /* Mode picker — the first decision, so it reads as three real choices
       rather than a row of equally-weighted toolbar buttons. */
    .mode-picker { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; }
    @media (max-width: 767px) { .mode-picker { grid-template-columns: 1fr; } }
    .mode-btn {
        display: flex; flex-direction: column; align-items: flex-start; gap: 2px;
        padding: 12px 14px; text-align: left; cursor: pointer;
        border: 1px solid var(--q3-border); border-radius: 8px; background: var(--q3-surface);
        transition: border-color .12s, background .12s, box-shadow .12s;
    }
    .mode-btn svg { width: 18px; height: 18px; margin-bottom: 4px; color: var(--q3-text-muted); }
    .mode-btn span { font-weight: 600; color: var(--q3-text); font-size: 14px; }
    .mode-btn small { color: var(--q3-text-muted); font-size: 11.5px; line-height: 1.3; }
    .mode-btn:hover:not(:disabled) { border-color: var(--theme-default); }
    .mode-btn.active {
        border-color: var(--theme-default);
        background: rgba(var(--rgb-primary), .08);
        box-shadow: inset 0 0 0 1px var(--theme-default);
    }
    .mode-btn.active svg, .mode-btn.active span { color: var(--theme-default); }
    .mode-btn:disabled { opacity: .5; cursor: not-allowed; }
</style>
@endpush

@section('content')
<div class="row g-3">

    <div class="col-xl-8">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <h5 class="mb-1">Capture</h5>
                    <small class="text-muted">
                        The webcam is baked into the video as you record — what you see here is exactly
                        what gets saved.
                    </small>
                </div>
                <div class="d-flex align-items-center gap-3">
                    <span id="recorder-indicator" class="badge bg-danger align-items-center" style="display:none;">
                        <span class="rec-dot"></span>REC
                    </span>
                    <span id="recorder-timer" class="fw-bold" style="font-variant-numeric: tabular-nums;">0:00</span>
                </div>
            </div>

            <div class="card-body">
                <div id="recorder-status" class="alert alert-light py-2 mb-3">
                    Choose what to record to begin. Nothing is captured until you press Start.
                </div>

                @if(! request()->secure() && ! app()->environment('local'))
                    <div class="alert alert-warning py-2">
                        Screen capture needs a secure (HTTPS) connection — browsers block it otherwise.
                    </div>
                @endif

                <div class="stage mb-3">
                    <canvas id="recorder-canvas" width="1280" height="720"></canvas>
                    <div class="stage__placeholder" id="recorder-placeholder">
                        <i data-feather="monitor" style="width:38px;height:38px;"></i>
                        <div class="fw-semibold">Pick what to record</div>
                        <div style="max-width:360px;font-size:13px;">
                            Choose one of the three options below. Nothing is captured until you
                            press Start, and this preview always shows exactly what gets saved.
                        </div>
                    </div>
                    <div class="countdown" id="recorder-countdown">3</div>
                </div>

                <div class="mode-picker mb-3">
                    <button type="button" id="mode-screen" class="mode-btn">
                        <i data-feather="monitor"></i>
                        <span>Screen only</span>
                        <small>A walkthrough with no camera</small>
                    </button>
                    <button type="button" id="mode-screen-camera" class="mode-btn">
                        <i data-feather="airplay"></i>
                        <span>Screen + webcam</span>
                        <small>You in a corner of the screen</small>
                    </button>
                    <button type="button" id="mode-camera" class="mode-btn">
                        <i data-feather="user"></i>
                        <span>Webcam only</span>
                        <small>Straight-to-camera announcement</small>
                    </button>
                </div>

                <div class="d-flex flex-wrap gap-2 align-items-center">
                    <span class="flex-grow-1"></span>
                    <span id="recorder-uploaded" class="text-muted small me-2"></span>
                    <button type="button" id="btn-start" class="btn btn-primary" disabled>Start recording</button>
                    <button type="button" id="btn-pause" class="btn btn-warning" style="display:none;">Pause</button>
                    <button type="button" id="btn-stop" class="btn btn-success" style="display:none;">Stop &amp; save</button>
                    <button type="button" id="btn-discard" class="btn btn-outline-danger" style="display:none;">Discard</button>
                </div>
            </div>
        </div>
    </div>

    <div class="col-xl-4">
        <div class="card studio-panel">
            <div class="card-header"><h5 class="mb-0">Webcam</h5></div>

            {{-- Shown instead of the corner picker when the webcam IS the frame,
                 so nobody hunts for a corner setting that cannot apply. --}}
            <div class="card-body text-muted" id="webcam-controls-na" style="display:none;">
                <small>
                    The webcam fills the whole frame in this mode, so there is no corner to place it in.
                    Mirroring is on — it only affects what you see, not the saved video.
                </small>
            </div>

            <div class="card-body" id="webcam-controls" style="display:none;">
                <label class="form-label">Corner</label>
                <div class="corner-picker mb-3">
                    @foreach(['top-left' => 'Top left', 'top-right' => 'Top right', 'bottom-left' => 'Bottom left', 'bottom-right' => 'Bottom right'] as $key => $label)
                        <button type="button" id="corner-{{ $key }}"
                                class="{{ $key === 'bottom-right' ? 'active' : '' }}">{{ $label }}</button>
                    @endforeach
                </div>

                <div class="row g-2 mb-2">
                    <div class="col-6">
                        <label class="form-label" for="webcam-size">Size</label>
                        <select id="webcam-size" class="form-select form-select-sm">
                            <option value="small">Small</option>
                            <option value="medium" selected>Medium</option>
                            <option value="large">Large</option>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label" for="webcam-shape">Shape</label>
                        <select id="webcam-shape" class="form-select form-select-sm">
                            <option value="rounded" selected>Rounded</option>
                            <option value="circle">Circle</option>
                        </select>
                    </div>
                </div>

                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="webcam-mirror" checked>
                    <label class="form-check-label" for="webcam-mirror">Mirror my webcam</label>
                </div>
            </div>
        </div>

        <div class="card studio-panel">
            <div class="card-header"><h5 class="mb-0">Audio</h5></div>
            <div class="card-body">
                <div class="form-check mb-2">
                    <input class="form-check-input" type="checkbox" id="include-mic" checked>
                    <label class="form-check-label" for="include-mic">Microphone (narration)</label>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="include-system-audio" checked>
                    <label class="form-check-label" for="include-system-audio">System audio</label>
                </div>
                <small class="text-muted d-block mt-2">
                    System audio is only captured if you tick “Share tab audio” in the browser's own
                    sharing dialog. It is mixed under the narration.
                </small>
            </div>
        </div>

        <div class="card studio-panel">
            <div class="card-header"><h5 class="mb-0">Details</h5></div>
            <div class="card-body">
                <div class="mb-2">
                    <label class="form-label" for="field-title">Title</label>
                    <input type="text" id="field-title" class="form-control" maxlength="200"
                           placeholder="How to share your invite link">
                </div>
                <div class="mb-2">
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
                              placeholder="What this walkthrough covers"></textarea>
                </div>
                <small class="text-muted d-block mt-2">
                    Details can be edited after saving. Recordings save as drafts — nobody sees them
                    until you publish.
                </small>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
    window.RECORDER_CONFIG = {
        csrf: document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
        createUrl: @json(route('admin.screen-recordings.store')),
        chunkBytes: {{ (int) $chunkBytes }},
        maxDuration: {{ (int) $maxDuration }},
        maxBytes: {{ (int) $maxBytes }}
    };
</script>
<script src="{{ \App\Support\Asset::v('assets/js/chunked-uploader.js') }}"></script>
<script src="{{ \App\Support\Asset::v('assets/js/screen-recorder.js') }}"></script>
@endpush
