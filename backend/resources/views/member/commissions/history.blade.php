@extends('layouts.member')

@section('title', 'Commission History')
@section('page-title', 'Commission History')

@section('content')
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h5 class="mb-0">All Transactions</h5>
        <div class="d-flex gap-2">
            <form method="GET" class="d-flex gap-2">
                <select name="type" class="form-select form-select-sm" style="width:auto"
                        onchange="this.form.submit()">
                    <option value="">All Types</option>
                    <option value="credit" {{ request('type') === 'credit' ? 'selected' : '' }}>Credits</option>
                    <option value="debit"  {{ request('type') === 'debit'  ? 'selected' : '' }}>Debits</option>
                </select>
            </form>
            <a href="{{ route('member.commissions.index') }}" class="btn btn-sm btn-outline-secondary">Overview</a>
        </div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Date</th>
                        <th>Type</th>
                        <th>Amount</th>
                        <th>Plan</th>
                        <th>Status</th>
                        <th>Notes</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($entries as $entry)
                    <tr>
                        <td class="small">{{ $entry->created_at->format('M j, Y') }}</td>
                        <td>
                            @if($entry->type === 'credit')
                                <span class="badge bg-success">Credit</span>
                            @else
                                <span class="badge bg-danger">Debit</span>
                            @endif
                        </td>
                        <td class="fw-semibold {{ $entry->type === 'debit' ? 'text-danger' : '' }}">
                            {{ $entry->type === 'debit' ? '-' : '+' }}${{ number_format($entry->amount, 2) }}
                        </td>
                        <td class="small">{{ $entry->commissionPlan?->name ?? '—' }}</td>
                        <td>
                            @php $badge = match($entry->status) {'pending'=>'warning','approved'=>'info','paid'=>'success','voided'=>'secondary',default=>'secondary'}; @endphp
                            <span class="badge bg-{{ $badge }}">{{ ucfirst($entry->status) }}</span>
                        </td>
                        <td class="small text-muted">{{ $entry->notes }}</td>
                    </tr>
                    @empty
                    <tr><td colspan="6" class="text-center text-muted py-4">No transactions found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="p-3">{{ $entries->links() }}</div>
    </div>
</div>
@endsection
