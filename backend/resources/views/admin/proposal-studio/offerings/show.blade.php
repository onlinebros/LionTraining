@extends('layouts.admin')

@section('title', $offering->name)
@section('page-title', $offering->name)

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('admin.proposal-studio.prospects.index') }}">Proposal Studio</a></li>
    <li class="breadcrumb-item"><a href="{{ route('admin.proposal-studio.offerings.index') }}">Offerings</a></li>
    <li class="breadcrumb-item active">{{ $offering->name }}</li>
@endsection

@php
    $draftClaims = $claims->get('draft', collect());
    $tabs = [
        'library' => 'Library ('.$offering->documents->count().')',
        'claims' => 'Claims'.($draftClaims->isNotEmpty() ? ' · '.$draftClaims->count().' to review' : ''),
        'ask' => 'Ask the library',
        'gaps' => 'Prospect questions',
        'settings' => 'Settings',
    ];
    $tab = array_key_exists($tab, $tabs) ? $tab : 'library';
    $md = fn (?string $text) => \Illuminate\Support\Str::markdown((string) $text, ['html_input' => 'escape', 'allow_unsafe_links' => false]);
@endphp

@section('content')
<div class="container-fluid">
    @include('admin.proposal-studio._nav')

    <ul class="nav nav-tabs mb-3">
        @foreach($tabs as $key => $label)
            <li class="nav-item"><a class="nav-link {{ $tab === $key ? 'active' : '' }}" href="{{ route('admin.proposal-studio.offerings.show', [$offering, 'tab' => $key]) }}">{{ $label }}</a></li>
        @endforeach
    </ul>

    @if($tab === 'library')
    <div class="row g-3">
        <div class="col-xl-8">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <h6 class="mb-0 fw-bold">Documents</h6>
                    <span class="small f-light">
                        {{ number_format($aiChars) }} characters the assistant reads.
                        {{ $aiChars > $contextChars ? 'More than fits in one prompt, so it searches for the passages that match.' : 'All of it goes into every prompt.' }}
                    </span>
                </div>
                <div class="card-body p-0">
                    @if($offering->documents->isEmpty())
                        <p class="f-light small p-4 mb-0">No documents yet. Until there are, the assistant makes no product claims and lists every product question for {{ $offering->vendor_name }}.</p>
                    @else
                    <div class="table-responsive">
                        <table class="table mb-0">
                            <thead><tr><th>Document</th><th>Text</th><th>Claims</th><th>Prospects may see it</th><th>Assistant reads it</th></tr></thead>
                            <tbody>
                            @foreach($offering->documents as $document)
                                @php $count = $claims->flatten()->where('offering_document_id', $document->id)->count(); @endphp
                                <tr>
                                    <td>
                                        <a href="{{ route('admin.proposal-studio.offerings.documents.show', [$offering, $document]) }}">{{ $document->title }}</a>
                                        <div class="f-light" style="font-size:.75rem;">Doc {{ $document->id }} · {{ $document->kindLabel() }}@if($document->read_on) · read on {{ $document->read_on->format('M j, Y') }}@endif</div>
                                    </td>
                                    <td class="small text-nowrap">
                                        @if($document->extraction_error)
                                            <span class="badge badge-light-danger" title="{{ $document->extraction_error }}">No text</span>
                                        @else
                                            {{ number_format($document->text_chars) }}
                                        @endif
                                    </td>
                                    <td class="small text-nowrap">
                                        @if(in_array($document->id, $readingDocs, true))
                                            <span class="spinner-border spinner-border-sm" role="status"></span> reading
                                        @else
                                            {{ $count ?: '' }}
                                            @if($document->text_chars > 0 && ! in_array($document->kind, ['training', 'notes'], true))
                                                <form method="POST" action="{{ route('admin.proposal-studio.offerings.documents.extract', [$offering, $document]) }}" class="d-inline">
                                                    @csrf <button class="btn btn-link btn-sm p-0">{{ $count ? 'Find more' : 'Find claims' }}</button>
                                                </form>
                                            @endif
                                        @endif
                                    </td>
                                    <td>
                                        <form method="POST" action="{{ route('admin.proposal-studio.offerings.documents.update', [$offering, $document]) }}">
                                            @csrf @method('PATCH')
                                            <input type="hidden" name="audience" value="{{ $document->audience === 'shareable' ? 'internal' : 'shareable' }}">
                                            <button class="btn btn-sm {{ $document->audience === 'shareable' ? 'btn-light-success' : 'btn-outline-secondary' }}" title="Click to change">{{ $document->audience === 'shareable' ? 'Yes' : 'Internal' }}</button>
                                        </form>
                                    </td>
                                    <td>
                                        <form method="POST" action="{{ route('admin.proposal-studio.offerings.documents.update', [$offering, $document]) }}">
                                            @csrf @method('PATCH')
                                            <input type="hidden" name="use_for_ai" value="{{ $document->use_for_ai ? 0 : 1 }}">
                                            <button class="btn btn-sm {{ $document->use_for_ai ? 'btn-light-success' : 'btn-outline-secondary' }}" title="Click to change">{{ $document->use_for_ai ? 'Yes' : 'No' }}</button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                    @endif
                </div>
            </div>
        </div>
        <div class="col-xl-4">
            @include('admin.proposal-studio.offerings._add-document')
        </div>
    </div>
    @endif

    @if($tab === 'claims')
        @include('admin.proposal-studio.offerings._claims')
    @endif

    @if($tab === 'ask')
    <div class="row g-3">
        <div class="col-xl-8">
            <div class="card">
                <div class="card-body">
                    <p class="small f-light">Ask what the library knows: "What would a 4-chair dental office need?", "What does the testing say about mold?", "How do we answer a question about ozone?" Answers cite their documents and say when the library does not know.</p>
                    <form method="POST" action="{{ route('admin.proposal-studio.offerings.ask', $offering) }}">
                        @csrf
                        <textarea name="question" rows="3" class="form-control mb-2" maxlength="3000" required placeholder="Your question">{{ old('question') }}</textarea>
                        <button class="btn btn-primary btn-sm">Ask</button>
                    </form>
                </div>
            </div>
            @foreach($questions as $q)
                <div class="card">
                    <div class="card-body">
                        <div class="small f-light mb-1">{{ $q->user?->name }}, {{ $q->created_at->diffForHumans() }}</div>
                        <p class="fw-semibold">{{ $q->question }}</p>
                        @if($q->answer)
                            <div class="ps-md small">{!! $md($q->answer) !!}</div>
                        @else
                            <div class="small f-light"><span class="spinner-border spinner-border-sm me-1" role="status"></span>The assistant is reading the library…</div>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    </div>
    @endif

    @if($tab === 'gaps')
    <div class="card">
        <div class="card-header"><h6 class="mb-0 fw-bold">Questions the assistant could not answer</h6></div>
        <div class="card-body">
            <p class="small f-light">Questions prospects asked in their portals that the approved material did not answer. Each one is a gap: add a document or approve a claim that covers it, and the assistant can answer it next time.</p>
            @forelse($gaps as $g)
                <div class="border-bottom py-2 small">
                    <a href="{{ route('admin.proposal-studio.prospects.show', $g->prospect) }}">{{ $g->prospect->company_name }}</a>
                    <span class="f-light">· {{ $g->created_at->diffForHumans() }} · {{ $g->answered_by === 'pending' ? 'waiting for an answer' : 'answered by an admin' }}</span>
                    <div class="fw-semibold">{{ $g->body }}</div>
                    @if($g->answer)<div class="f-light">{{ \Illuminate\Support\Str::limit($g->answer, 300) }}</div>@endif
                </div>
            @empty
                <p class="small f-light mb-0">None yet.</p>
            @endforelse
        </div>
    </div>
    @endif

    @if($tab === 'settings')
        @include('admin.proposal-studio.offerings._settings')
    @endif
</div>

@if($busy)
@push('scripts')
<script>
    (function poll() {
        setTimeout(function () {
            fetch(@json(route('admin.proposal-studio.offerings.status', $offering)), { headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.json(); })
                .then(function (s) { if (s.busy) { poll(); } else { window.location.reload(); } })
                .catch(poll);
        }, 5000);
    })();
</script>
@endpush
@endif
@push('styles')
<style>.ps-md > :last-child { margin-bottom: 0; } .ps-md h1,.ps-md h2,.ps-md h3 { font-size: 1rem; font-weight: 700; margin-top: 1rem; }</style>
@endpush
@endsection
