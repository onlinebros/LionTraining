@extends('layouts.admin')

@section('title', $presentation->exists ? 'Edit Presentation' : 'Schedule a Presentation')
@section('page-title', $presentation->exists ? 'Edit Presentation' : 'Schedule a Presentation')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('admin.presentations.index') }}">Presentations</a></li>
    <li class="breadcrumb-item active">{{ $presentation->exists ? 'Edit' : 'New' }}</li>
@endsection

@push('scripts')
<script>
/*
 * Echo the typed time back in words, plus what it means where the admin is
 * sitting. Someone scheduling from Phoenix types 7:00 PM and needs to see, in
 * the moment, that it is 7:00 PM in New York and 4:00 PM to them — otherwise
 * the mistake only surfaces when nobody turns up.
 */
(function () {
    var input   = document.getElementById('scheduled_at');
    var preview = document.getElementById('tz-preview');
    var ZONE    = @json(\App\Models\Presentation::bookingTimezone());
    if (!input || !preview || typeof Intl === 'undefined') return;

    var fallback = preview.textContent;

    function parts(date, zone) {
        return new Intl.DateTimeFormat('en-US', {
            timeZone: zone, weekday: 'short', day: 'numeric', month: 'short',
            hour: 'numeric', minute: '2-digit', timeZoneName: 'short'
        }).format(date);
    }

    /* A datetime-local value is a wall clock with no zone. To find the instant
       it represents in Eastern, guess UTC and correct by however far that guess
       lands from the intended clock. */
    function asEasternInstant(value) {
        var guess = new Date(value + 'Z');
        if (isNaN(guess.getTime())) return null;

        var seen = new Date(guess.toLocaleString('en-US', { timeZone: ZONE }));
        var utc  = new Date(guess.toLocaleString('en-US', { timeZone: 'UTC' }));

        return new Date(guess.getTime() + (utc.getTime() - seen.getTime()));
    }

    function render() {
        if (!input.value) { preview.textContent = fallback; return; }

        var instant = asEasternInstant(input.value);
        if (!instant) { preview.textContent = fallback; return; }

        var viewerZone = Intl.DateTimeFormat().resolvedOptions().timeZone;
        var text = parts(instant, ZONE);

        if (parts(instant, viewerZone) !== text) {
            text += ' — ' + parts(instant, viewerZone) + ' where you are';
        }

        preview.textContent = text;
    }

    input.addEventListener('input', render);
    input.addEventListener('change', render);
    render();

    /* An always-open share has no start time, so stop asking for one. */
    var when = document.getElementById('when-field');
    var onDemandRadio = document.getElementById('format-on_demand');

    function applyFormat() {
        var onDemand = onDemandRadio && onDemandRadio.checked;
        if (when) when.style.display = onDemand ? 'none' : '';
        input.required = !onDemand;
    }

    Array.prototype.forEach.call(
        document.querySelectorAll('input[name="format"]'),
        function (radio) { radio.addEventListener('change', applyFormat); }
    );

    applyFormat();
})();
</script>
@endpush

