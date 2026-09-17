@extends('layouts.admin')

@section('title', 'Review ' . $import->original_filename)
@section('page-title', 'Review import')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('admin.partners.imports.index') }}">Imports</a></li>
    <li class="breadcrumb-item active">{{ $import->original_filename }}</li>
@endsection

@section('content')

@if(session('success'))<div class="alert alert-success py-2">{{ session('success') }}</div>@endif
@if($errors->any())<div class="alert alert-danger py-2">{{ $errors->first() }}</div>@endif

@php $committed = $import->isCommitted(); @endphp

<div class="row g-3 mb-3">
    <div class="col-md-3">
        <div class="card mb-0"><div class="card-body py-4 text-center">
            <div class="q3-stat-value">{{ number_format($import->total_rows) }}</div>
            <div class="q3-stat-label">Rows in file</div>
        </div></div>
    </div>
    <div class="col-md-3">
        <div class="card mb-0"><div class="card-body py-4 text-center">
            <div class="q3-stat-value">{{ number_format($import->valid_rows) }}</div>
            <div class="q3-stat-label">Ready</div>
        </div></div>
    </div>
    <div class="col-md-3">
        <div class="card mb-0"><div class="card-body py-4 text-center">
            <div class="q3-stat-value {{ $import->error_rows > 0 ? 'text-danger' : '' }}">
                {{ number_format($import->error_rows) }}
            </div>
            <div class="q3-stat-label">With errors</div>
        </div></div>
    </div>
    <div class="col-md-3">
        <div class="card mb-0"><div class="card-body py-4 text-center">
            <div class="q3-stat-value {{ $unlinked > 0 ? 'q3-stat-value--gold' : '' }}">
                {{ number_format($unlinked) }}
            </div>
            <div class="q3-stat-label">Legs not connected</div>
        </div></div>
    </div>
</div>

@if($import->errors)
    <div class="alert alert-danger">
        <div class="fw-bold mb-1">This file cannot be imported as it stands</div>
        <ul class="mb-0 small">
            @foreach($import->errors as $error)<li>{{ $error }}</li>@endforeach
        </ul>
    </div>
@endif

