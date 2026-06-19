@extends('layouts.member')

@section('title', 'Payout History')
@section('page-title', 'Payout History')

@section('content')
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0">Payout History</h5>
        <a href="{{ route('member.commissions.index') }}" class="btn btn-sm btn-outline-secondary">Overview</a>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>Date</th>
                        <th>Period</th>
                        <th>Amount</th>
                        <th>Status</th>
                        <th>Reference</th>
                        <th>Paid At</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($payouts as $payout)
                    <tr>
                        <td class="text-muted small">{{ $payout->id }}</td>
                        <td class="small">{{ $payout->created_at->format('M j, Y') }}</td>
                        <td class="small">
                            @if($payout->period_start || $payout->period_end)
                                {{ $payout->period_start?->format('M j') }} – {{ $payout->period_end?->format('M j, Y') }}
                            @else
                                <span class="text-muted">All time</span>
                            @endif
                        </td>
                        <td class="fw-semibold">${{ number_format($payout->total_amount, 2) }}</td>
                        <td>
                            @php $badge = match($payout->status) {'pending'=>'warning','approved'=>'info','paid'=>'success','cancelled'=>'secondary',default=>'secondary'}; @endphp
                            <span class="badge bg-{{ $badge }}">{{ ucfirst($payout->status) }}</span>
                        </td>
                        <td class="small text-muted">
                            {{ $payout->payment_reference ?? '—' }}
                            @if($payout->payment_method)
                                <br><span class="text-muted">{{ $payout->payment_method }}</span>
                            @endif
                        </td>
                        <td class="small text-muted">{{ $payout->paid_at?->format('M j, Y') ?? '—' }}</td>
                    </tr>
                    @empty
                    <tr><td colspan="7" class="text-center text-muted py-4">No payouts on record yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="p-3">{{ $payouts->links() }}</div>
    </div>
</div>
@endsection
