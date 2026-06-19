@extends('layouts.admin')

@section('title', 'Edit — ' . $crmContact->full_name)
@section('page-title', 'Edit Contact')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('admin.crm.dashboard') }}">CRM</a></li>
    <li class="breadcrumb-item"><a href="{{ route('admin.crm.contacts.index') }}">Contacts</a></li>
    <li class="breadcrumb-item"><a href="{{ route('admin.crm.contacts.show', $crmContact) }}">{{ $crmContact->full_name }}</a></li>
    <li class="breadcrumb-item active">Edit</li>
@endsection

@section('content')
<div class="row justify-content-center">
    <div class="col-xl-10">
        <form method="POST" action="{{ route('admin.crm.contacts.update', $crmContact) }}">
            @csrf @method('PUT')

            @if($errors->any())
                <div class="alert alert-danger">
                    <ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
                </div>
            @endif

            <div class="row g-3">

                {{-- Core Info --}}
                <div class="col-lg-8">
                    <div class="card">
                        <div class="card-header"><h5 class="mb-0">Contact Information</h5></div>
                        <div class="card-body">
                            <div class="row g-3">
                                <div class="col-sm-6">
                                    <label class="form-label">First Name <span class="text-danger">*</span></label>
                                    <input type="text" name="first_name" class="form-control @error('first_name') is-invalid @enderror"
                                           value="{{ old('first_name', $crmContact->first_name) }}" required>
                                    @error('first_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>
                                <div class="col-sm-6">
                                    <label class="form-label">Last Name</label>
                                    <input type="text" name="last_name" class="form-control"
                                           value="{{ old('last_name', $crmContact->last_name) }}">
                                </div>
                                <div class="col-sm-6">
                                    <label class="form-label">Email</label>
                                    <input type="email" name="email" class="form-control @error('email') is-invalid @enderror"
                                           value="{{ old('email', $crmContact->email) }}">
                                    @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>
                                <div class="col-sm-6">
                                    <label class="form-label">Phone</label>
                                    <input type="text" name="phone" class="form-control"
                                           value="{{ old('phone', $crmContact->phone) }}">
                                </div>
                                <div class="col-sm-6">
                                    <label class="form-label">Company</label>
                                    <input type="text" name="company" class="form-control"
                                           value="{{ old('company', $crmContact->company) }}">
                                </div>
                                <div class="col-sm-6">
                                    <label class="form-label">Website</label>
                                    <input type="url" name="website" class="form-control"
                                           value="{{ old('website', $crmContact->website) }}" placeholder="https://">
                                </div>
                                <div class="col-12">
                                    <label class="form-label">Quick Note</label>
                                    <textarea name="quick_note" class="form-control" rows="3">{{ old('quick_note', $crmContact->quick_note) }}</textarea>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="card mt-3">
                        <div class="card-header"><h5 class="mb-0">Address</h5></div>
                        <div class="card-body">
                            <div class="row g-3">
                                <div class="col-12">
                                    <input type="text" name="address_line1" class="form-control" placeholder="Address Line 1"
                                           value="{{ old('address_line1', $crmContact->address_line1) }}">
                                </div>
                                <div class="col-12">
                                    <input type="text" name="address_line2" class="form-control" placeholder="Address Line 2"
                                           value="{{ old('address_line2', $crmContact->address_line2) }}">
                                </div>
                                <div class="col-sm-4">
                                    <input type="text" name="city" class="form-control" placeholder="City"
                                           value="{{ old('city', $crmContact->city) }}">
                                </div>
                                <div class="col-sm-4">
                                    <input type="text" name="state" class="form-control" placeholder="State / Province"
                                           value="{{ old('state', $crmContact->state) }}">
                                </div>
                                <div class="col-sm-4">
                                    <input type="text" name="postal_code" class="form-control" placeholder="Postal Code"
                                           value="{{ old('postal_code', $crmContact->postal_code) }}">
                                </div>
                                <div class="col-12">
                                    <input type="text" name="country" class="form-control" placeholder="Country"
                                           value="{{ old('country', $crmContact->country) }}">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Sidebar --}}
                <div class="col-lg-4">
                    <div class="card">
                        <div class="card-header"><h5 class="mb-0">Classification</h5></div>
                        <div class="card-body">
                            <div class="mb-3">
                                <label class="form-label">Contact Type <span class="text-danger">*</span></label>
                                <select name="contact_type" class="form-select" required>
                                    @foreach(\App\Models\CrmContact::$contactTypes as $key => $label)
                                        <option value="{{ $key }}" {{ old('contact_type', $crmContact->contact_type) === $key ? 'selected' : '' }}>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Status <span class="text-danger">*</span></label>
                                <select name="status" class="form-select" required>
                                    @foreach(\App\Models\CrmContact::$statuses as $key => $meta)
                                        <option value="{{ $key }}" {{ old('status', $crmContact->status) === $key ? 'selected' : '' }}>{{ $meta['label'] }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Lead Source</label>
                                <select name="lead_source" class="form-select">
                                    <option value="">— Select —</option>
                                    @foreach(\App\Models\CrmContact::$leadSources as $key => $label)
                                        <option value="{{ $key }}" {{ old('lead_source', $crmContact->lead_source) === $key ? 'selected' : '' }}>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Owner (Affiliate) <span class="text-danger">*</span></label>
                                <select name="owner_id" class="form-select" required>
                                    @foreach($owners as $o)
                                        <option value="{{ $o->id }}" {{ old('owner_id', $crmContact->owner_id) == $o->id ? 'selected' : '' }}>{{ $o->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Assigned To</label>
                                <select name="assigned_to" class="form-select">
                                    <option value="">Same as owner</option>
                                    @foreach($owners as $o)
                                        <option value="{{ $o->id }}" {{ old('assigned_to', $crmContact->assigned_to) == $o->id ? 'selected' : '' }}>{{ $o->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Next Follow-up Date</label>
                                <input type="datetime-local" name="next_followup_at" class="form-control"
                                       value="{{ old('next_followup_at', $crmContact->next_followup_at?->format('Y-m-d\TH:i')) }}">
                            </div>
                        </div>
                    </div>

                    <div class="card mt-3">
                        <div class="card-header"><h5 class="mb-0">Tags</h5></div>
                        <div class="card-body">
                            @forelse($tags as $tag)
                                <div class="form-check mb-1">
                                    <input class="form-check-input" type="checkbox" name="tags[]"
                                           value="{{ $tag->id }}" id="tag_{{ $tag->id }}"
                                           {{ in_array($tag->id, old('tags', $selectedTags)) ? 'checked' : '' }}>
                                    <label class="form-check-label" for="tag_{{ $tag->id }}">
                                        <span class="badge" style="background:{{ $tag->color }};color:#fff;">{{ $tag->name }}</span>
                                    </label>
                                </div>
                            @empty
                                <p class="f-light small mb-0">No tags available.</p>
                            @endforelse
                        </div>
                    </div>

                    <div class="mt-3 d-flex gap-2">
                        <button type="submit" class="btn btn-primary flex-grow-1">
                            <i data-feather="save" data-width="14" data-height="14"></i> Update Contact
                        </button>
                        <a href="{{ route('admin.crm.contacts.show', $crmContact) }}" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </div>

            </div>
        </form>
    </div>
</div>
@endsection