@if($import->ignored_columns)
    {{-- Named, not stored. We keep the column names so an admin can tell the
         partner what they sent us and we threw away; the values themselves
         never reached the database. --}}
    <div class="alert alert-warning">
        <div class="fw-bold mb-1">
            {{ count($import->ignored_columns) }} column(s) in this file were discarded
        </div>
        <div class="small mb-2">
            {{-- Escaped one at a time: these strings are a header row from
                 somebody else's spreadsheet, not markup we wrote. --}}
            @foreach($import->ignored_columns as $column)
                <code>{{ $column }}</code>@if(! $loop->last), @endif
            @endforeach
        </div>
        <div class="small mb-0">
            Only the column names were kept — the values were dropped on read and never stored.
            If any of these carried names, email addresses or phone numbers, tell
            {{ $import->company?->name }}: we do not want that data, and the next export should
            leave it out.
        </div>
    </div>
@endif

{{-- ── Connecting the legs ───────────────────────────────────────────────── --}}
{{-- The one thing on this page that has to be right. Everything else in an
     import can be corrected by re-uploading; a leg committed under the wrong
     partner is a position that cannot be moved. --}}
<div class="card mb-3">
    <div class="card-header py-3">
        <h6 class="mb-0 fw-bold">Connect each leg to a partner in our system</h6>
    </div>
    <div class="card-body pb-0">
        <p class="text-muted">
            These rows have no parent inside the file, so each one is the top of a leg. Whoever you
            name here receives that entire leg in their downline — permanently. Placements cannot be
            moved after commit, so check each one against what {{ $import->company?->name }} agreed.
        </p>
    </div>
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead><tr>
                <th>Line</th><th>Partner ID</th><th style="width:44%;">Sits beneath</th>
            </tr></thead>
            <tbody>
            @forelse($topRows as $row)
                <tr>
                    <td class="small text-muted">{{ $row->line_number }}</td>
                    <td class="fw-semibold" style="font-family:var(--bs-font-monospace,monospace);">
                        {{ $row->external_user_id }}
                    </td>
                    <td>
                        @if($committed)
                            <span class="fw-semibold">{{ $row->parentUser?->name ?? '—' }}</span>
                        @else
                            <form method="POST" class="d-flex gap-2"
                                  action="{{ route('admin.partners.imports.link', [$import, $row]) }}">
                                @csrf
                                <input type="text" name="existing_user" class="form-control form-control-sm"
                                       value="{{ $row->link_to_existing }}"
                                       placeholder="Quantum email or user ID">
                                <button class="btn btn-sm btn-primary" type="submit">Set</button>
                            </form>
                            @if($row->parentUser)
                                <div class="small text-success mt-1">
                                    &check; {{ $row->parentUser->name }}
                                    <span class="text-muted">({{ $row->parentUser->email }})</span>
                                </div>
                            @else
                                <div class="small text-warning mt-1">Not connected yet</div>
                            @endif
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="3" class="text-center text-muted py-4">
                    Every row in this file names a parent inside the file, which means nothing
                    connects it to our system. At least one row must have a blank
                    <code>external_parent_id</code>.
                </td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

{{-- ── Commit ────────────────────────────────────────────────────────────── --}}
@if(! $committed)
<div class="card mb-3">
    <div class="card-header py-3"><h6 class="mb-0 fw-bold">Import</h6></div>
    <div class="card-body">
        @if($import->isCommittable())
            <p class="text-muted">
                This creates {{ number_format($import->valid_rows) }} holding spots in the genealogy.
                They are placed immediately and hidden from every team view until their owners claim
                them. <strong>There is no undo</strong> — positions are permanent by design.
            </p>
            <form method="POST" action="{{ route('admin.partners.imports.commit', $import) }}"
                  class="row g-2 align-items-end" style="max-width:640px;">
                @csrf
                <div class="col-sm-8">
                    <label class="form-label small mb-1">
                        Type <code>{{ $import->original_filename }}</code> to confirm
                    </label>
                    <input type="text" name="confirm" class="form-control form-control-sm"
                           autocomplete="off" required>
                </div>
                <div class="col-sm-4">
                    <button class="btn btn-danger btn-sm w-100" type="submit">
                        Create {{ number_format($import->valid_rows) }} spots
                    </button>
                </div>
            </form>
        @else
            <p class="text-muted mb-2">Not ready to import:</p>
            <ul class="small text-muted">
                @if($import->status !== \App\Models\PartnerImport::STATUS_VALIDATED)
                    <li>{{ number_format($import->error_rows) }} row(s) still have errors. Fix the file and upload it again.</li>
                @endif
                @if($unlinked > 0)
                    <li>{{ $unlinked }} leg(s) are not connected to anyone in our system.</li>
                @endif
            </ul>
            <form method="POST" action="{{ route('admin.partners.imports.revalidate', $import) }}">
                @csrf
                <button class="btn btn-outline-secondary btn-sm" type="submit">Re-check</button>
            </form>
        @endif
    </div>
</div>
@else
<div class="alert alert-success">
    <div class="fw-bold">Imported {{ number_format($import->committed_rows) }} spots
        on {{ $import->committed_at?->format('M j, Y g:ia') }}.</div>
    <div class="small mt-1">
        Activation codes were hashed and the plaintext removed from staging. If somebody loses
        theirs, issue a replacement from the
        <a href="{{ route('admin.partners.spots', ['company' => $import->partner_company_id]) }}">spots board</a>.
    </div>
</div>
@endif

{{-- ── Every row ─────────────────────────────────────────────────────────── --}}
<div class="card">
    <div class="card-header py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h6 class="mb-0 fw-bold">Staged rows</h6>
        <div class="btn-group btn-group-sm">
            @foreach(['' => 'All', 'errors' => 'Errors', 'warnings' => 'Warnings', 'top' => 'Leg tops'] as $key => $label)
                <a href="{{ route('admin.partners.imports.show', [$import, 'filter' => $key ?: null]) }}"
                   class="btn btn-outline-secondary {{ $filter === ($key ?: null) ? 'active' : '' }}">{{ $label }}</a>
            @endforeach
        </div>
    </div>

    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead><tr>
                <th>Line</th><th>Partner ID</th><th>Parent</th><th>Sponsor</th><th>Status</th>
            </tr></thead>
            <tbody>
            @forelse($rows as $row)
                <tr class="{{ $row->status === \App\Models\PartnerImportRow::STATUS_INVALID ? 'table-danger' : '' }}">
                    <td class="small text-muted">{{ $row->line_number }}</td>
                    <td style="font-family:var(--bs-font-monospace,monospace);">{{ $row->external_user_id }}</td>
                    <td class="small" style="font-family:var(--bs-font-monospace,monospace);">
                        {{ $row->external_parent_id ?: '—' }}
                    </td>
                    <td class="small" style="font-family:var(--bs-font-monospace,monospace);">
                        {{ $row->external_sponsor_id ?: '—' }}
                    </td>
                    <td>
                        @if($row->status === \App\Models\PartnerImportRow::STATUS_COMMITTED)
                            <span class="badge bg-success">Imported</span>
                        @elseif($row->status === \App\Models\PartnerImportRow::STATUS_INVALID)
                            <span class="badge bg-danger">Error</span>
                        @elseif($row->status === \App\Models\PartnerImportRow::STATUS_VALID)
                            <span class="badge bg-secondary">Ready</span>
                        @else
                            <span class="badge bg-light text-dark">Staged</span>
                        @endif

                        @foreach($row->errors ?? [] as $error)
                            <div class="small text-danger mt-1">{{ $error }}</div>
                        @endforeach
                        @foreach($row->warnings ?? [] as $warning)
                            <div class="small text-warning mt-1">{{ $warning }}</div>
                        @endforeach
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="text-center text-muted py-4">Nothing matches this filter.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <div class="card-body">{{ $rows->links() }}</div>
</div>

@endsection
