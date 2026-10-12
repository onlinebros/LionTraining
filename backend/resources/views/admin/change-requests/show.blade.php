@extends('layouts.admin')

@section('title', $changeRequest->number)
@section('page-title', 'Change Request')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('admin.change-requests.index') }}">Change Requests</a></li>
    <li class="breadcrumb-item active">{{ $changeRequest->number }}</li>
@endsection

@php
    $busy = $changeRequest->isAssistantBusy();
    $mine = $changeRequest->user_id === auth()->id();
    $open = $mine && $changeRequest->isOpenForConversation();
    $canSubmit = $mine && in_array($changeRequest->status, ['drafting', 'needs_info'], true) && filled($changeRequest->plan);
@endphp

@section('content')
<div class="container-fluid">

    @foreach(['success' => 'success', 'error' => 'danger'] as $flash => $class)
        @if(session($flash))
            <div class="alert alert-{{ $class }} alert-dismissible fade show" role="alert">
                {{ session($flash) }}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        @endif
    @endforeach

<div class="row g-3">

    {{-- Plan and status --}}
    <div class="col-xl-5 order-xl-2">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-bold">Plan</h6>
                <span class="badge {{ $changeRequest->statusBadgeClass() }}">{{ $changeRequest->statusLabel() }}</span>
            </div>
            <div class="card-body">
                <p class="small f-light">
                    From {{ $changeRequest->user?->name }}, {{ $changeRequest->created_at->format('M j, Y') }}.
                    @if($changeRequest->source_url)
                        Started from <a href="{{ $changeRequest->source_url }}" rel="noopener noreferrer">{{ $changeRequest->source_title ?: parse_url($changeRequest->source_url, PHP_URL_PATH) }}</a>.
                    @endif
                </p>

                @if($changeRequest->reviewed_at)
                    <div class="alert alert-secondary small">
                        <strong>{{ $changeRequest->statusLabel() }}</strong>
                        by {{ $changeRequest->reviewerLabel() ?? 'the team' }}, {{ $changeRequest->reviewed_at->diffForHumans() }}.
                        @if($changeRequest->review_notes)<br>{!! nl2br(e($changeRequest->review_notes)) !!}@endif
                    </div>
                @endif

                @include('partials.request-plan')

                @if($open)
                    <div class="d-flex gap-2 flex-wrap mt-3">
                        <form method="POST" action="{{ route('admin.change-requests.plan', $changeRequest) }}">
                            @csrf
                            <button class="btn btn-outline-primary btn-sm" @disabled($busy)>
                                {{ $changeRequest->plan ? 'Redraft the plan' : 'Draft the plan' }}
                            </button>
                        </form>
                        @if($canSubmit)
                            <form method="POST" action="{{ route('admin.change-requests.submit', $changeRequest) }}">
                                @csrf
                                <button class="btn btn-primary btn-sm" @disabled($busy)>Submit for review</button>
                            </form>
                        @endif
                    </div>
                @endif
                @if($changeRequest->status === 'submitted')
                    <p class="small f-light mt-2 mb-0">Submitted {{ $changeRequest->submitted_at?->diffForHumans() }}. It is reviewed and decided on the dev server.</p>
                @endif
            </div>
        </div>
    </div>

    {{-- Conversation --}}
    <div class="col-xl-7 order-xl-1">
        <div class="card">
            <div class="card-header">
                <h6 class="mb-0 fw-bold">{{ $changeRequest->title }}</h6>
            </div>
            <div class="card-body">
                @include('partials.request-thread')

                @if($open)
                    <hr>
                    <form method="POST" action="{{ route('admin.change-requests.message', $changeRequest) }}">
                        @csrf
                        <div class="mb-2">
                            <textarea class="form-control @error('body') is-invalid @enderror" name="body" rows="3" maxlength="10000" required
                                      placeholder="{{ $busy ? 'Waiting for the assistant…' : 'Reply to the assistant…' }}" @disabled($busy)>{{ old('_ra_modal') ? '' : old('body') }}</textarea>
                            @error('body')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <button type="submit" class="btn btn-primary btn-sm" @disabled($busy)>Send</button>
                    </form>
                @elseif(! $mine)
                    <div class="alert alert-secondary mt-3 mb-0 small text-center">
                        This is {{ $changeRequest->user?->name }}'s request. Only they can continue the conversation.
                    </div>
                @else
                    <div class="alert alert-secondary mt-3 mb-0 small text-center">
                        This request has been decided, so the conversation is closed.
                        <a href="{{ route('admin.change-requests.index') }}">Start a new request</a> for anything further.
                    </div>
                @endif
            </div>
        </div>
    </div>

</div>
</div>

@if($busy)
@push('scripts')
<script>
    // Reload once the assistant's reply has landed.
    (function poll() {
        setTimeout(function () {
            fetch(@json(route('admin.change-requests.status', $changeRequest)), { headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.json(); })
                .then(function (s) { if (s.busy) { poll(); } else { window.location.reload(); } })
                .catch(poll);
        }, 4000);
    })();
</script>
@endpush
@endif
@endsection
