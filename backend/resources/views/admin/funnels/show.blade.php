@extends('layouts.admin')
@section('title', $funnel->title)
@section('page-title', $funnel->title)

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('admin.funnels.index') }}">Funnels</a></li>
    <li class="breadcrumb-item active">{{ $funnel->title }}</li>
@endsection

@push('styles')
<style>
    .stat-row { display:grid; grid-template-columns:repeat(auto-fit, minmax(140px,1fr)); gap:10px; margin-bottom:16px; }
    .stat { background:#fff; border:1px solid #e2e8f0; border-radius:10px; padding:13px 15px; }
    .stat__n { font-size:22px; font-weight:700; color:#0f172a; line-height:1.1; font-variant-numeric:tabular-nums; }
    .stat__l { font-size:11.5px; color:#64748b; text-transform:uppercase; letter-spacing:.06em; margin-top:3px; }

    /* A step and the choices hanging off it, so the shape of the flow is
       readable down the page rather than assembled in your head. */
    .step { border:1px solid #e2e8f0; border-radius:12px; margin-bottom:14px; background:#fff; }
    .step__head {
        display:flex; flex-wrap:wrap; gap:12px; align-items:center; justify-content:space-between;
        padding:13px 16px; border-bottom:1px solid #eef2f7;
    }
    .step__n {
        display:inline-flex; align-items:center; justify-content:center;
        width:26px; height:26px; border-radius:50%; margin-right:9px; flex:none;
        background:#f1f5f9; color:#475569; font-size:12px; font-weight:700;
    }
    .step__title { font-weight:600; color:#0f172a; }
    .step__meta { font-size:12px; color:#64748b; }
    .step__body { padding:14px 16px; }

    .cue {
        display:flex; flex-wrap:wrap; gap:10px; align-items:center;
        padding:9px 12px; border:1px solid #eef2f7; border-radius:9px; margin-bottom:8px;
        background:#f8fafc;
    }
    .cue--off { opacity:.55; }
    .cue__at {
        font-variant-numeric:tabular-nums; font-weight:700; color:#0f172a;
        background:#fff; border:1px solid #e2e8f0; border-radius:6px; padding:2px 8px; font-size:12.5px;
    }
    .cue__label { font-weight:600; color:#0f172a; font-size:13.5px; }
    .cue__to { font-size:12px; color:#64748b; }
    .cue__warn { font-size:12px; color:#b45309; }
</style>
@endpush

@section('content')
@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
@if(session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif
@if($errors->any())
    <div class="alert alert-danger">
        <ul class="mb-0 ps-3">
            @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
        </ul>
    </div>
@endif

<div class="stat-row">
    <div class="stat"><div class="stat__n">{{ $funnel->steps->count() }}</div><div class="stat__l">Videos</div></div>
    <div class="stat"><div class="stat__n">{{ $stats['started'] }}</div><div class="stat__l">People started</div></div>
    <div class="stat"><div class="stat__n">{{ $stats['choices'] }}</div><div class="stat__l">Choices made</div></div>
    <div class="stat"><div class="stat__n">{{ $stats['finished'] }}</div><div class="stat__l">Asked for something</div></div>
</div>

<div class="card mb-3">
    <div class="card-body d-flex flex-wrap gap-2 align-items-center justify-content-between">
        <div style="min-width:260px;flex:1;">
            <label class="form-label mb-1" style="font-size:12.5px;">Your link to this flow</label>
            <input type="text" class="form-control form-control-sm" readonly id="share-url"
                   value="{{ $funnel->shareUrlFor(auth()->user()) }}">
            <small class="text-muted">
                Every member gets their own version of this, carrying their code. Whoever's link a
                person comes through keeps them for the whole journey.
            </small>
        </div>
        <div class="d-flex gap-2">
            <button class="btn btn-sm btn-primary" type="button" id="copy-link">Copy</button>
            <a href="{{ route('admin.funnels.edit', $funnel) }}" class="btn btn-sm btn-outline-secondary">Settings</a>
            <a href="{{ route('admin.funnels.prospects', $funnel) }}" class="btn btn-sm btn-outline-secondary">Who went through</a>
        </div>
    </div>
</div>

@if(! $funnel->isReady())
    <div class="alert alert-warning">
        This flow has no first video, so its link does not work yet. Add one below.
    </div>
@endif

<div class="row g-3">
    <div class="col-lg-8">
        @forelse($funnel->steps as $step)
            <div class="step">
                <div class="step__head">
                    <div>
                        <span class="step__n">{{ $loop->iteration }}</span>
                        <span class="step__title">{{ $step->title }}</span>
                        @if($funnel->entry_presentation_id === $step->id)
                            <span class="badge bg-primary ms-1">Starts here</span>
                        @endif
                        <div class="step__meta ms-4 ps-2">
                            {{ $step->formattedDuration() }} ·
                            {{ $step->cues->count() }} {{ Str::plural('choice', $step->cues->count()) }}
                        </div>
                    </div>
                    <div class="d-flex gap-2">
                        @if($funnel->entry_presentation_id !== $step->id)
                            <form method="POST" action="{{ route('admin.funnels.steps.entry', [$funnel, $step]) }}">
                                @csrf @method('PATCH')
                                <button class="btn btn-sm btn-outline-primary">Start here</button>
                            </form>
                        @endif
                        <a href="{{ route('admin.presentations.show', $step) }}"
                           class="btn btn-sm btn-outline-secondary">Open</a>
                        <form method="POST" action="{{ route('admin.funnels.steps.destroy', [$funnel, $step]) }}"
                              onsubmit="return confirm('Take “{{ $step->title }}” out of this flow?');">
                            @csrf @method('DELETE')
                            <button class="btn btn-sm btn-outline-danger">Remove</button>
                        </form>
                    </div>
                </div>

                <div class="step__body">
                    @forelse($step->cues as $cue)
                        <div class="cue {{ $cue->is_active ? '' : 'cue--off' }}">
                            <span class="cue__at">{{ $cue->formattedStart() }}</span>
                            <span class="flex-grow-1" style="min-width:180px;">
                                <span class="cue__label">{{ $cue->buttonLabel() }}</span>
                                <span class="cue__to d-block">
                                    @if($cue->isBranch())
                                        &rarr; {{ $cue->nextPresentation?->title ?? 'video no longer in the flow' }}
                                    @else
                                        &rarr; {{ $cue->ctaItem?->name ?? 'call to action was deleted' }}
                                        @if($cue->ctaItem?->opens_in === \App\Support\PresentationCta::OPENS_SAME)
                                            <span class="badge bg-light text-dark">leaves the video</span>
                                        @endif
                                    @endif
                                    @if($cue->ends_at_seconds)
                                        · gone at {{ \App\Models\PresentationAttendee::clock($cue->ends_at_seconds) }}
                                    @endif
                                </span>
                                @unless($cue->isPlayable())
                                    <span class="cue__warn d-block">Not shown — it has nowhere to go.</span>
                                @endunless
                            </span>
                            <span class="d-flex gap-1">
                                <form method="POST" action="{{ route('admin.funnels.cues.toggle', $cue) }}">
                                    @csrf @method('PATCH')
                                    <button class="btn btn-sm btn-outline-secondary">
                                        {{ $cue->is_active ? 'Hide' : 'Show' }}
                                    </button>
                                </form>
                                <form method="POST" action="{{ route('admin.funnels.cues.destroy', $cue) }}"
                                      onsubmit="return confirm('Remove this choice?');">
                                    @csrf @method('DELETE')
                                    <button class="btn btn-sm btn-outline-danger">&times;</button>
                                </form>
                            </span>
                        </div>
                    @empty
                        <p class="text-muted mb-3" style="font-size:13.5px;">
                            Nothing offered on this video yet. It plays and stops.
                        </p>
                    @endforelse

                    <details>
                        <summary style="cursor:pointer;font-size:13.5px;color:#2563eb;">Add a choice</summary>
                        <form method="POST" action="{{ route('admin.funnels.cues.store', $step) }}"
                              class="row g-2 mt-1">
                            @csrf
                            <div class="col-md-3">
                                <label class="form-label mb-1" style="font-size:12px;">Appears at</label>
                                <input type="text" name="starts_at" class="form-control form-control-sm"
                                       placeholder="4:30" required>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label mb-1" style="font-size:12px;">Gone at (optional)</label>
                                <input type="text" name="ends_at" class="form-control form-control-sm"
                                       placeholder="—">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label mb-1" style="font-size:12px;">Button text (optional)</label>
                                <input type="text" name="label" maxlength="80" class="form-control form-control-sm"
                                       placeholder="Show me the numbers">
                            </div>

                            <div class="col-md-4">
                                <label class="form-label mb-1" style="font-size:12px;">What it does</label>
                                <select name="kind" class="form-select form-select-sm cue-kind">
                                    @foreach(\App\Models\PresentationCue::KINDS as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-5 cue-target cue-target--cta">
                                <label class="form-label mb-1" style="font-size:12px;">Which call to action</label>
                                <select name="cta_item_id" class="form-select form-select-sm">
                                    <option value="">Choose…</option>
                                    @foreach($ctaItems as $item)
                                        <option value="{{ $item->id }}">{{ $item->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-5 cue-target cue-target--branch" hidden>
                                <label class="form-label mb-1" style="font-size:12px;">Which video next</label>
                                <select name="next_presentation_id" class="form-select form-select-sm">
                                    <option value="">Choose…</option>
                                    @foreach($funnel->steps as $other)
                                        @continue($other->id === $step->id)
                                        <option value="{{ $other->id }}">{{ $other->title }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-3 d-flex align-items-end">
                                <button class="btn btn-sm btn-primary w-100">Add</button>
                            </div>
                        </form>
                    </details>
                </div>
            </div>
        @empty
            <div class="card"><div class="card-body text-center py-5 text-muted">
                No videos in this flow yet. Add the one people should see first.
            </div></div>
        @endforelse
    </div>

    <div class="col-lg-4">
        <div class="card mb-3">
            <div class="card-header"><h5 class="mb-0">Add a video</h5></div>
            <div class="card-body">
                @if($recordings->isEmpty())
                    <p class="text-muted mb-0" style="font-size:13.5px;">
                        Nothing in the library is ready. A recording needs a finished file and a known
                        length before choices can be timed on it.
                    </p>
                @else
                    <form method="POST" action="{{ route('admin.funnels.steps.store', $funnel) }}">
                        @csrf
                        <div class="mb-2">
                            <label class="form-label" for="recording_id">Recording</label>
                            <select name="recording_id" id="recording_id" required class="form-select">
                                <option value="">Choose…</option>
                                @foreach($recordings as $recording)
                                    <option value="{{ $recording->id }}">
                                        {{ $recording->title }} ({{ $recording->formattedDuration() }})
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="title">Call it (optional)</label>
                            <input type="text" name="title" id="title" maxlength="160" class="form-control"
                                   placeholder="Uses the recording's own title">
                        </div>
                        <button class="btn btn-primary w-100">Add to flow</button>
                    </form>
                @endif
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h5 class="mb-0">How a flow works</h5></div>
            <div class="card-body">
                <ul class="mb-0 ps-3" style="font-size:13.5px;line-height:1.75;">
                    <li>Each video plays from the start for whoever opens it.</li>
                    <li>A choice appears at the moment you set, and stays until the end unless you
                        give it a time to go.</li>
                    <li>Picking another video keeps them in the same conversation — the member
                        answering them does not lose the thread.</li>
                    <li>Picking a call to action opens it in a new window and ends the journey.</li>
                    <li>Everything they pick is recorded, and they stay attributed to whoever
                        invited them the whole way through.</li>
                </ul>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
(function () {
    var copy = document.getElementById('copy-link');
    var url  = document.getElementById('share-url');

    if (copy && url) {
        copy.addEventListener('click', function () {
            url.select();
            navigator.clipboard.writeText(url.value).then(function () {
                copy.textContent = 'Copied';
                setTimeout(function () { copy.textContent = 'Copy'; }, 1600);
            }).catch(function () {});
        });
    }

    // A choice either plays a video or asks for something; only one target
    // field is ever relevant, and showing both invites filling in the wrong one.
    Array.prototype.forEach.call(document.querySelectorAll('.cue-kind'), function (select) {
        var form = select.closest('form');

        function apply() {
            var branch = select.value === 'branch';
            form.querySelector('.cue-target--branch').hidden = !branch;
            form.querySelector('.cue-target--cta').hidden = branch;
        }

        select.addEventListener('change', apply);
        apply();
    });
})();
</script>
@endpush
