@extends('layouts.admin')

@section('title', $ticket->ticket_number)
@section('page-title', 'Support Ticket')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('admin.support.index') }}">Support Tickets</a></li>
    <li class="breadcrumb-item active">{{ $ticket->ticket_number }}</li>
@endsection

@section('content')
<div class="container-fluid">

    @if(session('success'))
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    <div class="row g-3">

        {{-- Sidebar meta --}}
        <div class="col-xl-3 order-xl-2">

            {{-- Ticket details --}}
            <div class="card mb-3">
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

                        @if($ticket->closed_at)
                        <dt class="col-5 f-light">Closed</dt>
                        <dd class="col-7">{{ $ticket->closed_at->format('M j, Y g:i A') }}</dd>
                        @endif
                    </dl>
                </div>
            </div>

            {{-- Source URL (page context) --}}
            @if($ticket->source_url)
            <div class="card mb-3">
                <div class="card-header"><h6 class="mb-0 fw-bold">Page Context</h6></div>
                <div class="card-body">
                    <p class="f-light small mb-2">Member submitted this ticket from:</p>
                    <a href="{{ $ticket->source_url }}" target="_blank" rel="noopener"
                       class="btn btn-sm btn-outline-secondary w-100 text-truncate" title="{{ $ticket->source_url }}">
                        <i data-feather="external-link" style="width:13px;height:13px;"></i>
                        {{ parse_url($ticket->source_url, PHP_URL_PATH) ?: $ticket->source_url }}
                    </a>
                    <p class="f-light mt-2 mb-0" style="font-size:.7rem;word-break:break-all;">{{ $ticket->source_url }}</p>
                </div>
            </div>
            @endif

            {{-- Member info --}}
            <div class="card mb-3">
                <div class="card-header"><h6 class="mb-0 fw-bold">Member</h6></div>
                <div class="card-body">
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <div style="width:38px;height:38px;border-radius:50%;background:var(--theme-default);
                                    color:#fff;display:flex;align-items:center;justify-content:center;
                                    font-weight:700;flex-shrink:0;">
                            {{ strtoupper(substr($ticket->user->name ?? '?', 0, 1)) }}
                        </div>
                        <div>
                            <div class="fw-semibold small">{{ $ticket->user->name ?? '—' }}</div>
                            <div class="f-light" style="font-size:.75rem;">{{ $ticket->user->email ?? '' }}</div>
                        </div>
                    </div>
                    @if($ticket->user)
                        <a href="{{ route('admin.users.show', $ticket->user) }}" class="btn btn-sm btn-outline-secondary w-100 mt-1">
                            View Profile
                        </a>
                    @endif
                </div>
            </div>

            {{-- Quick status change --}}
            <div class="card">
                <div class="card-header"><h6 class="mb-0 fw-bold">Change Status</h6></div>
                <div class="card-body">
                    <form method="POST" action="{{ route('admin.support.status', $ticket) }}">
                        @csrf
                        @method('PATCH')
                        <select name="status" class="form-select form-select-sm mb-2">
                            @foreach(\App\Models\SupportTicket::STATUSES as $val => $label)
                                <option value="{{ $val }}" {{ $ticket->status === $val ? 'selected' : '' }}>{{ $label }}</option>
                            @endforeach
                        </select>
                        <button type="submit" class="btn btn-sm btn-primary w-100">Update Status</button>
                    </form>
                </div>
            </div>

        </div>

        {{-- Thread --}}
        <div class="col-xl-9 order-xl-1">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0 fw-bold">{{ $ticket->subject }}</h5>
                </div>
                <div class="card-body">

                    {{-- Reply thread --}}
                    @foreach($ticket->replies as $reply)
                        @php
                            $isMember = $reply->user_id === $ticket->user_id;
                        @endphp
                        @if($reply->is_internal)
                        {{-- Internal note --}}
                        <div class="mb-4">
                            <div class="d-flex align-items-center gap-2 mb-1">
                                <span class="badge badge-light-warning">Internal Note</span>
                                <span class="fw-semibold small">{{ $reply->user->name ?? 'Unknown' }}</span>
                                <span class="f-light" style="font-size:.75rem;">{{ $reply->created_at->diffForHumans() }}</span>
                            </div>
                            <div class="p-3 rounded" style="background:#fffbeb;border:1px dashed #f6e05e;">
                                {!! nl2br(e($reply->body)) !!}
                            </div>
                        </div>
                        @else
                        <div class="d-flex gap-3 mb-4">
                            <div style="width:38px;height:38px;border-radius:50%;flex-shrink:0;
                                        background:{{ $isMember ? 'var(--theme-default)' : '#54ba4a' }};
                                        color:#fff;display:flex;align-items:center;justify-content:center;
                                        font-weight:700;font-size:.85rem;">
                                {{ strtoupper(substr($reply->user->name ?? '?', 0, 1)) }}
                            </div>
                            <div class="flex-grow-1">
                                <div class="d-flex align-items-center gap-2 mb-1">
                                    <span class="fw-semibold small">{{ $reply->user->name ?? 'Unknown' }}</span>
                                    @if(!$isMember)
                                        <span class="badge badge-light-success" style="font-size:.7rem;">Support</span>
                                    @else
                                        <span class="badge badge-light-primary" style="font-size:.7rem;">Member</span>
                                    @endif
                                    <span class="f-light" style="font-size:.75rem;">{{ $reply->created_at->diffForHumans() }}</span>
                                </div>
                                <div class="p-3 rounded"
                                     style="background:{{ $isMember ? '#f8f9fa' : '#f0fff4' }};border:1px solid {{ $isMember ? '#e9ecef' : '#c6f6d5' }};">
                                    {!! nl2br(e($reply->body)) !!}
                                </div>
                            </div>
                        </div>
                        @endif
                    @endforeach

                    {{-- Admin reply form --}}
                    <hr>
                    <h6 class="fw-semibold mb-3">Reply to Member</h6>
                    <form method="POST" action="{{ route('admin.support.reply', $ticket) }}">
                        @csrf
                        <div class="mb-3">
                            <textarea class="form-control @error('body') is-invalid @enderror"
                                      name="body" rows="5"
                                      placeholder="Type your reply to the member…" required>{{ old('body') }}</textarea>
                            @error('body')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="row g-2 align-items-end">
                            <div class="col-sm-4">
                                <label class="form-label small fw-semibold mb-1">Update Status</label>
                                <select name="status" class="form-select form-select-sm">
                                    @foreach(\App\Models\SupportTicket::STATUSES as $val => $label)
                                        <option value="{{ $val }}" {{ $ticket->status === $val ? 'selected' : '' }}>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-sm-4 d-flex align-items-end pb-1">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="is_internal" value="1" id="is_internal">
                                    <label class="form-check-label small" for="is_internal">
                                        Internal note (hidden from member)
                                    </label>
                                </div>
                            </div>
                            <div class="col-sm-4 text-sm-end">
                                <button type="submit" class="btn btn-primary">
                                    <i data-feather="send" style="width:15px;height:15px;"></i> Send Reply
                                </button>
                            </div>
                        </div>
                    </form>

                </div>
            </div>
        </div>

    </div>
</div>
@endsection
