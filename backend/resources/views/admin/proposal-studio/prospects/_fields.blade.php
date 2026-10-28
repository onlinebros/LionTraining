{{-- The prospect's record. $prospect is null on the create form. --}}
@php $p = $prospect ?? null; @endphp
<div class="row g-3">
    <div class="col-md-6">
        <label class="form-label small fw-semibold" for="ps-company">Company name</label>
        <input id="ps-company" type="text" name="company_name" maxlength="255" required class="form-control @error('company_name') is-invalid @enderror" value="{{ old('company_name', $p?->company_name) }}">
        @error('company_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-6">
        <label class="form-label small fw-semibold" for="ps-offering">Offering</label>
        <select id="ps-offering" name="offering_id" class="form-select" required>
            @foreach($offerings as $offering)
                <option value="{{ $offering->id }}" @selected((int) old('offering_id', $p?->offering_id) === $offering->id)>{{ $offering->name }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-md-6">
        <label class="form-label small fw-semibold" for="ps-website">Website</label>
        <input id="ps-website" type="url" name="website" maxlength="255" class="form-control @error('website') is-invalid @enderror" value="{{ old('website', $p?->website) }}" placeholder="https://">
        @error('website')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-6">
        <label class="form-label small fw-semibold" for="ps-industry">Industry</label>
        <input id="ps-industry" type="text" name="industry" maxlength="255" class="form-control" value="{{ old('industry', $p?->industry) }}" placeholder="e.g. Dental practice, gym, school">
    </div>
    <div class="col-md-4">
        <label class="form-label small fw-semibold" for="ps-contact">Main contact</label>
        <input id="ps-contact" type="text" name="contact_name" maxlength="255" class="form-control" value="{{ old('contact_name', $p?->contact_name) }}">
    </div>
    <div class="col-md-4">
        <label class="form-label small fw-semibold" for="ps-email">Email</label>
        <input id="ps-email" type="email" name="contact_email" maxlength="255" class="form-control @error('contact_email') is-invalid @enderror" value="{{ old('contact_email', $p?->contact_email) }}">
        @error('contact_email')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-4">
        <label class="form-label small fw-semibold" for="ps-phone">Phone</label>
        <input id="ps-phone" type="text" name="contact_phone" maxlength="40" class="form-control" value="{{ old('contact_phone', $p?->contact_phone) }}">
    </div>
</div>
