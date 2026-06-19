@extends('layouts.admin')

@section('title', 'Commission Clawbacks')
@section('page-title', 'Commission Clawbacks')

@section('breadcrumb')
    <li class="breadcrumb-item active">Commission Clawbacks</li>
@endsection

@section('content')
<div class="row">
    <div class="col-sm-12">

        {{-- Initiate clawback form --}}
        @if(request('ledger_id'))
        @php $targetLedger = \App\Models\CommissionLedger::with('earner','commissionPlan')->find(request('ledger_id')); @endphp
        @if($targetLedger)
        <div class="card mb-3 border-danger">
            <div class="card-header bg-danger text-white">
                <h6 class="mb-0">Initiate Clawback — Ledger Entry #{{ $targetLedger->id }}</h6>
            </div>
            <div class="card-body">
                <p>
                    Earner: <strong>{{ $targetLedger->earner?->name }}</strong> &nbsp;|&nbsp;
                    Amount: <strong>${{ number_format($targetLedger->amount, 2) }}</strong> &nbsp;|&nbsp;
                    Plan: {{ $targetLedger->commissionPlan?->name ?? '—' }} &nbsp;|&nbsp;
                    Clawback eligible until:
                    @if($targetLedger->clawback_eligible_until)
                        {{ $targetLedger->clawback_eligible_until->format('M j, Y') }}
                        @if($targetLedger->isClawbackEligible())
                            <span class="badge bg-success">Within window</span>
                        @else
                            <span class="badge bg-danger">Window expired</span>
                        @endif
                    @else
                        <span class="text-muted">No automatic window</span>
                    @endif
                </p>
                @if(session('error'))
                    <div class="alert alert-danger">{{ session('error') }}</div>
                @endif
                <form action="{{ route('admin.commission-clawbacks.store') }}" method="POST">
                    @csrf
                    <input type="hidden" name="ledger_id" value="{{ $targetLedger->id }}">
                    <div class="mb-3">
                        <label class="form-label">Reason <span class="text-danger">*</span></label>
                        <textarea name="reason" class="form-control" rows="2" required></textarea>
                    </div>
                    @if(!$targetLedger->isClawbackEligible())
                    <div class="mb-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="override_window" id="override_window" value="1">
                            <label class="form-check-label text-danger" for="override_window">
                                Override clawback window (admin exception)
                            </label>
                        </div>
                        <div class="mt-2" id="override_notes_wrap" style="display:none">
                            <label class="form-label">Override Justification <span class="text-danger">*</span></label>
                            <textarea name="override_notes" class="form-control" rows="2"></textarea>
                        </div>
                    </div>
                    @endif
                    <div class="d-flex gap-2">
                        <button class="btn btn-danger">Apply Clawback</button>
                        <a href="{{ route('admin.commission-clawbacks.index') }}" class="btn btn-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
        @endif
        @endif

        {{-- Clawbacks table --}}
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <h5 class="mb-0">Clawback History</h5>
                <form method="GET" class="d-flex gap-2">
                    <select name="status" class="form-select form-select-sm" style="width:auto">
                        <option value="">All Statuses</option>
                        @foreach(['pending','applied','reversed'] as $s)
                            <option value="{{ $s }}" {{ request('status') === $s ? 'selected' : '' }}>{{ ucfirst($s) }}</option>
                        @endforeach
                    </select>
                    <button class="btn btn-outline-secondary btn-sm">Filter</button>
                </form>
            </div>
            <div class="card-body p-0">
                @if(session('success'))
                    <div class="alert alert-success m-3">{{ session('success') }}</div>
                @endif
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>#</th>
                                <th>Date</th>
                                <th>Earner</th>
                                <th>Amount</th>
                                <th>Reason</th>
                                <th>Status</th>
                                <th>Window Override</th>
                                <th>Initiated By</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($clawbacks as $cb)
                            <tr>
                                <td class="text-muted small">{{ $cb->id }}</td>
                                <td class="small">{{ $cb->created_at->format('M j, Y') }}</td>
                                <td>{{ $cb->earner?->name ?? '—' }}</td>
                                <td class="fw-semibold text-danger">-${{ number_format($cb->amount, 2) }}</td>
                                <td class="small">{{ Str::limit($cb->reason, 50) }}</td>
                                <td>
                                    @php $badge = match($cb->status) {'pending'=>'warning','applied'=>'danger','reversed'=>'secondary',default=>'secondary'}; @endphp
                                    <span class="badge bg-{{ $badge }}">{{ ucfirst($cb->status) }}</span>
                                </td>
                                <td>
                                    @if($cb->override_window)
                                        <span class="badge bg-warning text-dark">Yes</span>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="small">{{ $cb->initiatedBy?->name ?? '—' }}</td>
                                <td>
                                    <a href="{{ route('admin.commission-clawbacks.show', $cb) }}" class="btn btn-sm btn-outline-primary">View</a>
                                    @if($cb->isApplied())
                                        <form action="{{ route('admin.commission-clawbacks.reverse', $cb) }}" method="POST" class="d-inline"
                                              onsubmit="return confirm('Reverse this clawback?')">
                                            @csrf
                                            <button class="btn btn-sm btn-outline-warning">Reverse</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                            @empty
                            <tr><td colspan="9" class="text-center text-muted py-4">No clawbacks on record.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="p-3">{{ $clawbacks->links() }}</div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
const ow = document.getElementById('override_window');
if (ow) {
    ow.addEventListener('change', function () {
        document.getElementById('override_notes_wrap').style.display = this.checked ? '' : 'none';
    });
}
</script>
@endpush
