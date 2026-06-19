@extends('layouts.admin')

@section('title', 'Clawback #' . $clawback->id)
@section('page-title', 'Clawback #' . $clawback->id)

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('admin.commission-clawbacks.index') }}">Commission Clawbacks</a></li>
    <li class="breadcrumb-item active">#{{ $clawback->id }}</li>
@endsection

@section('content')
<div class="row justify-content-center">
    <div class="col-md-7">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">Clawback Details</h5>
                @php $badge = match($clawback->status) {'pending'=>'warning','applied'=>'danger','reversed'=>'secondary',default=>'secondary'}; @endphp
                <span class="badge bg-{{ $badge }} fs-6">{{ ucfirst($clawback->status) }}</span>
            </div>
            <div class="card-body">
                @if(session('success'))
                    <div class="alert alert-success">{{ session('success') }}</div>
                @endif
                @if(session('error'))
                    <div class="alert alert-danger">{{ session('error') }}</div>
                @endif

                <dl class="row">
                    <dt class="col-sm-4">Earner</dt>
                    <dd class="col-sm-8">{{ $clawback->earner?->name }} <small class="text-muted">({{ $clawback->earner?->email }})</small></dd>

                    <dt class="col-sm-4">Amount Clawed Back</dt>
                    <dd class="col-sm-8 fw-bold text-danger">-${{ number_format($clawback->amount, 2) }}</dd>

                    <dt class="col-sm-4">Reason</dt>
                    <dd class="col-sm-8">{{ $clawback->reason }}</dd>

                    <dt class="col-sm-4">Initiated By</dt>
                    <dd class="col-sm-8">{{ $clawback->initiatedBy?->name }} on {{ $clawback->created_at->format('M j, Y g:i A') }}</dd>

                    @if($clawback->applied_at)
                        <dt class="col-sm-4">Applied At</dt>
                        <dd class="col-sm-8">{{ $clawback->applied_at->format('M j, Y g:i A') }}</dd>
                    @endif

                    <dt class="col-sm-4">Window Override</dt>
                    <dd class="col-sm-8">
                        @if($clawback->override_window)
                            <span class="badge bg-warning text-dark">Yes</span>
                            @if($clawback->override_notes)
                                <br><small>{{ $clawback->override_notes }}</small>
                            @endif
                        @else
                            No
                        @endif
                    </dd>
                </dl>

                <hr>
                <h6>Original Credit Entry</h6>
                @php $orig = $clawback->originalLedger; @endphp
                @if($orig)
                <table class="table table-sm">
                    <tr><th>Ledger ID</th><td>#{{ $orig->id }}</td></tr>
                    <tr><th>Amount</th><td>${{ number_format($orig->amount, 2) }}</td></tr>
                    <tr><th>Plan</th><td>{{ $orig->commissionPlan?->name ?? '—' }}</td></tr>
                    <tr><th>Status</th><td>{{ ucfirst($orig->status) }}</td></tr>
                    <tr><th>Earned At</th><td>{{ $orig->created_at->format('M j, Y') }}</td></tr>
                </table>
                @endif

                @if($clawback->debitLedger)
                <h6 class="mt-3">Debit Entry Created</h6>
                <table class="table table-sm">
                    <tr><th>Ledger ID</th><td>#{{ $clawback->debitLedger->id }}</td></tr>
                    <tr><th>Amount</th><td class="text-danger">-${{ number_format($clawback->debitLedger->amount, 2) }}</td></tr>
                    <tr><th>Status</th><td>{{ ucfirst($clawback->debitLedger->status) }}</td></tr>
                </table>
                @endif

                @if($clawback->isApplied())
                <form action="{{ route('admin.commission-clawbacks.reverse', $clawback) }}" method="POST"
                      onsubmit="return confirm('Reverse this clawback and restore the original credit?')">
                    @csrf
                    <button class="btn btn-warning">Reverse Clawback</button>
                </form>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection
