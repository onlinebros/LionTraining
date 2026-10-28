{{-- Claims: the assistant's finds waiting for review, and the approved set
     that may reach prospects. Quotes are never edited: approve or reject. --}}
@php
    $draft = $claims->get('draft', collect());
    $approved = $claims->get('approved', collect());
    $rejected = $claims->get('rejected', collect());
@endphp
<div class="row g-3">
    <div class="col-xl-7">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-bold">Waiting for review ({{ $draft->count() }})</h6>
            </div>
            <div class="card-body">
                <p class="small f-light">Found by the assistant, and checked to appear word for word in the document. Approve the ones we may show prospects; approved claims are the only results the assistant may state to a prospect. If wording is wrong, reject it: a claim is never reworded.</p>
                @if($draft->isEmpty())
                    <p class="small f-light mb-0">Nothing waiting. Use "Find claims" on a document in the Library tab.</p>
                @else
                    <form method="POST" action="{{ route('admin.proposal-studio.offerings.claims.review-many', $offering) }}" id="claims-form">
                        @csrf
                        @foreach($draft->groupBy('offering_document_id') as $docClaims)
                            <h6 class="small fw-bold mt-3">{{ $docClaims->first()->document->title ?? '' }}</h6>
                            @foreach($docClaims as $claim)
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="checkbox" name="claim_ids[]" value="{{ $claim->id }}" id="dc-{{ $claim->id }}">
                                    <label class="form-check-label small" for="dc-{{ $claim->id }}">
                                        @if($claim->topic)<span class="badge badge-light-primary">{{ $claim->topic }}</span> @endif
                                        "{{ $claim->quote }}"
                                        @if($claim->footnote)<span class="d-block f-light">Footnote: {{ $claim->footnote }}</span>@endif
                                    </label>
                                </div>
                            @endforeach
                        @endforeach
                        <div class="d-flex gap-2 mt-3 flex-wrap">
                            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="document.querySelectorAll('#claims-form input[type=checkbox]').forEach(function (c) { c.checked = true; })">Select all</button>
                            <button class="btn btn-success btn-sm" name="status" value="approved">Approve selected</button>
                            <button class="btn btn-outline-danger btn-sm" name="status" value="rejected">Reject selected</button>
                        </div>
                    </form>
                @endif
            </div>
        </div>
    </div>
    <div class="col-xl-5">
        <div class="card">
            <div class="card-header"><h6 class="mb-0 fw-bold">Approved ({{ $approved->count() }})</h6></div>
            <div class="card-body" style="max-height: 70vh; overflow: auto;">
                @forelse($approved as $claim)
                    <div class="small border-bottom py-2">
                        <span class="f-light">Claim {{ $claim->id }}@if($claim->topic) · {{ $claim->topic }}@endif · {{ $claim->document->title ?? '' }}</span>
                        <div>"{{ $claim->quote }}"</div>
                        @if($claim->footnote)<div class="f-light">Footnote: {{ $claim->footnote }}</div>@endif
                        <form method="POST" action="{{ route('admin.proposal-studio.offerings.claims.review', [$offering, $claim]) }}" class="d-inline">
                            @csrf @method('PATCH') <input type="hidden" name="status" value="rejected">
                            <button class="btn btn-link btn-sm p-0 text-danger">Withdraw</button>
                        </form>
                        <span class="f-light">· approved by {{ $claim->reviewer?->name ?? 'an admin' }}</span>
                    </div>
                @empty
                    <p class="small f-light mb-0">None approved yet. Until some are, proposals show no results and the assistant states none to prospects.</p>
                @endforelse
            </div>
        </div>
        @if($rejected->isNotEmpty())
            <details class="small">
                <summary class="fw-bold">Rejected ({{ $rejected->count() }})</summary>
                @foreach($rejected as $claim)
                    <div class="py-1">"{{ $claim->quote }}"
                        <form method="POST" action="{{ route('admin.proposal-studio.offerings.claims.review', [$offering, $claim]) }}" class="d-inline">
                            @csrf @method('PATCH') <input type="hidden" name="status" value="draft">
                            <button class="btn btn-link btn-sm p-0">Review again</button>
                        </form>
                    </div>
                @endforeach
            </details>
        @endif
    </div>
</div>
