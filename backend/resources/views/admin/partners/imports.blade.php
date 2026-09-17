@extends('layouts.admin')

@section('title', 'Spot Imports')
@section('page-title', 'Spot Imports')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('admin.partners.spots') }}">Partner Spots</a></li>
    <li class="breadcrumb-item active">Imports</li>
@endsection

@section('content')

@if(session('success'))<div class="alert alert-success py-2">{{ session('success') }}</div>@endif
@if($errors->any())<div class="alert alert-danger py-2">{{ $errors->first() }}</div>@endif

<div class="row g-3 mb-3">
    <div class="col-lg-5">
        <div class="card mb-0 h-100">
            <div class="card-header py-3"><h6 class="mb-0 fw-bold">Upload a list</h6></div>
            <div class="card-body">
                @if($companies->isEmpty())
                    <p class="text-muted mb-2">Add a partner company first — an import belongs to one.</p>
                    <a href="{{ route('admin.partners.companies.create') }}" class="btn btn-sm btn-primary">Add company</a>
                @else
                    <form method="POST" action="{{ route('admin.partners.imports.store') }}"
                          enctype="multipart/form-data">
                        @csrf

                        <div class="mb-3">
                            <label class="form-label" for="partner_company_id">Company</label>
                            <select class="form-select" id="partner_company_id" name="partner_company_id" required>
                                @foreach($companies as $company)
                                    <option value="{{ $company->id }}">{{ $company->name }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label" for="file">CSV file</label>
                            <input class="form-control" id="file" type="file" name="file" accept=".csv,text/csv" required>
                            <div class="form-text">
                                Nothing is created by uploading. The file is staged, checked, and shown
                                to you before anything reaches the genealogy. It holds live activation
                                codes, so delete your copy once the import is committed.
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label" for="notes">Notes</label>
                            <input class="form-control" id="notes" type="text" name="notes"
                                   placeholder="e.g. First tranche, west region">
                        </div>

                        <button class="btn btn-primary" type="submit">Stage this file</button>
                    </form>
                @endif
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="card mb-0 h-100">
            <div class="card-header py-3"><h6 class="mb-0 fw-bold">The template</h6></div>
            <div class="card-body">
                <p class="text-muted">
                    Send this to the partner company. Four columns, no personal data:
                    <code>external_user_id</code> and <code>activation_code</code> are required, and
                    the tree is described by <code>external_parent_id</code> pointing at another
                    row's <code>external_user_id</code>. <code>external_sponsor_id</code> is optional
                    and only needed if they track recruitment separately from position.
                </p>
                <p class="text-muted">
                    <strong>No names, emails, phone numbers or addresses.</strong> We import
                    positions, not people — each member gives us their own details when they claim.
                    Any other column in the file has its values discarded on read; we keep the
                    column names only, so you can tell the partner what was dropped.
                </p>
                <p class="text-muted">
                    Rows with a blank parent are the tops of legs. Those are the ones you connect to
                    partners already in our system on the review screen, and they are the only part of
                    an import that cannot be fixed afterwards — placements are permanent.
                </p>
                <a href="{{ route('admin.partners.imports.template') }}" class="btn btn-primary btn-sm">
                    Download template (with examples)
                </a>
                <a href="{{ route('admin.partners.imports.template', ['blank' => 1]) }}"
                   class="btn btn-outline-secondary btn-sm">Headers only</a>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header py-3"><h6 class="mb-0 fw-bold">Uploads</h6></div>
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead><tr>
                <th>File</th><th>Company</th><th>Rows</th><th>Status</th>
                <th>Uploaded</th><th>By</th><th></th>
            </tr></thead>
            <tbody>
            @forelse($imports as $import)
                <tr>
                    <td class="fw-semibold">{{ $import->original_filename }}</td>
                    <td class="small">{{ $import->company?->name }}</td>
                    <td class="small">
                        {{ number_format($import->total_rows) }}
                        @if($import->error_rows > 0)
                            <span class="badge bg-danger">{{ $import->error_rows }} with errors</span>
                        @endif
                    </td>
                    <td>
                        @switch($import->status)
                            @case(\App\Models\PartnerImport::STATUS_COMMITTED)
                                <span class="badge bg-success">Committed</span>
                                <div class="small text-muted">{{ number_format($import->committed_rows) }} spots</div>
                                @break
                            @case(\App\Models\PartnerImport::STATUS_VALIDATED)
                                <span class="badge bg-info">Checked &mdash; ready</span>
                                @break
                            @case(\App\Models\PartnerImport::STATUS_FAILED)
                                <span class="badge bg-danger">Needs fixing</span>
                                @break
                            @default
                                <span class="badge bg-secondary">Staged</span>
                        @endswitch
                    </td>
                    <td class="small text-muted">{{ $import->created_at->format('M j, Y g:ia') }}</td>
                    <td class="small text-muted">{{ $import->uploader?->name ?? '—' }}</td>
                    <td class="text-end">
                        <a href="{{ route('admin.partners.imports.show', $import) }}"
                           class="btn btn-sm btn-outline-secondary">Review</a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="text-center text-muted py-4">Nothing uploaded yet.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="card-body">{{ $imports->links() }}</div>
</div>

@endsection
