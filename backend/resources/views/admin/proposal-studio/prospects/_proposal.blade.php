{{-- The proposal page: versions, the vendor's review, publishing. --}}
@php
    $current = $prospect->proposals->first();
    $published = $prospect->publishedProposal;
    $draftingProposal = $job?->kind === 'proposal_draft';
@endphp
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h6 class="mb-0 fw-bold">Proposal page</h6>
        @if(! $current || $current->status !== 'draft' || $current->content === null)
            <form method="POST" action="{{ route('admin.proposal-studio.proposals.draft', $prospect) }}">
                @csrf
                <button class="btn btn-outline-primary btn-sm" @disabled($busy)>{{ $current ? 'Draft a new version' : 'Draft the proposal page' }}</button>
            </form>
        @endif
    </div>
    <div class="card-body">
        @if($prospect->proposals->isEmpty())
            <p class="f-light small mb-0">The page the prospect reads in their portal: what we understood, the recommendation, how it works, and approved claims word for word. When discovery is far enough along, ask the assistant to draft it.</p>
        @else
            @foreach($prospect->proposals->take(4) as $proposal)
                <div class="d-flex justify-content-between align-items-start gap-2 py-2 @if(!$loop->last) border-bottom @endif">
                    <div class="small">
                        <strong>Version {{ $proposal->version }}</strong>
                        <span class="badge {{ match($proposal->status) { 'published' => 'badge-light-success', 'in_review' => 'badge-light-warning', 'draft' => 'badge-light-primary', default => 'badge-light-secondary' } }}">{{ $proposal->statusLabel() }}</span>
                        @if($proposal->status === 'draft' && $proposal->content === null)<span class="f-light">being drafted</span>@endif
                        @if($proposal->vendor_review === 'changes_requested')
                            <div class="alert alert-warning small mt-2 mb-0"><strong>{{ $prospect->offering->vendor_name }} asked for changes</strong>@if($proposal->vendor_review_note): {!! nl2br(e($proposal->vendor_review_note)) !!}@endif</div>
                        @elseif($proposal->vendor_review === 'approved')
                            <div class="f-light">Approved by {{ $proposal->vendorReviewer?->name ?? $prospect->offering->vendor_name }} {{ $proposal->vendor_reviewed_at?->diffForHumans() }}</div>
                        @endif
                        @if($proposal->published_at)<div class="f-light">Published {{ $proposal->published_at->diffForHumans() }}</div>@endif
                    </div>
                    <div class="d-flex gap-1 flex-wrap justify-content-end">
                        @if($proposal->content !== null)
                            <a class="btn btn-outline-secondary btn-sm" href="{{ route('admin.proposal-studio.proposals.preview', [$prospect, $proposal]) }}" target="_blank" rel="noopener">Preview</a>
                        @endif
                        @if($proposal->status === 'draft' && $proposal->content !== null)
                            <a class="btn btn-primary btn-sm" href="{{ route('admin.proposal-studio.proposals.edit', [$prospect, $proposal]) }}">Edit and publish</a>
                        @elseif($proposal->status === 'published')
                            <form method="POST" action="{{ route('admin.proposal-studio.proposals.new-version', [$prospect, $proposal]) }}">
                                @csrf <button class="btn btn-outline-primary btn-sm">Edit as new version</button>
                            </form>
                        @endif
                    </div>
                </div>
            @endforeach
        @endif
    </div>
</div>
