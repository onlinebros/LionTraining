@extends('layouts.admin')

@section('title', $series->exists ? 'Edit Schedule' : 'New Repeating Schedule')
@section('page-title', $series->exists ? 'Edit Schedule' : 'New Repeating Schedule')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('admin.presentations.series.index') }}">Repeating</a></li>
    <li class="breadcrumb-item active">{{ $series->exists ? 'Edit' : 'New' }}</li>
@endsection

@push('styles')
<style>
    .day-picker { display:flex; flex-wrap:wrap; gap:8px; }
    .day-picker label {
        border:1px solid #d7dceb; border-radius:8px; padding:8px 14px;
        cursor:pointer; font-size:14px; user-select:none; background:#fff;
    }
    .day-picker input { display:none; }
    .day-picker input:checked + span { color:var(--theme-default); font-weight:600; }
    .day-picker label:has(input:checked) {
        border-color:var(--theme-default); background:rgba(var(--rgb-primary),.08);
    }
    .time-row { display:flex; gap:8px; align-items:center; margin-bottom:8px; }
</style>
@endpush

@section('content')
@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
@if(session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif

<form method="POST"
      action="{{ $series->exists
          ? route('admin.presentations.series.update', $series)
          : route('admin.presentations.series.store') }}">
    @csrf
    @if($series->exists) @method('PUT') @endif

    <div class="row g-3">
        <div class="col-xl-7">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-1">What plays, and when</h5>
                    <small class="text-muted">
                        Times are Eastern, like everywhere else. Each showing becomes its own event
                        with its own link, guest list and conversations.
                    </small>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label" for="title">Title</label>
                        <input type="text" name="title" id="title" required maxlength="160"
                               class="form-control @error('title') is-invalid @enderror"
                               value="{{ old('title', $series->title) }}"
                               placeholder="Weekly Opportunity Presentation">
                        @error('title')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="recording_id">Recording to play</label>
                        <select name="recording_id" id="recording_id" required
                                class="form-select @error('recording_id') is-invalid @enderror">
                            <option value="">Choose a recording…</option>
                            @foreach($recordings as $recording)
                                <option value="{{ $recording->id }}"
                                    @selected(old('recording_id', $series->recording_id) == $recording->id)>
                                    {{ $recording->title }} ({{ $recording->formattedDuration() }})
                                </option>
                            @endforeach
                        </select>
                        @error('recording_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Days</label>
                        @php $chosenDays = old('days', $series->days ?? []); @endphp
                        <div class="day-picker">
                            @foreach(\App\Models\PresentationSeries::DAY_NAMES as $value => $name)
                                <label>
                                    <input type="checkbox" name="days[]" value="{{ $value }}"
                                        @checked(in_array((string) $value, array_map('strval', (array) $chosenDays), true))>
                                    <span>{{ $name }}</span>
                                </label>
                            @endforeach
                        </div>
                        @error('days')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Times (Eastern)</label>
                        <div id="times">
                            @php $chosenTimes = old('times', $series->times ?: ['19:00']); @endphp
                            @foreach((array) $chosenTimes as $time)
                                <div class="time-row">
                                    <input type="time" name="times[]" class="form-control" required value="{{ $time }}">
                                    <button type="button" class="btn btn-outline-danger btn-sm remove-time">Remove</button>
                                </div>
                            @endforeach
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="add-time">Add another time</button>
                        @error('times')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                        @error('times.*')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                    </div>

                    <div>
                        <label class="form-label" for="description">Description</label>
                        <textarea name="description" id="description" rows="3" class="form-control"
                                  placeholder="Shown on the registration page.">{{ old('description', $series->description) }}</textarea>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-5">
            <div class="card">
                <div class="card-header"><h5 class="mb-0">Settings</h5></div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-6">
                            <label class="form-label" for="starts_on">First date</label>
                            <input type="date" name="starts_on" id="starts_on" class="form-control"
                                   value="{{ old('starts_on', optional($series->starts_on)->format('Y-m-d')) }}">
                            <small class="text-muted">Blank means start now.</small>
                        </div>
                        <div class="col-6">
                            <label class="form-label" for="ends_on">Last date</label>
                            <input type="date" name="ends_on" id="ends_on"
                                   class="form-control @error('ends_on') is-invalid @enderror"
                                   value="{{ old('ends_on', optional($series->ends_on)->format('Y-m-d')) }}">
                            <small class="text-muted">Blank means keep going.</small>
                            @error('ends_on')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="col-12">
                            <label class="form-label" for="weeks_ahead">Put showings on the calendar</label>
                            <select name="weeks_ahead" id="weeks_ahead" class="form-select">
                                @foreach([2, 4, 8, 12] as $weeks)
                                    <option value="{{ $weeks }}" @selected(old('weeks_ahead', $series->weeks_ahead) == $weeks)>
                                        {{ $weeks }} weeks ahead
                                    </option>
                                @endforeach
                            </select>
                            <small class="text-muted">
                                How far out members can share a link. New ones are added daily.
                            </small>
                        </div>

                        <div class="col-12">
                            <label class="form-label" for="replay_visibility">Replay afterwards</label>
                            <select name="replay_visibility" id="replay_visibility" class="form-select">
                                @foreach(\App\Models\Presentation::REPLAY_OPTIONS as $value => $label)
                                    <option value="{{ $value }}"
                                        @selected(old('replay_visibility', $series->replay_visibility) === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="col-12">
                            <input type="hidden" name="is_active" value="0">
                            <div class="form-check form-switch mb-2">
                                <input class="form-check-input" type="checkbox" name="is_active" value="1" id="is_active"
                                       {{ old('is_active', $series->exists ? $series->is_active : true) ? 'checked' : '' }}>
                                <label class="form-check-label" for="is_active">Running</label>
                            </div>
                            <input type="hidden" name="collect_phone" value="0">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="collect_phone" value="1" id="collect_phone"
                                       {{ old('collect_phone', $series->collect_phone) ? 'checked' : '' }}>
                                <label class="form-check-label" for="collect_phone">Also ask guests for a phone number</label>
                            </div>
                        </div>
                    </div>

                    <div class="mt-4 d-flex gap-2">
                        <button class="btn btn-primary">{{ $series->exists ? 'Save schedule' : 'Create schedule' }}</button>
                        <a href="{{ route('admin.presentations.series.index') }}" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </div>
            </div>

            @if($series->exists && isset($upcoming))
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-1">On the calendar</h5>
                        <small class="text-muted">
                            Already created, each with its own link. Cancel one from the
                            presentations list and it will not come back.
                        </small>
                    </div>
                    <div class="card-body">
                        @forelse($upcoming as $showing)
                            <div class="d-flex justify-content-between align-items-center py-1">
                                <a href="{{ route('admin.presentations.show', $showing) }}" style="font-size:13.5px;">
                                    @include('partials.presentation-time', ['presentation' => $showing])
                                </a>
                                <small class="text-muted">{{ $showing->attendees()->count() }} registered</small>
                            </div>
                        @empty
                            <p class="text-muted mb-0">Nothing scheduled yet — save to fill the calendar.</p>
                        @endforelse
                    </div>
                </div>

                <form method="POST" action="{{ route('admin.presentations.series.destroy', $series) }}"
                      onsubmit="return confirm('Stop this schedule? Showings already on the calendar stay.');">
                    @csrf @method('DELETE')
                    <button class="btn btn-outline-danger w-100">Stop this schedule</button>
                </form>
            @endif
        </div>
    </div>
</form>
@endsection

@push('scripts')
<script>
(function () {
    var list = document.getElementById('times');

    document.getElementById('add-time').addEventListener('click', function () {
        var row = document.createElement('div');
        row.className = 'time-row';
        row.innerHTML = '<input type="time" name="times[]" class="form-control" required value="19:00">'
                      + '<button type="button" class="btn btn-outline-danger btn-sm remove-time">Remove</button>';
        list.appendChild(row);
    });

    // Delegated, so it also covers rows added after load. The last row stays:
    // a schedule with no times would silently generate nothing.
    list.addEventListener('click', function (e) {
        if (!e.target.classList.contains('remove-time')) return;
        if (list.querySelectorAll('.time-row').length <= 1) return;
        e.target.closest('.time-row').remove();
    });
})();
</script>
@endpush
