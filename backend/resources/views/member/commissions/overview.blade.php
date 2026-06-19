@extends('layouts.member')

@section('title', 'My Commissions')
@section('page-title', 'My Commissions')

@section('content')
<div class="row g-3 mb-4">
    {{-- Balance card --}}
    <div class="col-sm-6 col-xl-3">
        <div class="card mb-0 h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div style="width:48px;height:48px;border-radius:12px;background:#f0eeff;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <i data-feather="dollar-sign" style="width:22px;height:22px;color:#7366ff;"></i>
                </div>
                <div>
                    <div class="fs-4 fw-bold lh-1">${{ number_format($balance, 2) }}</div>
                    <div class="text-muted small">Available Balance</div>
                </div>
            </div>
        </div>
    </div>

    {{-- Lifetime paid --}}
    <div class="col-sm-6 col-xl-3">
        <div class="card mb-0 h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div style="width:48px;height:48px;border-radius:12px;background:#edfceb;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <i data-feather="trending-up" style="width:22px;height:22px;color:#54ba4a;"></i>
                </div>
                <div>
                    <div class="fs-4 fw-bold lh-1">${{ number_format($lifetime, 2) }}</div>
                    <div class="text-muted small">Lifetime Paid Out</div>
                </div>
            </div>
        </div>
    </div>

    {{-- Pending payout --}}
    <div class="col-sm-6 col-xl-3">
        <div class="card mb-0 h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div style="width:48px;height:48px;border-radius:12px;background:#fff8e7;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <i data-feather="clock" style="width:22px;height:22px;color:#f7b731;"></i>
                </div>
                <div>
                    @if($pendingPayout)
                        <div class="fs-4 fw-bold lh-1">${{ number_format($pendingPayout->total_amount, 2) }}</div>
                        <div class="text-muted small">Payout {{ ucfirst($pendingPayout->status) }}</div>
                    @else
                        <div class="fs-4 fw-bold lh-1 text-muted">—</div>
                        <div class="text-muted small">No Pending Payout</div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- Pending entries count --}}
    <div class="col-sm-6 col-xl-3">
        <div class="card mb-0 h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div style="width:48px;height:48px;border-radius:12px;background:#ffe9e9;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <i data-feather="list" style="width:22px;height:22px;color:#f04f5f;"></i>
                </div>
                <div>
                    <div class="fs-4 fw-bold lh-1">{{ $recent->count() }}</div>
                    <div class="text-muted small">Recent Transactions</div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-md-8">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">Recent Activity</h5>
                <a href="{{ route('member.commissions.history') }}" class="btn btn-sm btn-outline-primary">View All</a>
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
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($recent as $entry)
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
                            </tr>
                            @empty
                            <tr><td colspan="5" class="text-center text-muted py-4">No commission activity yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">Payout History</h5>
                <a href="{{ route('member.commissions.payouts') }}" class="btn btn-sm btn-outline-primary">View All</a>
            </div>
            <div class="card-body p-0">
                @php
                    $recentPayouts = \App\Models\CommissionPayout::where('earner_id', auth()->id())->latest()->limit(5)->get();
                @endphp
                @forelse($recentPayouts as $p)
                <div class="d-flex justify-content-between align-items-center px-3 py-2 border-bottom">
                    <div>
                        <div class="fw-semibold">${{ number_format($p->total_amount, 2) }}</div>
                        <div class="text-muted small">{{ $p->created_at->format('M j, Y') }}</div>
                    </div>
                    @php $badge = match($p->status) {'pending'=>'warning','approved'=>'info','paid'=>'success','cancelled'=>'secondary',default=>'secondary'}; @endphp
                    <span class="badge bg-{{ $badge }}">{{ ucfirst($p->status) }}</span>
                </div>
                @empty
                <p class="text-center text-muted py-4 mb-0">No payouts yet.</p>
                @endforelse
            </div>
        </div>
    </div>
</div>
@endsection
