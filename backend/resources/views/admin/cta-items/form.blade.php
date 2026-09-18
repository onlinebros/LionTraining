@extends('layouts.admin')
@section('title', $item->exists ? 'Edit call to action' : 'New call to action')
@section('page-title', $item->exists ? $item->name : 'New call to action')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('admin.cta-items.index') }}">Calls to action</a></li>
    <li class="breadcrumb-item active">{{ $item->exists ? 'Edit' : 'New' }}</li>
@endsection

@section('content')
<form method="POST"
      action="{{ $item->exists ? route('admin.cta-items.update', $item) : route('admin.cta-items.store') }}">
    @csrf
    @if($item->exists) @method('PUT') @endif

    <div class="row g-3">
        <div class="col-lg-7">
            <div class="card">
                <div class="card-header"><h5 class="mb-0">What it asks for</h5></div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label" for="name">Name it</label>
                        <input type="text" name="name" id="name" required maxlength="120"
                               class="form-control @error('name') is-invalid @enderror"
                               value="{{ old('name', $item->name) }}"
                               placeholder="Book a PRMI call">
                        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <small class="text-muted">
                            For you, in this list. The guest never sees it.
                        </small>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="kind">Where it sends them</label>
                        <select name="kind" id="kind" class="form-select @error('kind') is-invalid @enderror">
                            @foreach($types as $value => $label)
                                <option value="{{ $value }}" @selected(old('kind', $item->kind) === $value)>
                                    {{ $label }}
                                </option>
                            @endforeach
                        </select>
                        @error('kind')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <small class="text-muted d-block mt-1" id="kind-hint"></small>
                    </div>

                    <div class="mb-3" id="url-field">
                        <label class="form-label" for="url">Link</label>
                        <input type="url" name="url" id="url" maxlength="500"
                               class="form-control @error('url') is-invalid @enderror"
                               value="{{ old('url', $item->url) }}"
                               placeholder="https://…">
                        @error('url')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="mb-3">
                        <label class="form-label">When they click it</label>
                        @foreach(\App\Support\PresentationCta::OPENS as $value => $label)
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="opens_in"
                                       value="{{ $value }}" id="opens-{{ $value }}"
                                       @checked(old('opens_in', $item->opens_in ?: \App\Support\PresentationCta::OPENS_NEW) === $value)>
                                <label class="form-check-label" for="opens-{{ $value }}">{{ $label }}</label>
                            </div>
                        @endforeach
                        @error('opens_in')<div class="text-danger small">{{ $message }}</div>@enderror
                        <small class="text-muted d-block mt-1">
                            A new window suits something they fill in and come back from — they keep
                            their place and the member is still there in the chat. Going to the page
                            suits a flow done in steps, where there is nothing to come back to.
                        </small>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="label">Button text</label>
                        <input type="text" name="label" id="label" maxlength="60" class="form-control"
                               value="{{ old('label', $item->label) }}"
                               placeholder="Book my call">
                        <small class="text-muted">Leave blank to use the standard wording.</small>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="headline">Headline above it</label>
                        <input type="text" name="headline" id="headline" maxlength="160" class="form-control"
                               value="{{ old('headline', $item->headline) }}"
                               placeholder="Ready to talk it through with someone?">
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="note">Small print</label>
                        <input type="text" name="note" id="note" maxlength="300" class="form-control"
                               value="{{ old('note', $item->note) }}"
                               placeholder="Opens in a new window, so you keep your place here.">
                    </div>

                    <div class="form-check">
                        <input type="hidden" name="is_active" value="0">
                        <input class="form-check-input" type="checkbox" name="is_active" value="1"
                               id="is_active" @checked(old('is_active', $item->exists ? $item->is_active : true))>
                        <label class="form-check-label" for="is_active">
                            Available to place on videos
                        </label>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card">
                <div class="card-header"><h5 class="mb-0">How it behaves</h5></div>
                <div class="card-body">
                    <ul class="mb-3 ps-3" style="font-size:14px;line-height:1.7;">
                        <li>The destination always carries the inviting member's referral code.</li>
                        <li>Clicking it is recorded against that prospect, and a sign-up afterwards
                            traces back here even if they use a different email address.</li>
                    </ul>

                    <div class="d-flex gap-2">
                        <button class="btn btn-primary">Save</button>
                        <a href="{{ route('admin.cta-items.index') }}"
                           class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</form>
@endsection

@push('scripts')
<script>
/*
 * Only a plain link needs a URL typed. Everything else resolves to one of our
 * own pages, and saying which one is more use than an empty box.
 */
(function () {
    var kind  = document.getElementById('kind');
    var field = document.getElementById('url-field');
    // Outside the URL box on purpose: the box is the thing that gets hidden,
    // and the explanation is most needed exactly when it is.
    var hint  = document.getElementById('kind-hint');
    if (!kind || !field) return;

    var HINTS = {
        join:          'Goes to the partner sign-up page for whoever invited them.',
        schedule_call: 'The link to your booking page (Calendly or similar).',
        custom:        'Where the button goes.'
    };

    function apply() {
        var k = kind.value;
        // A booking can optionally override its destination; a plain link must.
        field.style.display = (k === 'custom' || k === 'schedule_call') ? '' : 'none';
        hint.textContent = HINTS[k] || '';
    }

    kind.addEventListener('change', apply);
    apply();
})();
</script>
@endpush
