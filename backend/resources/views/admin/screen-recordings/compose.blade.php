@extends('layouts.admin')

@section('title', 'Combine Recordings')
@section('page-title', 'Combine Recordings')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('admin.screen-recordings.index') }}">Video Library</a></li>
    <li class="breadcrumb-item active">Combine</li>
@endsection

@push('styles')
<style>
    .pick-list { max-height: 460px; overflow-y: auto; }
    .pick-row {
        display: flex; align-items: center; gap: 10px; width: 100%;
        padding: 10px 12px; border: 1px solid var(--q3-border); border-radius: 8px;
        background: var(--q3-surface); margin-bottom: 8px; text-align: left; cursor: pointer;
    }
    .pick-row:hover { border-color: var(--theme-default); }
    .pick-row[disabled] { opacity: .4; cursor: default; }
    .pick-row .thumb {
        width: 72px; height: 41px; border-radius: 4px; object-fit: cover;
        background: var(--q3-black); flex: none;
    }
    .pick-row .grow { flex-grow: 1; min-width: 0; }
    .pick-row .title { font-weight: 600; font-size: 13.5px; color: var(--q3-text); }
    .pick-row .meta  { font-size: 11.5px; color: var(--q3-text-muted); }

    .seq-row {
        display: flex; align-items: center; gap: 10px;
        padding: 10px 12px; border: 1px solid var(--q3-border); border-radius: 8px;
        background: var(--q3-surface-2); margin-bottom: 8px;
    }
    .seq-num {
        width: 26px; height: 26px; border-radius: 50%; flex: none;
        background: var(--theme-default); color: var(--q3-gold-ink);
        display: flex; align-items: center; justify-content: center;
        font-size: 12px; font-weight: 700;
    }
    .seq-empty {
        border: 2px dashed var(--q3-border); border-radius: 8px; padding: 36px 16px;
        text-align: center; color: var(--q3-text-muted);
    }
    .step-badge {
        display: inline-flex; align-items: center; justify-content: center;
        width: 22px; height: 22px; border-radius: 50%;
        background: var(--theme-default); color: var(--q3-gold-ink);
        font-size: 12px; font-weight: 700; margin-right: 8px;
    }
</style>
@endpush

@section('content')

