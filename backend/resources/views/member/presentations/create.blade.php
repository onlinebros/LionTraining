@extends('layouts.member')

@section('title', 'Schedule a presentation')

@section('content')
<div class="container-fluid">
    <div class="page-title mb-3">
        <h3 class="mb-1">Schedule a presentation</h3>
        <p class="text-muted mb-0">
            Pick a video and a time that suits your team. You get your own link to share, and only
            you see who turns up.
        </p>
    </div>

    @if(session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif

    @if($recordings->isEmpty())
        <div class="card"><div class="card-body text-center py-5">
            <h6 class="mb-2">Nothing available to schedule yet</h6>
            <p class="text-muted mb-0">
                Head office releases videos for members to schedule. Check back, or ask them to
                release one.
            </p>
        </div></div>
    @else
        <form method="POST" action="{{ route('member.presentations.store') }}">
            @csrf
            <div class="row g-3">
                <div class="col-lg-7">
                    <div class="card">
                        <div class="card-header"><h5 class="mb-0">Details</h5></div>
                        <div class="card-body">
                            <div class="mb-3">
                                <label class="form-label">How people watch it</label>
                                @foreach(\App\Models\Presentation::FORMATS as $value => $label)
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="format"
                                               value="{{ $value }}" id="format-{{ $value }}"
                                               @checked(old('format', \App\Models\Presentation::FORMAT_SCHEDULED) === $value)>
                                        <label class="form-check-label" for="format-{{ $value }}">{{ $label }}</label>
                                    </div>
                                @endforeach
                            </div>

                            <div class="mb-3">
                                <label class="form-label" for="recording_id">Video</label>
                                <select name="recording_id" id="recording_id" required
                                        class="form-select @error('recording_id') is-invalid @enderror">
                                    <option value="">Choose a video…</option>
                                    @foreach($recordings as $recording)
                                        <option value="{{ $recording->id }}"
                                            @selected(old('recording_id') == $recording->id)>
                                            {{ $recording->title }} ({{ $recording->formattedDuration() }})
                                        </option>
                                    @endforeach
                                </select>
                                @error('recording_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>

                            <div class="mb-3">
                                <label class="form-label" for="title">What to call it</label>
                                <input type="text" name="title" id="title" required maxlength="160"
                                       class="form-control @error('title') is-invalid @enderror"
                                       value="{{ old('title') }}"
                                       placeholder="Team overview — Thursday night">
                                @error('title')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                <small class="text-muted">Your guests see this on the invitation page.</small>
                            </div>

                            <div class="mb-3" id="when-field">
                                <label class="form-label" for="scheduled_at">
                                    Starts at <span class="badge bg-light text-dark">Eastern</span>
                                </label>
                                <input type="datetime-local" name="scheduled_at" id="scheduled_at" required
                                       class="form-control @error('scheduled_at') is-invalid @enderror"
                                       value="{{ old('scheduled_at') }}">
                                @error('scheduled_at')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                <small class="text-muted d-block" id="tz-preview">
                                    Times are always Eastern, whatever timezone you are in.
                                </small>
                            </div>

                            <div>
                                <label class="form-label" for="description">Anything to add</label>
                                <textarea name="description" id="description" rows="3" class="form-control"
                                          placeholder="Optional. Shown on the invitation page.">{{ old('description') }}</textarea>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-lg-5">
                    <div class="card">
                        <div class="card-header"><h5 class="mb-0">How this works</h5></div>
                        <div class="card-body">
                            <ul class="mb-3 ps-3" style="font-size:14px;line-height:1.7;">
                                <li id="how-watch">Everyone who joins watches together, from the same moment.</li>
                                <li>You get a link that is yours — anyone who registers through it is
                                    your guest, and no other member sees them.</li>
                                <li>You can chat with them while it plays.</li>
                                <li>You will be told when someone joins.</li>
                            </ul>

                            <p class="text-muted small mb-3">
                                You can have {{ $remaining }} more coming up.
                            </p>

                            <div class="d-flex gap-2">
                                <button class="btn btn-primary">Schedule it</button>
                                <a href="{{ route('member.presentations.index') }}"
                                   class="btn btn-outline-secondary">Cancel</a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    @endif
</div>
@endsection

@push('scripts')
<script>
/*
 * Echo the chosen time back, plus what it means where the member is sitting.
 * Somebody scheduling from Phoenix types 7:00 PM and needs to see, then and
 * there, that it is 7:00 PM Eastern and 4:00 PM to them.
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
       it means in Eastern, guess UTC then correct by however far that guess
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
    var how  = document.getElementById('how-watch');

    function applyFormat() {
        var onDemand = document.getElementById('format-on_demand').checked;

        when.style.display = onDemand ? 'none' : '';
        input.required = !onDemand;

        how.textContent = onDemand
            ? 'It starts from the beginning whenever somebody opens your link.'
            : 'Everyone who joins watches together, from the same moment.';
    }

    Array.prototype.forEach.call(
        document.querySelectorAll('input[name="format"]'),
        function (radio) { radio.addEventListener('change', applyFormat); }
    );

    applyFormat();
})();
</script>
@endpush
