@extends('layouts.member')

@section('title', 'Support Tickets')
@section('page-title', 'Support Tickets')

@section('breadcrumb')
    <li class="breadcrumb-item active">Support</li>
@endsection

@section('content')

<div class="row mb-3">
    <div class="col">
        <div class="d-flex justify-content-between align-items-center">
            <h5 class="mb-0">My Tickets</h5>
            <button type="button" class="btn btn-primary"
                    data-bs-toggle="modal" data-bs-target="#supportTicketModal"
                    id="supportTicketBtnIndex">
                <i data-feather="help-circle" style="width:15px;height:15px;"></i> New Ticket
            </button>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-body p-0">
        @if($tickets->isEmpty())
            <div class="text-center py-5">
                <i data-feather="inbox" style="width:48px;height:48px;opacity:.25;" class="mb-3"></i>
                <p class="f-light mb-3">No support tickets yet.</p>
                <button type="button" class="btn btn-primary btn-sm"
                        data-bs-toggle="modal" data-bs-target="#supportTicketModal">
                    Submit a Ticket
                </button>
            </div>
        @else
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Ticket #</th>
                            <th>Subject</th>
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
                            <td class="fw-semibold">{{ $ticket->subject }}</td>
                            <td><span class="badge badge-light-secondary">{{ \App\Models\SupportTicket::CATEGORIES[$ticket->category] ?? ucfirst($ticket->category) }}</span></td>
                            <td><span class="badge {{ $ticket->priorityBadgeClass() }}">{{ ucfirst($ticket->priority) }}</span></td>
                            <td><span class="badge {{ $ticket->statusBadgeClass() }}">{{ \App\Models\SupportTicket::STATUSES[$ticket->status] ?? ucfirst($ticket->status) }}</span></td>
                            <td class="f-light small">{{ $ticket->created_at->format('M j, Y') }}</td>
                            <td>
                                <a href="{{ route('member.support.show', $ticket) }}" class="btn btn-sm btn-outline-primary py-0">View</a>
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

@endsection

@push('scripts')
<script>
    // The index "New Ticket" button also needs to capture the current URL
    var indexBtn = document.getElementById('supportTicketBtnIndex');
    if (indexBtn) {
        indexBtn.addEventListener('click', function () {
            var urlInput = document.getElementById('ticketSourceUrl');
            var ctxBox   = document.getElementById('ticketPageContext');
            var ctxLabel = document.getElementById('ticketPageContextUrl');
            var url = window.location.href;
            if (urlInput)  urlInput.value       = url;
            if (ctxLabel)  ctxLabel.textContent = url;
            if (ctxBox)    ctxBox.style.display = '';
        });
    }
</script>
@endpush
