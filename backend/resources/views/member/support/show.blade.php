@extends('layouts.member')

@section('title', $ticket->ticket_number)
@section('page-title', 'Support Ticket')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('member.support.index') }}">Support</a></li>
    <li class="breadcrumb-item active">{{ $ticket->ticket_number }}</li>
@endsection

@section('content')

@if(session('success'))
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        {{ session('success') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
@endif

<div class="row g-3">

    {{-- Ticket meta --}}
    <div class="col-xl-4 order-xl-2">
        <div class="card">
            <div class="card-header"><h6 class="mb-0 fw-bold">Ticket Details</h6></div>
            <div class="card-body">
                <dl class="row mb-0 small">
                    <dt class="col-5 f-light">Ticket #</dt>
                    <dd class="col-7"><code>{{ $ticket->ticket_number }}</code></dd>

                    <dt class="col-5 f-light">Status</dt>
                    <dd class="col-7">
                        <span class="badge {{ $ticket->statusBadgeClass() }}">
                            {{ \App\Models\SupportTicket::STATUSES[$ticket->status] ?? ucfirst($ticket->status) }}
                        </span>
                    </dd>

                    <dt class="col-5 f-light">Category</dt>
                    <dd class="col-7">{{ \App\Models\SupportTicket::CATEGORIES[$ticket->category] ?? ucfirst($ticket->category) }}</dd>

                    <dt class="col-5 f-light">Priority</dt>
                    <dd class="col-7">
                        <span class="badge {{ $ticket->priorityBadgeClass() }}">{{ ucfirst($ticket->priority) }}</span>
                    </dd>

                    <dt class="col-5 f-light">Submitted</dt>
                    <dd class="col-7">{{ $ticket->created_at->format('M j, Y g:i A') }}</dd>

                    @if($ticket->isClosed())
                    <dt class="col-5 f-light">Closed</dt>
                    <dd class="col-7">{{ $ticket->closed_at?->format('M j, Y g:i A') ?? '—' }}</dd>
                    @endif

                    @if($ticket->source_url)
                    <dt class="col-5 f-light">Page</dt>
                    <dd class="col-7">
                        <a href="{{ $ticket->source_url }}" target="_blank" rel="noopener"
                           class="text-truncate d-block" style="max-width:160px;" title="{{ $ticket->source_url }}">
                            {{ parse_url($ticket->source_url, PHP_URL_PATH) ?: $ticket->source_url }}
                        </a>
                    </dd>
                    @endif
                </dl>
            </div>
        </div>
    </div>

    {{-- Thread --}}
    <div class="col-xl-8 order-xl-1">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <div>
                    <h6 class="mb-0 fw-bold">{{ $ticket->subject }}</h6>
                </div>
            </div>
            <div class="card-body">

                {{-- Reply thread --}}
                @foreach($ticket->replies as $reply)
                    @php $isOwn = $reply->user_id === auth()->id(); @endphp
                    <div class="d-flex gap-3 mb-4">
                        <div style="width:38px;height:38px;border-radius:50%;flex-shrink:0;
                                    background:{{ $isOwn ? 'var(--theme-default)' : '#54ba4a' }};
                                    color:#fff;display:flex;align-items:center;justify-content:center;
                                    font-weight:700;font-size:.85rem;">
                            {{ strtoupper(substr($reply->user->name ?? '?', 0, 1)) }}
                        </div>
                        <div class="flex-grow-1">
                            <div class="d-flex align-items-center gap-2 mb-1">
                                <span class="fw-semibold small">{{ $reply->user->name ?? 'Unknown' }}</span>
                                @if(!$isOwn)
                                    <span class="badge badge-light-success" style="font-size:.7rem;">Support</span>
                                @endif
                                <span class="f-light" style="font-size:.75rem;">{{ $reply->created_at->diffForHumans() }}</span>
                            </div>
                            <div class="p-3 rounded" style="background:{{ $isOwn ? '#f8f9fa' : '#f0fff4' }};border:1px solid {{ $isOwn ? '#e9ecef' : '#c6f6d5' }};">
                                {!! nl2br(e($reply->body)) !!}
                            </div>
                        </div>
                    </div>
                @endforeach

                {{-- Reply form --}}
                @if(!$ticket->isClosed())
                    <hr>
                    <h6 class="fw-semibold mb-3">Add Reply</h6>
                    <form method="POST" action="{{ route('member.support.reply', $ticket) }}">
                        @csrf
                        <div class="mb-3">
                            <textarea class="form-control @error('body') is-invalid @enderror"
                                      name="body" rows="4"
                                      placeholder="Type your reply…" required>{{ old('body') }}</textarea>
                            @error('body')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <button type="submit" class="btn btn-primary btn-sm">
                            <i data-feather="send" style="width:13px;height:13px;"></i> Send Reply
                        </button>
                    </form>
                @else
                    <div class="alert alert-secondary mt-3 mb-0 small text-center">
                        This ticket has been closed. <a href="{{ route('member.support.create') }}">Open a new ticket</a> if you need further help.
                    </div>
                @endif

            </div>
        </div>
    </div>

</div>

@endsection
