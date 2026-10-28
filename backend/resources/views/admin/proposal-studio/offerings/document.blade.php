@extends('layouts.admin')

@section('title', $document->title)
@section('page-title', $document->title)

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('admin.proposal-studio.prospects.index') }}">Proposal Studio</a></li>
    <li class="breadcrumb-item"><a href="{{ route('admin.proposal-studio.offerings.show', $offering) }}">{{ $offering->name }}</a></li>
    <li class="breadcrumb-item active">Doc {{ $document->id }}</li>
@endsection

@section('content')
<div class="container-fluid">
    @include('admin.proposal-studio._nav')

    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h6 class="mb-0 fw-bold">{{ $document->title }}</h6>
                <div class="small f-light">
                    {{ $document->kindLabel() }} · {{ \App\Models\OfferingDocument::AUDIENCES[$document->audience] ?? $document->audience }}
                    · {{ $document->use_for_ai ? 'The assistant reads it' : 'The assistant does not read it' }}
                    @if($document->source_url) · <a href="{{ $document->source_url }}" rel="noopener noreferrer" target="_blank">source</a>@endif
                </div>
            </div>
            @if($document->file_path)
                <a class="btn btn-outline-secondary btn-sm" href="{{ route('admin.proposal-studio.offerings.documents.download', [$offering, $document]) }}">Download {{ $document->original_name }}</a>
            @endif
        </div>
        <div class="card-body">
            <p class="small f-light">This is the text the assistant reads, as extracted. If a table or figure came out garbled, paste a clean version in as a separate document.</p>
            @if($document->extraction_error)
                <div class="alert alert-danger small">{{ $document->extraction_error }}</div>
            @endif
            <pre class="small" style="white-space:pre-wrap; max-height:75vh; overflow:auto;">{{ $document->extracted_text }}</pre>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-xl-6">
            <div class="card">
                <div class="card-header"><h6 class="mb-0 fw-bold">Add a claim from this document</h6></div>
                <div class="card-body">
                    <p class="small f-light">Copy the wording from the text above, exactly. It is checked against the document and approved straight away.</p>
                    <form method="POST" action="{{ route('admin.proposal-studio.offerings.documents.claims.store', [$offering, $document]) }}">
                        @csrf
                        <div class="mb-2"><input name="topic" class="form-control form-control-sm" maxlength="120" placeholder="Topic, e.g. Mold reduction" value="{{ old('topic') }}" aria-label="Topic"></div>
                        <div class="mb-2"><textarea name="quote" rows="3" class="form-control form-control-sm" required maxlength="3000" placeholder="The claim, word for word" aria-label="Claim">{{ old('quote') }}</textarea></div>
                        <div class="mb-2"><textarea name="footnote" rows="2" class="form-control form-control-sm" maxlength="3000" placeholder="Its footnote or test reference, word for word (optional)" aria-label="Footnote">{{ old('footnote') }}</textarea></div>
                        <button class="btn btn-primary btn-sm">Add claim</button>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-xl-6">
            <div class="card">
                <div class="card-header"><h6 class="mb-0 fw-bold">Claims from this document ({{ $document->claims->count() }})</h6></div>
                <div class="card-body">
                    @forelse($document->claims as $claim)
                        <div class="small py-1 border-bottom"><span class="badge {{ $claim->status === 'approved' ? 'badge-light-success' : ($claim->status === 'draft' ? 'badge-light-warning' : 'badge-light-secondary') }}">{{ \App\Models\KnowledgeClaim::STATUSES[$claim->status] }}</span> "{{ $claim->quote }}"</div>
                    @empty
                        <p class="small f-light mb-0">None yet.</p>
                    @endforelse
                </div>
            </div>
            <form method="POST" action="{{ route('admin.proposal-studio.offerings.documents.destroy', [$offering, $document]) }}" onsubmit="return confirm('Remove this document and every claim drawn from it?');">
                @csrf @method('DELETE')
                <button class="btn btn-outline-danger btn-sm">Remove this document</button>
            </form>
        </div>
    </div>
</div>
@endsection
