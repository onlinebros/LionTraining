@extends('layouts.admin')

@section('title', $recording->title)
@section('page-title', 'Manage Recording')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('admin.screen-recordings.index') }}">Video Library</a></li>
    <li class="breadcrumb-item active">{{ Str::limit($recording->title, 40) }}</li>
@endsection

@push('styles')
<style>
    .player-shell { background:var(--q3-black); border-radius:10px; overflow:hidden; }
    .player-shell video { width:100%; display:block; max-height:70vh; background:var(--q3-black); }
    .meta-list dt { font-weight:500; color:var(--q3-text-muted); font-size:12px; }
    .meta-list dd { margin-bottom:10px; }
    .copy-field { font-family: monospace; font-size:12px; }
</style>
@endpush

@section('content')

@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
@if(session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif

@if($recording->isRendering())
    <div class="alert alert-info d-flex align-items-center gap-2" id="render-running">
        <div class="spinner-border spinner-border-sm" role="status"></div>
        <div>
            <strong>Building your combined video…</strong>
            This takes roughly a minute for every minute of video. You can leave this page —
            it keeps going on its own. This page refreshes itself.
        </div>
    </div>
@elseif($recording->isComposition() && $recording->upload_error)
    <div class="alert alert-danger d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div><strong>The last build failed.</strong> {{ $recording->upload_error }}</div>
        <form method="POST" action="{{ route('admin.screen-recordings.rebuild', $recording) }}">
            @csrf
            <button class="btn btn-sm btn-danger">Try again</button>
        </form>
    </div>
@endif

@if($recording->status === \App\Models\ScreenRecording::STATUS_UPLOADING)
    <div class="alert alert-info d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            This recording never finished uploading — the studio tab was probably closed mid-capture.
            Whatever arrived is still buffered on the server; it is swept automatically after
            {{ config('screen-recordings.stale_upload_hours') }} hours.
        </div>
        <form method="POST" action="{{ route('admin.screen-recordings.retry-store', $recording) }}">
            @csrf
            <button class="btn btn-sm btn-info">Store what arrived</button>
        </form>
    </div>
@elseif($recording->status === \App\Models\ScreenRecording::STATUS_FAILED)
    <div class="alert alert-danger d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <strong>Storage failed.</strong> {{ $recording->upload_error }}
            The captured file is still on the server — retry once the storage problem is fixed.
        </div>
        <form method="POST" action="{{ route('admin.screen-recordings.retry-store', $recording) }}">
            @csrf
            <button class="btn btn-sm btn-danger">Retry storing</button>
        </form>
    </div>
@endif

<div class="row g-3">
    <div class="col-xl-8">
        <div class="card">
            <div class="card-body">
                @if($recording->isReady())
                    <div class="player-shell mb-3">
                        <video controls preload="metadata"
                               @if($recording->thumbnail_path) poster="{{ route('recordings.poster', $recording) }}" @endif
                               src="{{ $playback }}">
                            Your browser cannot play this video.
                        </video>
                    </div>
                @else
                    <div class="player-shell d-flex align-items-center justify-content-center text-center p-5 mb-3"
                         style="color:#9aa4c4;">
                        No playable file yet.
                    </div>
                @endif

                <div class="d-flex flex-wrap gap-2 align-items-center">
                    <form method="POST" action="{{ route('admin.screen-recordings.publish', $recording) }}">
                        @csrf
                        <input type="hidden" name="publish" value="{{ $recording->is_published ? 0 : 1 }}">
                        <button class="btn btn-{{ $recording->is_published ? 'outline-secondary' : 'success' }}"
                                @disabled(! $recording->isReady())>
                            {{ $recording->is_published ? 'Unpublish' : 'Publish' }}
                        </button>
                    </form>

                    <a href="{{ route('recordings.watch', $recording) }}" target="_blank"
                       class="btn btn-outline-primary">Open watch page</a>

                    <span class="flex-grow-1"></span>

                    <form method="POST" action="{{ route('admin.screen-recordings.destroy', $recording) }}"
                          onsubmit="return confirm('Delete this recording and its video file? This cannot be undone.');">
                        @csrf @method('DELETE')
                        <button class="btn btn-outline-danger">Delete permanently</button>
                    </form>
                </div>
            </div>
        </div>

        {{-- What this was built from --}}
        @if($recording->isComposition())
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <h5 class="mb-1">Built from these videos</h5>
                    <small class="text-muted">
                        They play in this order. The originals are untouched — editing or deleting
                        one of them does not change this video unless you rebuild.
                    </small>
                </div>
                @unless($recording->isRendering())
                    <form method="POST" action="{{ route('admin.screen-recordings.rebuild', $recording) }}">
                        @csrf
                        <button class="btn btn-sm btn-outline-secondary">Rebuild</button>
                    </form>
                @endunless
            </div>
            <div class="card-body">
                <ol class="mb-0 ps-3">
                    @foreach($recording->clips as $clip)
                        <li class="mb-1">
                            @if($clip->source)
                                <a href="{{ route('admin.screen-recordings.show', $clip->source) }}">{{ $clip->label() }}</a>
                                <small class="text-muted">({{ $clip->source->formattedDuration() }})</small>
                            @else
                                <span class="text-muted">{{ $clip->label() }}</span>
                                <span class="badge bg-warning text-dark">no longer in the library</span>
                            @endif
                        </li>
                    @endforeach
                </ol>
            </div>
        </div>
        @endif

        {{-- Trim --}}
        @if($recording->isReady())
        <div class="card">
            <div class="card-header">
                <h5 class="mb-1">Trim</h5>
                <small class="text-muted">
                    Cut the dead air off the start and end. The original is kept, so you can widen
                    the cut again or put it back at any time.
                </small>
            </div>
            <div class="card-body">
                @if($recording->isTrimming())
                    <div class="alert alert-info d-flex align-items-center gap-2 mb-0" id="trim-running">
                        <div class="spinner-border spinner-border-sm" role="status"></div>
                        <div>
                            <strong>Trimming…</strong>
                            The recording keeps playing at its current length until the new version is ready.
                            This page refreshes itself.
                        </div>
                    </div>
                @else
                    @if($recording->trimFailed())
                        <div class="alert alert-danger">
                            <strong>The last trim failed.</strong> {{ $recording->trim_error }}
                        </div>
                    @endif

                    <form method="POST" action="{{ route('admin.screen-recordings.trim', $recording) }}">
                        @csrf
                        <div class="row g-3 align-items-end">
                            <div class="col-sm-4">
                                <label class="form-label" for="trim_start">Start at (seconds)</label>
                                <div class="input-group">
                                    <input type="number" step="0.1" min="0" name="trim_start" id="trim_start"
                                           class="form-control @error('trim_start') is-invalid @enderror"
                                           value="{{ old('trim_start', 0) }}">
                                    <button class="btn btn-outline-secondary" type="button" data-playhead="trim_start">
                                        Use playhead
                                    </button>
                                </div>
                                @error('trim_start')<div class="text-danger small">{{ $message }}</div>@enderror
                            </div>

                            <div class="col-sm-4">
                                <label class="form-label" for="trim_end">End at (seconds)</label>
                                <div class="input-group">
                                    <input type="number" step="0.1" min="0" name="trim_end" id="trim_end"
                                           class="form-control @error('trim_end') is-invalid @enderror"
                                           value="{{ old('trim_end', $recording->original_duration_seconds ?: $recording->duration_seconds) }}">
                                    <button class="btn btn-outline-secondary" type="button" data-playhead="trim_end">
                                        Use playhead
                                    </button>
                                </div>
                                @error('trim_end')<div class="text-danger small">{{ $message }}</div>@enderror
                            </div>

                            <div class="col-sm-4 d-flex gap-2">
                                <button class="btn btn-primary flex-grow-1">Trim</button>
                                @if($recording->hasOriginal())
                                    <button class="btn btn-outline-secondary" type="submit"
                                            formaction="{{ route('admin.screen-recordings.trim.revert', $recording) }}"
                                            formnovalidate
                                            onclick="return confirm('Restore the original, untrimmed recording?');">
                                        Revert
                                    </button>
                                @endif
                            </div>
                        </div>

                        <div class="mt-2 small text-muted" id="trim-summary"></div>

                        @if($recording->hasOriginal())
                            <div class="mt-2 small">
                                <span class="badge bg-light text-dark">Trimmed</span>
                                Currently showing {{ $recording->trim_start }}s–{{ $recording->trim_end }}s of a
                                {{ $recording->original_duration_seconds }}s original. New trims re-cut the original,
                                so you are never cutting a cut.
                            </div>
                        @endif
                    </form>
                @endif
            </div>
        </div>
        @endif

        {{-- Details --}}
        <div class="card">
            <div class="card-header"><h5 class="mb-0">Details &amp; access</h5></div>
            <div class="card-body">
                <form method="POST" action="{{ route('admin.screen-recordings.update', $recording) }}">
                    @csrf @method('PUT')

                    <div class="mb-3">
                        <label class="form-label" for="title">Title</label>
                        <input type="text" name="title" id="title" maxlength="200" required
                               class="form-control @error('title') is-invalid @enderror"
                               value="{{ old('title', $recording->title) }}">
                        @error('title')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="description">Description</label>
                        <textarea name="description" id="description" rows="3"
                                  class="form-control">{{ old('description', $recording->description) }}</textarea>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label" for="category_id">Training category</label>
                            <select name="category_id" id="category_id" class="form-select">
                                <option value="">Unfiled</option>
                                @foreach($categories as $category)
                                    <option value="{{ $category->id }}"
                                        @selected(old('category_id', $recording->category_id) == $category->id)>
                                        {{ $category->name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label" for="visibility">Who can watch</label>
                            <select name="visibility" id="visibility" class="form-select">
                                @foreach(\App\Models\ScreenRecording::VISIBILITIES as $value => $label)
                                    <option value="{{ $value }}"
                                        @selected(old('visibility', $recording->visibility) === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label" for="required_role_id">Role level</label>
                            <select name="required_role_id" id="required_role_id"
                                    class="form-select @error('required_role_id') is-invalid @enderror">
                                <option value="">—</option>
                                @foreach($roles as $role)
                                    <option value="{{ $role->id }}"
                                        @selected(old('required_role_id', $recording->required_role_id) == $role->id)>
                                        {{ $role->display_name }}
                                    </option>
                                @endforeach
                            </select>
                            @error('required_role_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            <small class="text-muted">Only used with “Members at a role level”.</small>
                        </div>
                    </div>

                    <div class="form-check form-switch mt-3">
                        <input type="hidden" name="member_schedulable" value="0">
                        <input class="form-check-input" type="checkbox" name="member_schedulable" value="1"
                               id="member_schedulable" {{ $recording->member_schedulable ? 'checked' : '' }}>
                        <label class="form-check-label" for="member_schedulable">
                            Let members schedule this themselves
                        </label>
                        <small class="text-muted d-block">
                            Members can put this on at a time that suits their own team and get their
                            own link for it. Leave off for anything internal or unfinished.
                        </small>
                    </div>

                    <div class="mt-3">
                        <button class="btn btn-primary">Save changes</button>
                    </div>
                </form>
            </div>
        </div>

        {{-- Use it --}}
        <div class="card">
            <div class="card-header">
                <h5 class="mb-1">Used in presentations</h5>
                <small class="text-muted">Showings that play this recording. It cannot be deleted while any exist.</small>
            </div>
            <div class="card-body">
                @forelse($recording->presentations as $showing)
                    <div class="d-flex justify-content-between align-items-center py-1">
                        <a href="{{ route('admin.presentations.show', $showing) }}">{{ $showing->title }}</a>
                        <small class="text-muted">{{ ucfirst($showing->status) }}</small>
                    </div>
                @empty
                    <span class="text-muted">Not used yet.</span>
                    <a href="{{ route('admin.presentations.create') }}" class="ms-1">Schedule a presentation</a>
                @endforelse
            </div>
        </div>
    </div>

    <div class="col-xl-4">
        <div class="card">
            <div class="card-header"><h5 class="mb-0">Sharing</h5></div>
            <div class="card-body">
                <label class="form-label" for="share-link">Watch link</label>
                <div class="input-group input-group-sm mb-2">
                    <input type="text" class="form-control copy-field" id="share-link" readonly
                           value="{{ route('recordings.watch', $recording) }}">
                    <button class="btn btn-outline-secondary" type="button" data-copy="share-link">Copy</button>
                </div>

                <label class="form-label" for="embed-code">Embed code</label>
                <div class="input-group input-group-sm">
                    <input type="text" class="form-control copy-field" id="embed-code" readonly
                           value='<video src="{{ route('recordings.stream', $recording) }}" controls width="720"></video>'>
                    <button class="btn btn-outline-secondary" type="button" data-copy="embed-code">Copy</button>
                </div>

                <small class="text-muted d-block mt-2">
                    @if($recording->visibility === \App\Models\ScreenRecording::VISIBILITY_LINK && $recording->is_published)
                        Anyone with this link can watch, signed in or not.
                    @elseif(! $recording->is_published)
                        This is a draft — only you and other admins can open the link.
                    @else
                        The link still checks who is asking: {{ strtolower($recording->visibilityLabel()) }}.
                    @endif
                </small>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h5 class="mb-0">Recording</h5></div>
            <div class="card-body">
                <dl class="meta-list mb-0">
                    <dt>Capture</dt><dd>{{ $recording->sourceLabel() }}</dd>
                    <dt>Audio</dt>
                    <dd>
                        @php
                            $audio = array_filter([
                                $recording->has_mic_audio ? 'microphone' : null,
                                $recording->has_system_audio ? 'system audio' : null,
                            ]);
                        @endphp
                        {{ $audio ? ucfirst(implode(' + ', $audio)) : 'Silent' }}
                    </dd>
                    <dt>Length</dt><dd>{{ $recording->formattedDuration() }}</dd>
                    <dt>Resolution</dt>
                    <dd>{{ $recording->width ? $recording->width.' × '.$recording->height : '—' }}</dd>
                    <dt>File</dt>
                    <dd>{{ $recording->formattedSize() }} · {{ $recording->mime ?? '—' }}</dd>
                    <dt>Recorded by</dt>
                    <dd>{{ $recording->author?->name ?? 'Unknown' }} on {{ $recording->created_at->format('M j, Y g:ia') }}</dd>
                    <dt>Views</dt>
                    <dd>
                        {{ $recording->view_count }}
                        @if($recording->last_viewed_at)
                            <span class="text-muted">· last {{ $recording->last_viewed_at->diffForHumans() }}</span>
                        @endif
                    </dd>
                    <dt>Stored at</dt>
                    <dd class="copy-field text-break">{{ $recording->disk }}:{{ $recording->path ?? '—' }}</dd>
                </dl>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
    /* Trim controls: read the point the admin has actually scrubbed to, rather
       than making them read a timecode off the player and type it in. */
    (function () {
        var video = document.querySelector('.player-shell video');
        var start = document.getElementById('trim_start');
        var end   = document.getElementById('trim_end');
        var summary = document.getElementById('trim-summary');

        document.querySelectorAll('[data-playhead]').forEach(function (button) {
            button.addEventListener('click', function () {
                if (!video) return;
                document.getElementById(button.getAttribute('data-playhead')).value =
                    Math.max(0, Math.round(video.currentTime * 10) / 10);
                describe();
            });
        });

        function describe() {
            if (!summary || !start || !end) return;
            var kept = parseFloat(end.value) - parseFloat(start.value);
            summary.textContent = kept > 0
                ? 'Keeping ' + kept.toFixed(1) + ' seconds.'
                : 'The end must come after the start.';
        }

        if (start && end) {
            start.addEventListener('input', describe);
            end.addEventListener('input', describe);
            describe();
        }

        // While a trim or a build runs there is nothing to poll but the row
        // itself, and a plain reload is cheaper than an endpoint that exists
        // only for this.
        if (document.getElementById('trim-running') || document.getElementById('render-running')) {
            setTimeout(function () { window.location.reload(); }, 8000);
        }
    })();

    document.querySelectorAll('[data-copy]').forEach(function (button) {
        button.addEventListener('click', function () {
            var field = document.getElementById(button.getAttribute('data-copy'));
            field.select();
            field.setSelectionRange(0, field.value.length);
            navigator.clipboard.writeText(field.value).then(function () {
                var original = button.textContent;
                button.textContent = 'Copied';
                setTimeout(function () { button.textContent = original; }, 1400);
            });
        });
    });
</script>
@endpush