@section('content')
<div class="card">
    <div class="card-header">
        <h5 class="mb-1">Details</h5>
        <small class="text-muted">
            Pick a finished recording and a time. Members get their own invitation link
            automatically — there is nothing to hand out.
        </small>
    </div>
    <div class="card-body">
        <form method="POST"
              action="{{ $presentation->exists
                  ? route('admin.presentations.update', $presentation)
                  : route('admin.presentations.store') }}">
            @csrf
            @if($presentation->exists) @method('PUT') @endif

            <div class="row g-3">
                <div class="col-md-8">
                    <label class="form-label" for="title">Title</label>
                    <input type="text" name="title" id="title" required maxlength="160"
                           class="form-control @error('title') is-invalid @enderror"
                           value="{{ old('title', $presentation->title) }}"
                           placeholder="Tuesday Opportunity Presentation">
                    @error('title')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="col-md-4" id="when-field">
                    <label class="form-label" for="scheduled_at">
                        Starts at <span class="badge bg-light text-dark">Eastern</span>
                    </label>
                    <input type="datetime-local" name="scheduled_at" id="scheduled_at" required
                           class="form-control @error('scheduled_at') is-invalid @enderror"
                           value="{{ old('scheduled_at', $presentation->scheduled_at ? $presentation->bookingInputValue() : '') }}">
                    @error('scheduled_at')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    <small class="text-muted d-block" id="tz-preview">
                        Times are always Eastern, whatever timezone you are in.
                    </small>
                </div>

                <div class="col-12">
                    <label class="form-label">How people watch it</label>
                    <div class="d-flex flex-wrap gap-3">
                        @foreach(\App\Models\Presentation::FORMATS as $value => $label)
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="format"
                                       value="{{ $value }}" id="format-{{ $value }}"
                                       @checked(old('format', $presentation->format ?: \App\Models\Presentation::FORMAT_SCHEDULED) === $value)>
                                <label class="form-check-label" for="format-{{ $value }}">{{ $label }}</label>
                            </div>
                        @endforeach
                    </div>
                    @error('format')<div class="text-danger small">{{ $message }}</div>@enderror
                </div>

                <div class="col-12">
                    <label class="form-label" for="recording_id">Recording to play</label>
                    <select name="recording_id" id="recording_id" required
                            class="form-select @error('recording_id') is-invalid @enderror">
                        <option value="">Choose a recording…</option>
                        @foreach($recordings as $recording)
                            <option value="{{ $recording->id }}"
                                @selected(old('recording_id', $presentation->recording_id) == $recording->id)>
                                {{ $recording->title }} ({{ $recording->formattedDuration() }})
                            </option>
                        @endforeach
                    </select>
                    @error('recording_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    <small class="text-muted">
                        Only finished recordings appear here. The length is copied when you save, so
                        trimming the recording later will not move this showing's end time.
                    </small>
                </div>

                <div class="col-12">
                    <label class="form-label" for="description">Description</label>
                    <textarea name="description" id="description" rows="3" class="form-control"
                              placeholder="What guests will see. Shown on the registration page.">{{ old('description', $presentation->description) }}</textarea>
                </div>

                <div class="col-md-6">
                    <label class="form-label" for="replay_visibility">Replay afterwards</label>
                    <select name="replay_visibility" id="replay_visibility" class="form-select">
                        @foreach(\App\Models\Presentation::REPLAY_OPTIONS as $value => $label)
                            <option value="{{ $value }}"
                                @selected(old('replay_visibility', $presentation->replay_visibility) === $value)>
                                {{ $label }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="col-12"><hr class="my-2"></div>

                <div class="col-12">
                    <h6 class="mb-1">What the button at the end asks for</h6>
                    <small class="text-muted d-block mb-2">
                        Whichever you pick, the link carries the inviting partner's code, so the
                        credit lands with them.
                    </small>
                </div>

                <div class="col-md-4">
                    <label class="form-label" for="cta_type">Call to action</label>
                    <select name="cta_type" id="cta_type" class="form-select">
                        @foreach(\App\Support\PresentationCta::TYPES as $value => $label)
                            <option value="{{ $value }}"
                                @selected(old('cta_type', $presentation->cta_type ?: config('presentations.default_cta')) === $value)>
                                {{ $label }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="col-md-4">
                    <label class="form-label" for="cta_opens_in">When they click it</label>
                    <select name="cta_opens_in" id="cta_opens_in" class="form-select">
                        @foreach(\App\Support\PresentationCta::OPENS as $value => $label)
                            <option value="{{ $value }}"
                                @selected(old('cta_opens_in', $presentation->cta_opens_in ?: \App\Support\PresentationCta::OPENS_NEW) === $value)>
                                {{ $label }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="col-md-4">
                    <label class="form-label" for="cta_label">Button text</label>
                    <input type="text" name="cta_label" id="cta_label" maxlength="60" class="form-control"
                           value="{{ old('cta_label', $presentation->cta_label) }}"
                           placeholder="See how to get started">
                    <small class="text-muted">Blank uses the wording for that type.</small>
                </div>

                <div class="col-md-4">
                    <label class="form-label" for="cta_url">Your own link</label>
                    <input type="url" name="cta_url" id="cta_url" maxlength="500"
                           class="form-control @error('cta_url') is-invalid @enderror"
                           value="{{ old('cta_url', $presentation->cta_url) }}"
                           placeholder="https://…">
                    @error('cta_url')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    <small class="text-muted">For “book a call” and “somewhere else”.</small>
                </div>

                <div class="col-md-6">
                    <label class="form-label" for="cta_headline">Headline above the button</label>
                    <input type="text" name="cta_headline" id="cta_headline" maxlength="160" class="form-control"
                           value="{{ old('cta_headline', $presentation->cta_headline) }}"
                           placeholder="Ready to take the next step?">
                </div>

                <div class="col-md-6">
                    <label class="form-label" for="cta_note">Small print</label>
                    <input type="text" name="cta_note" id="cta_note" maxlength="300" class="form-control"
                           value="{{ old('cta_note', $presentation->cta_note) }}"
                           placeholder="Opens in a new window, so you keep your place here.">
                </div>

                <div class="col-md-6 d-flex align-items-end">
                    <div>
                        <div class="form-check form-switch mb-2">
                            <input class="form-check-input" type="checkbox" name="is_open" value="1" id="is_open"
                                   {{ old('is_open', $presentation->exists ? $presentation->is_open : true) ? 'checked' : '' }}>
                            <label class="form-check-label" for="is_open">Accepting registrations</label>
                        </div>
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="collect_phone" value="1" id="collect_phone"
                                   {{ old('collect_phone', $presentation->collect_phone) ? 'checked' : '' }}>
                            <label class="form-check-label" for="collect_phone">Also ask for a phone number</label>
                        </div>
                    </div>
                </div>
            </div>

            <div class="mt-4 d-flex gap-2">
                <button class="btn btn-primary">{{ $presentation->exists ? 'Save changes' : 'Schedule it' }}</button>
                <a href="{{ route('admin.presentations.index') }}" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>
@endsection