@if(session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif

@if(! $available)
    <div class="alert alert-warning">
        Combining videos needs a tool called ffmpeg, which is not installed on this server yet.
        Ask your developer to install it — everything else on this page will work once it is.
    </div>
@endif

<form method="POST" action="{{ route('admin.screen-recordings.combine.store') }}" id="combine-form">
    @csrf

    @error('clips')<div class="alert alert-danger">{{ $message }}</div>@enderror

    <div class="row g-3">
        {{-- Step 1 --}}
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header">
                    <h5 class="mb-1"><span class="step-badge">1</span>Pick your videos</h5>
                    <small class="text-muted">Click a video to add it. Click it again in the list on the right to remove it.</small>
                </div>
                <div class="card-body pick-list">
                    @forelse($recordings as $recording)
                        <button type="button" class="pick-row" data-pick="{{ $recording->id }}"
                                data-title="{{ e($recording->title) }}"
                                data-meta="{{ $recording->formattedDuration() }} · {{ $recording->created_at->format('M j, Y') }}">
                            @if($recording->thumbnail_path)
                                <img class="thumb" alt="" src="{{ route('recordings.poster', $recording) }}">
                            @else
                                <span class="thumb"></span>
                            @endif
                            <span class="grow">
                                <span class="title d-block text-truncate">{{ $recording->title }}</span>
                                <span class="meta">
                                    {{ $recording->formattedDuration() }} ·
                                    {{ $recording->created_at->format('M j, Y') }}
                                </span>
                            </span>
                            <span class="badge bg-light text-dark add-label">Add</span>
                        </button>
                    @empty
                        <p class="text-muted text-center py-4 mb-0">
                            You have no finished recordings yet.
                            <a href="{{ route('admin.screen-recordings.studio') }}">Record one first.</a>
                        </p>
                    @endforelse
                </div>
            </div>
        </div>

        {{-- Step 2 --}}
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header">
                    <h5 class="mb-1"><span class="step-badge">2</span>Put them in order</h5>
                    <small class="text-muted">This is the order they will play in. Use the arrows to move one up or down.</small>
                </div>
                <div class="card-body">
                    <div id="sequence"></div>
                    <div class="seq-empty" id="sequence-empty">
                        Nothing added yet — pick a video from the left to start.
                    </div>
                    <div class="mt-2 small text-muted" id="sequence-summary"></div>
                </div>
            </div>
        </div>
    </div>

    {{-- Step 3 --}}
    <div class="card mt-3">
        <div class="card-header">
            <h5 class="mb-1"><span class="step-badge">3</span>Name the new video</h5>
            <small class="text-muted">
                This creates a brand new video. The ones you picked are not changed or deleted.
            </small>
        </div>
        <div class="card-body">
            <div class="row g-3 align-items-end">
                <div class="col-md-6">
                    <label class="form-label" for="title">Title</label>
                    <input type="text" name="title" id="title" maxlength="200" required
                           class="form-control @error('title') is-invalid @enderror"
                           value="{{ old('title') }}" placeholder="September team update">
                    @error('title')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="category_id">Training category</label>
                    <select name="category_id" id="category_id" class="form-select">
                        <option value="">Unfiled</option>
                        @foreach(\App\Models\TrainingCategory::orderBy('name')->get() as $category)
                            <option value="{{ $category->id }}" @selected(old('category_id') == $category->id)>
                                {{ $category->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2 d-grid">
                    <button class="btn btn-primary" id="combine-submit" @disabled(! $available)>Combine</button>
                </div>
            </div>

            <p class="text-muted small mb-0 mt-3">
                Building takes a few minutes — roughly a minute for every minute of video. You can leave
                this page; it keeps going on its own and the new video appears in your library.
            </p>
        </div>
    </div>
</form>
@endsection

@push('scripts')
<script>
(function () {
    var sequence = [];
    var listEl    = document.getElementById('sequence');
    var emptyEl   = document.getElementById('sequence-empty');
    var summaryEl = document.getElementById('sequence-summary');
    var form      = document.getElementById('combine-form');
    var maxClips  = {{ (int) $maxClips }};

    document.querySelectorAll('[data-pick]').forEach(function (button) {
        button.addEventListener('click', function () {
            if (sequence.length >= maxClips) {
                summaryEl.textContent = 'That is the most you can combine at once (' + maxClips + ').';
                return;
            }
            // The same recording twice in a row is a legitimate thing to want
            // (an intro bumper, say), so duplicates are allowed.
            sequence.push({
                id: button.getAttribute('data-pick'),
                title: button.getAttribute('data-title'),
                meta: button.getAttribute('data-meta')
            });
            render();
        });
    });

    function move(index, by) {
        var target = index + by;
        if (target < 0 || target >= sequence.length) return;
        var moved = sequence.splice(index, 1)[0];
        sequence.splice(target, 0, moved);
        render();
    }

    function render() {
        listEl.innerHTML = '';

        sequence.forEach(function (clip, index) {
            var row = document.createElement('div');
            row.className = 'seq-row';

            var num = document.createElement('span');
            num.className = 'seq-num';
            num.textContent = index + 1;

            var body = document.createElement('div');
            body.className = 'flex-grow-1 min-width-0';
            body.innerHTML = '<div class="fw-semibold text-truncate" style="font-size:13.5px;"></div>'
                           + '<div class="text-muted" style="font-size:11.5px;"></div>';
            body.children[0].textContent = clip.title;
            body.children[1].textContent = clip.meta;

            row.appendChild(num);
            row.appendChild(body);
            row.appendChild(button('↑', 'Move up',   function () { move(index, -1); }, index === 0));
            row.appendChild(button('↓', 'Move down', function () { move(index, 1); },  index === sequence.length - 1));
            row.appendChild(button('✕', 'Remove',    function () { sequence.splice(index, 1); render(); }, false, true));

            listEl.appendChild(row);
        });

        emptyEl.style.display = sequence.length ? 'none' : '';
        summaryEl.textContent = sequence.length < 2
            ? 'Add at least two videos.'
            : sequence.length + ' videos will play back to back.';

        document.getElementById('combine-submit').disabled =
            sequence.length < 2 || !{{ $available ? 'true' : 'false' }};

        // Rebuild the hidden inputs so the submitted order is exactly this list.
        form.querySelectorAll('input[name="clips[]"]').forEach(function (i) { i.remove(); });
        sequence.forEach(function (clip) {
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'clips[]';
            input.value = clip.id;
            form.appendChild(input);
        });
    }

    function button(label, title, onClick, disabled, danger) {
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'btn btn-sm ' + (danger ? 'btn-outline-danger' : 'btn-outline-secondary');
        b.textContent = label;
        b.title = title;
        b.disabled = !!disabled;
        b.addEventListener('click', onClick);
        return b;
    }

    render();
})();
</script>
@endpush
