{{-- The account's record. $account is null on the create form. --}}
@php $a = $account ?? null; @endphp
<div class="row g-3">
    <div class="col-md-6">
        <label class="form-label small fw-semibold" for="ba-name">Company name</label>
        <input id="ba-name" type="text" name="name" maxlength="255" required class="form-control @error('name') is-invalid @enderror" value="{{ old('name', $a?->name) }}">
        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-6">
        <label class="form-label small fw-semibold" for="ba-website">Website</label>
        <input id="ba-website" type="url" name="website" maxlength="255" class="form-control @error('website') is-invalid @enderror" value="{{ old('website', $a?->website) }}" placeholder="https://">
        @error('website')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-4">
        <label class="form-label small fw-semibold" for="ba-industry">Industry</label>
        <input id="ba-industry" type="text" name="industry" maxlength="255" class="form-control" value="{{ old('industry', $a?->industry) }}" placeholder="e.g. Hotel group, school district">
    </div>
    <div class="col-md-4">
        <label class="form-label small fw-semibold" for="ba-size">Employees</label>
        <select id="ba-size" name="size" class="form-select">
            <option value="">Not known</option>
            @foreach(\App\Models\BusinessAccount::SIZES as $size)
                <option value="{{ $size }}" @selected(old('size', $a?->size) === $size)>{{ $size }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-md-4">
        <label class="form-label small fw-semibold" for="ba-hq">Headquarters</label>
        <input id="ba-hq" type="text" name="headquarters" maxlength="255" class="form-control" value="{{ old('headquarters', $a?->headquarters) }}" placeholder="City, state">
    </div>
    <div class="col-md-6">
        <label class="form-label small fw-semibold" for="ba-next">Next step</label>
        <input id="ba-next" type="text" name="next_step" maxlength="255" class="form-control" value="{{ old('next_step', $a?->next_step) }}" placeholder="e.g. Walk-through with their facilities lead">
    </div>
    <div class="col-md-3">
        <label class="form-label small fw-semibold" for="ba-due">By</label>
        <input id="ba-due" type="date" name="next_step_due" class="form-control" value="{{ old('next_step_due', $a?->next_step_due?->format('Y-m-d')) }}">
    </div>
    <div class="col-md-3">
        <label class="form-label small fw-semibold" for="ba-owner">Owner</label>
        <select id="ba-owner" name="owner_user_id" class="form-select">
            @foreach($admins as $admin)
                <option value="{{ $admin->id }}" @selected((int) old('owner_user_id', $a?->owner_user_id ?? auth()->id()) === $admin->id)>{{ $admin->name }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-12">
        <label class="form-label small fw-semibold" for="ba-notes">Notes</label>
        <textarea id="ba-notes" name="notes" rows="4" maxlength="20000" class="form-control" placeholder="How we met them, what they care about, how they buy. The assistant reads this when it writes their microsite.">{{ old('notes', $a?->notes) }}</textarea>
    </div>
</div>
