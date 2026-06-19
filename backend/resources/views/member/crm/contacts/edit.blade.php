@extends('layouts.member')

@section('title', 'Edit — ' . $contact->full_name)
@section('page-title', 'Edit Contact')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('member.crm.dashboard') }}">CRM</a></li>
    <li class="breadcrumb-item"><a href="{{ route('member.crm.contacts.index') }}">Contacts</a></li>
    <li class="breadcrumb-item"><a href="{{ route('member.crm.contacts.show', $contact) }}">{{ $contact->full_name }}</a></li>
    <li class="breadcrumb-item active">Edit</li>
@endsection

@section('content')
<div class="row justify-content-center">
    <div class="col-xl-9">
        <form method="POST" action="{{ route('member.crm.contacts.update', $contact) }}">
            @csrf @method('PUT')

            @if($errors->any())
                <div class="alert alert-danger">
                    <ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
                </div>
            @endif

            <div class="row g-3">

                <div class="col-lg-8">
                    <div class="card">
                        <div class="card-header"><h5 class="mb-0">Contact Information</h5></div>
                        <div class="card-body">
                            <div class="row g-3">
                                <div class="col-sm-6">
                                    <label class="form-label">First Name <span class="text-danger">*</span></label>
                                    <input type="text" name="first_name" class="form-control @error('first_name') is-invalid @enderror"
                                           value="{{ old('first_name', $contact->first_name) }}" required>
                                    @error('first_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>
                                <div class="col-sm-6">
                                    <label class="form-label">Last Name</label>
                                    <input type="text" name="last_name" class="form-control" value="{{ old('last_name', $contact->last_name) }}">
                                </div>
                                <div class="col-sm-6">
                                    <label class="form-label">Email</label>
                                    <input type="email" name="email" class="form-control @error('email') is-invalid @enderror"
                                           value="{{ old('email', $contact->email) }}">
                                    @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>
                                <div class="col-sm-6">
                                    <label class="form-label">Phone</label>
                                    <input type="text" name="phone" class="form-control" value="{{ old('phone', $contact->phone) }}">
                                </div>
                                <div class="col-12">
                                    <label class="form-label">Company</label>
                                    <input type="text" name="company" class="form-control" value="{{ old('company', $contact->company) }}">
                                </div>
                                <div class="col-sm-6">
                                    <label class="form-label">City</label>
                                    <input type="text" name="city" class="form-control" value="{{ old('city', $contact->city) }}">
                                </div>
                                <div class="col-sm-3">
                                    <label class="form-label">State</label>
                                    <input type="text" name="state" class="form-control" value="{{ old('state', $contact->state) }}">
                                </div>
                                <div class="col-sm-3">
                                    <label class="form-label">Country</label>
                                    <input type="text" name="country" class="form-control" value="{{ old('country', $contact->country) }}">
                                </div>
                                <div class="col-12">
                                    <label class="form-label">Notes</label>
                                    <textarea name="quick_note" class="form-control" rows="3">{{ old('quick_note', $contact->quick_note) }}</textarea>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-lg-4">
                    <div class="card">
                        <div class="card-header"><h5 class="mb-0">Classification</h5></div>
                        <div class="card-body">
                            <div class="mb-3">
                                <label class="form-label">Type <span class="text-danger">*</span></label>
                                <select name="contact_type" class="form-select" required>
                                    @foreach(\App\Models\CrmContact::$contactTypes as $key => $label)
                                        <option value="{{ $key }}" {{ old('contact_type', $contact->contact_type) === $key ? 'selected' : '' }}>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Status <span class="text-danger">*</span></label>
                                <select name="status" class="form-select" required>
                                    @foreach(\App\Models\CrmContact::$statuses as $key => $meta)
                                        <option value="{{ $key }}" {{ old('status', $contact->status) === $key ? 'selected' : '' }}>{{ $meta['label'] }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Lead Source</label>
                                <select name="lead_source" class="form-select">
                                    <option value="">— Select —</option>
                                    @foreach(\App\Models\CrmContact::$leadSources as $key => $label)
                                        <option value="{{ $key }}" {{ old('lead_source', $contact->lead_source) === $key ? 'selected' : '' }}>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Next Follow-up</label>
                                <input type="datetime-local" name="next_followup_at" class="form-control"
                                       value="{{ old('next_followup_at', $contact->next_followup_at?->format('Y-m-d\TH:i')) }}">
                            </div>
                        </div>
                    </div>

                    @if($tags->count() > 0)
                    <div class="card mt-3">
                        <div class="card-header"><h5 class="mb-0">Tags</h5></div>
                        <div class="card-body">
                            @foreach($tags as $tag)
                                <div class="form-check mb-1">
                                    <input class="form-check-input" type="checkbox" name="tags[]"
                                           value="{{ $tag->id }}" id="tag_{{ $tag->id }}"
                                           {{ in_array($tag->id, old('tags', $selectedTags)) ? 'checked' : '' }}>
                                    <label class="form-check-label" for="tag_{{ $tag->id }}">
                                        <span class="badge" style="background:{{ $tag->color }};color:#fff;">{{ $tag->name }}</span>
                                    </label>
                                </div>
                            @endforeach
                        </div>
                    </div>
                    @endif

                    <div class="mt-3 d-flex gap-2">
                        <button type="submit" class="btn btn-primary flex-grow-1">
                            <i data-feather="save" data-width="14" data-height="14"></i> Update
                        </button>
                        <a href="{{ route('member.crm.contacts.show', $contact) }}" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </div>

            </div>
        </form>
    </div>
</div>
@endsection
