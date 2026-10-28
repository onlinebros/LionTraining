@extends('layouts.admin')

@section('title', 'Offerings')
@section('page-title', 'Proposal Studio')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('admin.proposal-studio.prospects.index') }}">Proposal Studio</a></li>
    <li class="breadcrumb-item active">Offerings</li>
@endsection

@section('content')
<div class="container-fluid">
    @include('admin.proposal-studio._nav')

    <div class="row g-3">
        <div class="col-xl-8">
            <div class="card">
                <div class="card-header"><h5 class="mb-0">Offerings</h5></div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table mb-0">
                            <thead><tr><th>Offering</th><th>Vendor</th><th>Documents</th><th>Claims to review</th><th>Prospects</th><th></th></tr></thead>
                            <tbody>
                            @forelse($offerings as $offering)
                                <tr>
                                    <td><a href="{{ route('admin.proposal-studio.offerings.show', $offering) }}">{{ $offering->name }}</a></td>
                                    <td class="small">{{ $offering->vendor_name }}</td>
                                    <td class="small">{{ $offering->documents_count }}</td>
                                    <td class="small">{{ $offering->draft_claims_count ?: '' }}</td>
                                    <td class="small">{{ $offering->prospects_count }}</td>
                                    <td>@unless($offering->is_active)<span class="badge badge-light-secondary">Inactive</span>@endunless</td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="f-light small p-4">No offerings yet.</td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-4">
            <div class="card">
                <div class="card-header"><h6 class="mb-0 fw-bold">Add an offering</h6></div>
                <div class="card-body">
                    <p class="small f-light">A product line we present. The assistant learns it from the documents you add to it, so a new line needs no new code.</p>
                    <form method="POST" action="{{ route('admin.proposal-studio.offerings.store') }}">
                        @csrf
                        <div class="mb-2">
                            <label class="form-label small fw-semibold" for="of-name">Name</label>
                            <input id="of-name" name="name" class="form-control @error('name') is-invalid @enderror" maxlength="255" required value="{{ old('name') }}">
                            @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="mb-2">
                            <label class="form-label small fw-semibold" for="of-vendor">Vendor</label>
                            <input id="of-vendor" name="vendor_name" class="form-control @error('vendor_name') is-invalid @enderror" maxlength="255" required value="{{ old('vendor_name') }}">
                            @error('vendor_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-semibold" for="of-key">Short key</label>
                            <input id="of-key" name="key" class="form-control @error('key') is-invalid @enderror" maxlength="40" required value="{{ old('key') }}" placeholder="e.g. acme-solar">
                            @error('key')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <button class="btn btn-primary btn-sm">Add offering</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
