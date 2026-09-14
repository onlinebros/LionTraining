@extends('layouts.admin')

@section('title', 'Support Tickets')
@section('page-title', 'Support Tickets')

@section('breadcrumb')
    <li class="breadcrumb-item active">Support Tickets</li>
@endsection

@push('styles')
<style>
    .priority-high    { color: var(--q3-danger); }
    .priority-normal  { color: var(--q3-warning); }
    .priority-low     { color: var(--q3-text-muted); }
</style>
@endpush

@section('content')
<div class="container-fluid">

    @if(session('success'))
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    {{-- Stat cards --}}
    <div class="row mb-4">
        @foreach(['open' => ['primary','Open'], 'in_progress' => ['warning','In Progress'], 'closed' => ['success','Closed'], 'all' => ['secondary','Total']] as $key => [$color, $label])
        <div class="col-xl-3 col-sm-6">
            <a href="{{ route('admin.support.index', ['status' => $key]) }}" class="text-decoration-none">
                <div class="card small-widget mb-sm-0 {{ $status === $key ? 'border border-' . $color : '' }}">
                    <div class="card-body {{ $color }}">
                        <span class="f-light">{{ $label }}</span>
                        <div class="d-flex align-items-end gap-1 mt-3">
                            <h4>{{ $counts[$key] }}</h4>
                        </div>
                        <div class="bg-gradient">
                            <i data-feather="{{ $key === 'open' ? 'inbox' : ($key === 'in_progress' ? 'clock' : ($key === 'closed' ? 'check-circle' : 'list')) }}"
                               class="stroke-icon"></i>
                        </div>
                    </div>
                </div>
            </a>
        </div>
        @endforeach
    </div>

    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
            <h5 class="mb-0">
                @if($status === 'all') All Tickets
                @elseif($status === 'open') Open Tickets
                @elseif($status === 'in_progress') In Progress
                @else Closed Tickets
                @endif
            </h5>
            <div class="d-flex gap-2 flex-wrap">
                @foreach(['open' => 'Open', 'in_progress' => 'In Progress', 'closed' => 'Closed', 'all' => 'All'] as $key => $label)
                    <a href="{{ route('admin.support.index', ['status' => $key]) }}"
                       class="btn btn-sm {{ $status === $key ? 'btn-primary' : 'btn-secondary' }}">
                        {{ $label }}
                        @if(isset($counts[$key]) && $counts[$key] > 0 && $key !== 'all')
                            <span class="badge ms-1" style="background:rgba(0,0,0,.18);color:inherit;">{{ $counts[$key] }}</span>
                        @endif
                    </a>
                @endforeach
            </div>
        </div>
        <div class="card-body p-0">
            @if($tickets->isEmpty())
                <div class="text-center py-5">
                    <i data-feather="check-circle" style="width:48px;height:48px;color:var(--q3-success);"></i>
                    <p class="mt-3 f-light">No tickets in this category.</p>
                </div>
            @else
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Ticket #</th>
                                <th>Subject</th>
                                <th>Requester</th>
                                <th>Category</th>
                                <th>Priority</th>
                                <th>Status</th>
                                <th>Submitted</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($tickets as $ticket)
                            <tr>
                                <td><code class="text-primary">{{ $ticket->ticket_number }}</code></td>
                                <td class="fw-semibold" style="max-width:260px;">
                                    <span class="text-truncate d-block">{{ $ticket->subject }}</span>
                                </td>
                                <td>
                                    <div class="fw-semibold small">
                                        {{ $ticket->requesterDisplayName() }}
                                        @if($ticket->isFromWebsite())
                                            <span class="badge badge-light-info ms-1" style="font-size:.65rem;" title="Submitted through the website contact form — no account">Website</span>
                                        @endif
                                    </div>
                                    <div class="f-light" style="font-size:.75rem;">{{ $ticket->requesterDisplayEmail() ?? '' }}</div>
                                </td>
                                <td><span class="badge badge-light-secondary">{{ \App\Models\SupportTicket::CATEGORIES[$ticket->category] ?? ucfirst($ticket->category) }}</span></td>
                                <td>
                                    <span class="badge {{ $ticket->priorityBadgeClass() }}">{{ ucfirst($ticket->priority) }}</span>
                                </td>
                                <td><span class="badge {{ $ticket->statusBadgeClass() }}">{{ \App\Models\SupportTicket::STATUSES[$ticket->status] ?? ucfirst($ticket->status) }}</span></td>
                                <td class="f-light small">{{ $ticket->created_at->format('M j, Y') }}</td>
                                <td>
                                    <a href="{{ route('admin.support.show', $ticket) }}" class="btn btn-sm btn-outline-primary py-0">View</a>
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if($tickets->hasPages())
                    <div class="p-3">{{ $tickets->links() }}</div>
                @endif
            @endif
        </div>
    </div>

</div>
@endsection
