@extends('layouts.admin')

@section('title', $changeRequest->number)
@section('page-title', 'Review Request')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('admin.review-requests.index') }}">Review Requests</a></li>
    <li class="breadcrumb-item active">{{ $changeRequest->number }}</li>
@endsection

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

        {{-- Plan and decision --}}
        <div class="col-xl-6 order-xl-2">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h6 class="mb-0 fw-bold">Decision</h6>
                    <span class="badge {{ $changeRequest->statusBadgeClass() }}">{{ $changeRequest->statusLabel() }}</span>
                </div>
                <div class="card-body">
                    <dl class="row small mb-3">
                        <dt class="col-4 f-light">From</dt>
                        <dd class="col-8">{{ $changeRequest->user?->name }} <span class="f-light">{{ $changeRequest->user?->email }}</span></dd>
                        @if($changeRequest->source_url)
                            <dt class="col-4 f-light">Started from</dt>
                            <dd class="col-8">
                                <a href="{{ $changeRequest->source_url }}" target="_blank" rel="noopener noreferrer">{{ $changeRequest->source_title ?: parse_url($changeRequest->source_url, PHP_URL_PATH) }}</a>
                                <span class="f-light d-block" style="font-size:.75rem;">{{ $changeRequest->source_url }}</span>
                            </dd>
                        @endif
                        <dt class="col-4 f-light">Opened</dt>
                        <dd class="col-8">{{ $changeRequest->created_at->format('M j, Y g:i A') }}</dd>
                        @if($changeRequest->submitted_at)
                            <dt class="col-4 f-light">Submitted</dt>
                            <dd class="col-8">{{ $changeRequest->submitted_at->format('M j, Y g:i A') }}</dd>
                        @endif
                        @if($changeRequest->reviewed_at)
                            <dt class="col-4 f-light">Last decision</dt>
                            <dd class="col-8">{{ $changeRequest->reviewerLabel() }}, {{ $changeRequest->reviewed_at->diffForHumans() }}</dd>
                        @endif
                        @if($changeRequest->snapshot_commit)
                            <dt class="col-4 f-light">Code read at</dt>
                            <dd class="col-8"><code>{{ substr($changeRequest->snapshot_commit, 0, 10) }}</code></dd>
                        @endif
                        <dt class="col-4 f-light">Assistant usage</dt>
                        <dd class="col-8">${{ number_format($cost, 2) }} <span class="f-light">at API prices. Not billed: it runs on the Claude subscription on dev.</span></dd>
                    </dl>

                    @if(auth()->user()->isSuperAdmin())
                    <form method="POST" action="{{ route('admin.review-requests.review', $changeRequest->id) }}">
                        @csrf @method('PATCH')
                        <div class="mb-2">
                            <select name="status" class="form-select form-select-sm" required>
                                @foreach(\App\Models\ChangeRequest::REVIEW_STATUSES as $s)
                                    <option value="{{ $s }}" @selected($changeRequest->status === $s)>{{ \App\Models\ChangeRequest::STATUSES[$s] }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="mb-2">
                            <textarea name="review_notes" rows="3" class="form-control form-control-sm"
                                      placeholder="Notes the requester will see (why, what's next, what's missing)">{{ old('review_notes', $changeRequest->review_notes) }}</textarea>
                        </div>
                        <div class="d-flex gap-2">
                            <button class="btn btn-primary btn-sm">Save decision</button>
                            <a href="{{ route('admin.review-requests.export', $changeRequest->id) }}" class="btn btn-outline-secondary btn-sm">Download as Markdown</a>
                        </div>
                        <p class="small f-light mt-2 mb-0">Saved on {{ $upstream }}, where the requester sees it. Approving records the decision only. To build it, hand the downloaded plan to whoever implements it.</p>
                    </form>
                    @else
                        <a href="{{ route('admin.review-requests.export', $changeRequest->id) }}" class="btn btn-outline-secondary btn-sm">Download as Markdown</a>
                        <p class="small f-light mt-2 mb-0">Decisions are made by a super admin. You can comment in the conversation.</p>
                    @endif
                </div>
            </div>

            <div class="card">
                <div class="card-header"><h6 class="mb-0 fw-bold">Plan</h6></div>
                <div class="card-body">
                    @include('partials.request-plan')
                </div>
            </div>
        </div>

        {{-- Conversation --}}
        <div class="col-xl-6 order-xl-1">
            <div class="card">
                <div class="card-header"><h6 class="mb-0 fw-bold">{{ $changeRequest->title }}</h6></div>
                <div class="card-body">
                    @include('partials.request-thread', ['showMeta' => true])

                    <hr>
                    <form method="POST" action="{{ route('admin.review-requests.comment', $changeRequest->id) }}">
                        @csrf
                        <div class="mb-2">
                            <textarea name="body" rows="3" maxlength="10000" required class="form-control form-control-sm"
                                      placeholder="Comment to the requester. The assistant sees it too on its next reply."></textarea>
                        </div>
                        <button class="btn btn-outline-primary btn-sm">Add comment</button>
                    </form>
                </div>
            </div>
        </div>

    </div>
</div>
@endsection
