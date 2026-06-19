@extends('layouts.admin')

@section('title', 'Commission Payouts')
@section('page-title', 'Commission Payouts')

@section('breadcrumb')
    <li class="breadcrumb-item active">Commission Payouts</li>
@endsection

@section('content')
<div class="row">
    <div class="col-sm-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <h5 class="mb-0">Payout Batches</h5>
                <div class="d-flex gap-2 align-items-center flex-wrap">
                    <form method="GET" class="d-flex gap-2">
                        <select name="earner_id" class="form-select form-select-sm" style="width:auto">
                            <option value="">All Earners</option>
                            @foreach($users as $u)
                                <option value="{{ $u->id }}" {{ request('earner_id') == $u->id ? 'selected' : '' }}>{{ $u->name }}</option>
                            @endforeach
                        </select>
                        <select name="status" class="form-select form-select-sm" style="width:auto">
                            <option value="">All Statuses</option>
                            @foreach(['pending','approved','paid','cancelled'] as $s)
                                <option value="{{ $s }}" {{ request('status') === $s ? 'selected' : '' }}>{{ ucfirst($s) }}</option>
                            @endforeach
                        </select>
                        <button class="btn btn-outline-secondary btn-sm">Filter</button>
                    </form>
                    <a href="{{ route('admin.commission-payouts.create') }}" class="btn btn-primary btn-sm">
                        <i data-feather="plus" data-width="14" data-height="14"></i> New Payout
                    </a>
                </div>
            </div>
            <div class="card-body p-0">
                @if(session('success'))
                    <div class="alert alert-success m-3">{{ session('success') }}</div>
                @endif
                @if(session('error'))
                    <div class="alert alert-danger m-3">{{ session('error') }}</div>
                @endif
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>#</th>
                                <th>Earner</th>
                                <th>Period</th>
                                <th>Total</th>
                                <th>Status</th>
                                <th>Paid At</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($payouts as $payout)
                            <tr>
                                <td class="text-muted small">{{ $payout->id }}</td>
                                <td>{{ $payout->earner?->name ?? '—' }}</td>
                                <td class="small">
                                    @if($payout->period_start || $payout->period_end)
                                        {{ $payout->period_start?->format('M j') }} – {{ $payout->period_end?->format('M j, Y') }}
                                    @else
                                        <span class="text-muted">All time</span>
                                    @endif
                                </td>
                                <td class="fw-semibold">${{ number_format($payout->total_amount, 2) }}</td>
                                <td>
                                    @php
                                        $badge = match($payout->status) {
                                            'pending'   => 'warning',
                                            'approved'  => 'info',
                                            'paid'      => 'success',
                                            'cancelled' => 'secondary',
                                            default     => 'secondary',
                                        };
                                    @endphp
                                    <span class="badge bg-{{ $badge }}">{{ ucfirst($payout->status) }}</span>
                                </td>
                                <td class="small text-muted">{{ $payout->paid_at?->format('M j, Y') ?? '—' }}</td>
                                <td>
                                    <a href="{{ route('admin.commission-payouts.show', $payout) }}" class="btn btn-sm btn-outline-primary">View</a>
                                </td>
                            </tr>
                            @empty
                            <tr><td colspan="7" class="text-center text-muted py-4">No payouts yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="p-3">{{ $payouts->links() }}</div>
            </div>
        </div>
    </div>
</div>
@endsection
