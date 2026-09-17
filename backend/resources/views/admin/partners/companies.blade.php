@extends('layouts.admin')

@section('title', 'Partner Companies')
@section('page-title', 'Partner Companies')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('admin.partners.spots') }}">Partner Spots</a></li>
    <li class="breadcrumb-item active">Companies</li>
@endsection

@section('content')

@if(session('success'))<div class="alert alert-success py-2">{{ session('success') }}</div>@endif

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <p class="text-muted mb-0" style="max-width:640px;">
        Each company gets its own claim page, its own branding, and its own namespace for member
        IDs — two companies can both have a member 1001 without colliding.
    </p>
    <a href="{{ route('admin.partners.companies.create') }}" class="btn btn-primary btn-sm">Add company</a>
</div>

<div class="row g-3">
@forelse($companies as $company)
    @php $c = $counts[$company->id]; @endphp
    <div class="col-md-6">
        <div class="card mb-0 h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                    <div>
                        <h6 class="mb-1 fw-bold">{{ $company->name }}</h6>
                        <div class="small text-muted">
                            {{ $company->identifier_label }} &middot; {{ $company->activation_label }}
                        </div>
                    </div>
                    @if($company->is_active)
                        <span class="badge bg-success">Claim page live</span>
                    @else
                        <span class="badge bg-secondary">Not live</span>
                    @endif
                </div>

                <div class="d-flex gap-4 my-3">
                    <div>
                        <div class="fs-5 fw-bold">{{ number_format($c['unclaimed']) }}</div>
                        <div class="small text-muted">Unclaimed</div>
                    </div>
                    <div>
                        <div class="fs-5 fw-bold">{{ number_format($c['claimed']) }}</div>
                        <div class="small text-muted">Claimed</div>
                    </div>
                    <div>
                        <div class="fs-5 fw-bold">{{ $company->imports_count }}</div>
                        <div class="small text-muted">Imports</div>
                    </div>
                </div>

                <div class="small text-muted mb-3" style="word-break:break-all;">
                    {{ $company->claimUrl() }}
                </div>

                <a href="{{ route('admin.partners.companies.edit', $company) }}"
                   class="btn btn-sm btn-outline-secondary">Edit</a>
                <a href="{{ route('admin.partners.spots', ['company' => $company->id]) }}"
                   class="btn btn-sm btn-outline-secondary">Spots</a>
                @if($company->is_active)
                    <a href="{{ $company->claimUrl() }}" target="_blank" rel="noopener"
                       class="btn btn-sm btn-outline-secondary">Open claim page</a>
                @endif
            </div>
        </div>
    </div>
@empty
    <div class="col-12">
        <div class="card mb-0"><div class="card-body text-center text-muted py-5">
            <p class="mb-2 fw-semibold">No partner companies yet.</p>
            <p class="mb-3 small">Add one before uploading its list — an import belongs to a company.</p>
            <a href="{{ route('admin.partners.companies.create') }}" class="btn btn-primary btn-sm">Add company</a>
        </div></div>
    </div>
@endforelse
</div>

@endsection
