@extends('layouts.admin')

@php $editing = $company->exists; @endphp

@section('title', $editing ? 'Edit ' . $company->name : 'Add Partner Company')
@section('page-title', $editing ? 'Edit ' . $company->name : 'Add Partner Company')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('admin.partners.companies.index') }}">Companies</a></li>
    <li class="breadcrumb-item active">{{ $editing ? 'Edit' : 'Add' }}</li>
@endsection

@section('content')

@if($errors->any())<div class="alert alert-danger py-2">{{ $errors->first() }}</div>@endif

<form method="POST" enctype="multipart/form-data"
      action="{{ $editing ? route('admin.partners.companies.update', $company) : route('admin.partners.companies.store') }}">
    @csrf
    @if($editing) @method('PUT') @endif

    <div class="row g-3">
        <div class="col-lg-7">
            <div class="card mb-0">
                <div class="card-header py-3"><h6 class="mb-0 fw-bold">The company</h6></div>
                <div class="card-body">

                    <div class="mb-3">
                        <label class="form-label" for="name">Company name</label>
                        <input class="form-control" id="name" type="text" name="name"
                               value="{{ old('name', $company->name) }}" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="slug">Claim link</label>
                        <div class="input-group">
                            <span class="input-group-text">{{ url('/partner') }}/</span>
                            <input class="form-control" id="slug" type="text" name="slug"
                                   value="{{ old('slug', $company->slug) }}"
                                   placeholder="acme-group" {{ $editing ? 'disabled' : '' }}>
                        </div>
                        <div class="form-text">
                            @if($editing)
                                Fixed once the company is created — this URL goes out in the partner's
                                own mailings, and changing it breaks every link already sent.
                            @else
                                Lowercase letters, numbers and hyphens. Leave blank to build it from
                                the name. It cannot be changed afterwards.
                            @endif
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="headline">Headline on the claim page</label>
                        <input class="form-control" id="headline" type="text" name="headline"
                               value="{{ old('headline', $company->headline) }}"
                               placeholder="Claim your position">
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="intro">Introduction</label>
                        <textarea class="form-control" id="intro" name="intro" rows="3"
                                  placeholder="A position has been reserved for you…">{{ old('intro', $company->intro) }}</textarea>
                        <div class="form-text">Shown under the headline. Leave blank for the default wording.</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="support_email">Their support address</label>
                        <input class="form-control" id="support_email" type="email" name="support_email"
                               value="{{ old('support_email', $company->support_email) }}"
                               placeholder="support@acme.example">
                        <div class="form-text">
                            Where we send someone who cannot find their ID or code. They issued both,
                            so they are the only ones who can look them up.
                        </div>
                    </div>

                    <div class="form-check form-switch mb-0">
                        <input class="form-check-input" type="checkbox" id="is_active" name="is_active"
                               value="1" @checked(old('is_active', $company->is_active))>
                        <label class="form-check-label" for="is_active">
                            Claim page is live
                        </label>
                        <div class="form-text">
                            Leave this off until the list is imported. A live page with no spots behind
                            it tells every visitor their details do not match.
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card mb-3">
                <div class="card-header py-3"><h6 class="mb-0 fw-bold">What they call the two fields</h6></div>
                <div class="card-body">
                    <p class="text-muted small">
                        Their people know these credentials by the partner's own names for them. A page
                        asking for a "User ID" when their card says "Distributor Number" is a support
                        ticket per person.
                    </p>

                    <div class="mb-3">
                        <label class="form-label" for="identifier_label">Identifier field label</label>
                        <input class="form-control" id="identifier_label" type="text" name="identifier_label"
                               value="{{ old('identifier_label', $company->identifier_label) }}"
                               placeholder="User ID">
                    </div>

                    <div class="mb-0">
                        <label class="form-label" for="activation_label">Code field label</label>
                        <input class="form-control" id="activation_label" type="text" name="activation_label"
                               value="{{ old('activation_label', $company->activation_label) }}"
                               placeholder="Activation Code">
                    </div>
                </div>
            </div>

            <div class="card mb-0">
                <div class="card-header py-3"><h6 class="mb-0 fw-bold">Logo</h6></div>
                <div class="card-body">
                    <p class="text-muted small">
                        Sits beside the Quantum mark at the top of the claim page. PNG or SVG with a
                        transparent background works best.
                    </p>

                    @if($logo = $company->logoUrl())
                        {{-- On a dark panel, so the dark mark. --}}
                        <div class="mb-2 p-2 rounded" style="background:var(--q3-surface-2);">
                            <img src="{{ $logo }}" alt="{{ $company->name }}"
                                 style="max-height:48px;max-width:100%;object-fit:contain;">
                        </div>
                    @endif

                    <div class="mb-3">
                        <label class="form-label" for="logo">Logo (light backgrounds)</label>
                        <input class="form-control" id="logo" type="file" name="logo"
                               accept="image/png,image/jpeg,image/webp,image/svg+xml">
                    </div>

                    <div class="mb-0">
                        <label class="form-label" for="logo_dark">Logo (dark backgrounds)</label>
                        <input class="form-control" id="logo_dark" type="file" name="logo_dark"
                               accept="image/png,image/jpeg,image/webp,image/svg+xml">
                        <div class="form-text">Optional — the light version is used if this is blank.</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ── Claim webhook ──────────────────────────────────────────────────── --}}
    <div class="card mt-3">
        <div class="card-header py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
            <h6 class="mb-0 fw-bold">Tell them when a spot is claimed</h6>
            @if($editing && $company->webhookConfigured())
                <span class="small text-muted">
                    @if($company->webhook_last_success_at)
                        Last delivered {{ $company->webhook_last_success_at->diffForHumans() }}
                    @else
                        Nothing delivered yet
                    @endif
                    @if($company->webhook_consecutive_failures > 0)
                        &middot; <span class="text-danger">{{ $company->webhook_consecutive_failures }} failure(s) in a row</span>
                    @endif
                </span>
            @endif
        </div>
        <div class="card-body">
            <p class="text-muted">
                We POST a signed JSON event to this URL whenever one of their people claims a
                position. Retries run out to about two hours; after that you replay it from the
                <a href="{{ route('admin.partners.webhooks.index') }}">deliveries screen</a>.
            </p>

            <div class="row">
                <div class="col-lg-7 mb-3">
                    <label class="form-label" for="webhook_url">Endpoint URL</label>
                    <input class="form-control" id="webhook_url" type="url" name="webhook_url"
                           value="{{ old('webhook_url', $company->webhook_url) }}"
                           placeholder="https://api.partner.example/quantum/spot-claimed">
                    <div class="form-text">Must be https.</div>
                </div>

                <div class="col-lg-5 mb-3">
                    <label class="form-label" for="webhook_secret">Signing secret</label>
                    <input class="form-control" id="webhook_secret" type="password" name="webhook_secret"
                           autocomplete="new-password"
                           placeholder="{{ $editing && filled($company->webhook_secret) ? 'Set — leave blank to keep' : 'At least 16 characters' }}">
                    <div class="form-text">
                        Shared with the partner so they can verify each delivery. Stored encrypted and
                        never shown again — leave blank to keep the current one.
                    </div>
                </div>
            </div>

            <div class="mb-3" style="max-width:520px;">
                <label class="form-label" for="webhook_signature_style">Signature the partner reads</label>
                <select class="form-select" id="webhook_signature_style" name="webhook_signature_style">
                    @foreach(\App\Models\PartnerCompany::SIGNATURE_STYLES as $value => $label)
                        <option value="{{ $value }}"
                            @selected(old('webhook_signature_style', $company->webhook_signature_style) === $value)>
                            {{ $label }}
                        </option>
                    @endforeach
                </select>
                <div class="form-text">
                    Their receiver already exists — match what it verifies. Ask them which header
                    they read and whether the timestamp is part of the signed material.
                </div>
            </div>

            <div class="form-check form-switch mb-3">
                <input class="form-check-input" type="checkbox" id="webhook_enabled" name="webhook_enabled"
                       value="1" @checked(old('webhook_enabled', $company->webhook_enabled))>
                <label class="form-check-label" for="webhook_enabled">Send claim events</label>
            </div>

            {{-- The one setting on this page that discloses somebody else's
                 personal data. It reads like a warning because it is one. --}}
            <div class="form-check form-switch mb-2">
                <input class="form-check-input" type="checkbox" id="webhook_include_contact"
                       name="webhook_include_contact" value="1"
                       @checked(old('webhook_include_contact', $company->webhook_include_contact))>
                <label class="form-check-label" for="webhook_include_contact">
                    Include the member's name, email and phone
                </label>
            </div>
            <div class="alert alert-warning small mb-0">
                Off, the event carries their {{ $company->identifier_label ?: 'User ID' }}, when they
                claimed and where the position sits — enough for the partner to reconcile their own
                list, and nothing they did not already know.
                <br>
                On, it also sends contact details the member gave <em>us</em>. That is a disclosure to
                a third party: make sure the claim page and our privacy policy say it happens before
                you switch it on.
            </div>

        </div>
    </div>

    <div class="mt-3">
        <button class="btn btn-primary" type="submit">{{ $editing ? 'Save changes' : 'Add company' }}</button>
        <a href="{{ route('admin.partners.companies.index') }}" class="btn btn-outline-secondary">Cancel</a>
    </div>
</form>

{{-- Its own form, outside the one above: HTML cannot nest forms, and this POST
     must not carry the edit form's _method=PUT override. --}}
@if($editing && $company->webhookConfigured())
    <form method="POST" action="{{ route('admin.partners.companies.ping', $company) }}" class="mt-2">
        @csrf
        <button class="btn btn-sm btn-outline-secondary" type="submit">Send a test event</button>
        <span class="small text-muted ms-2">
            Sends to the saved settings — save first if you have just changed them.
        </span>
    </form>
@endif

@endsection
