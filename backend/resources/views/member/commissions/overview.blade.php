@extends('layouts.member')

@section('title', 'My Commissions')
@section('page-title', 'My Commissions')

@section('content')
<div class="row g-3 mb-4">
    {{-- Balance card --}}
    <div class="col-sm-6 col-xl-3">
        <div class="card mb-0 h-100">
            {{-- Available balance is the headline number on this page, so it
                 takes the gold; the other three stay off-white. --}}
            <div class="card-body q3-stat">
                <div class="q3-stat-icon q3-stat-icon--gold"><i data-feather="dollar-sign"></i></div>
                <div>
                    <div class="q3-stat-value q3-stat-value--gold">${{ number_format($balance, 2) }}</div>
                    <div class="q3-stat-label">Available Balance</div>
                </div>
            </div>
        </div>
    </div>

    {{-- Lifetime paid --}}
    <div class="col-sm-6 col-xl-3">
        <div class="card mb-0 h-100">
            <div class="card-body q3-stat">
                <div class="q3-stat-icon q3-stat-icon--success"><i data-feather="trending-up"></i></div>
                <div>
                    <div class="q3-stat-value">${{ number_format($lifetime, 2) }}</div>
                    <div class="q3-stat-label">Lifetime Paid Out</div>
                </div>
            </div>
        </div>
    </div>

    {{-- Pending payout --}}
    <div class="col-sm-6 col-xl-3">
        <div class="card mb-0 h-100">
            <div class="card-body q3-stat">
                <div class="q3-stat-icon"><i data-feather="clock"></i></div>
                <div>
                    @if($pendingPayout)
                        <div class="q3-stat-value">${{ number_format($pendingPayout->total_amount, 2) }}</div>
                        <div class="q3-stat-label">Payout {{ ucfirst($pendingPayout->status) }}</div>
                    @else
                        <div class="q3-stat-value q3-dim">—</div>
                        <div class="q3-stat-label">No Pending Payout</div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- Pending entries count --}}
    <div class="col-sm-6 col-xl-3">
        <div class="card mb-0 h-100">
            <div class="card-body q3-stat">
                <div class="q3-stat-icon"><i data-feather="list"></i></div>
                <div>
                    <div class="q3-stat-value">{{ $recent->count() }}</div>
                    <div class="q3-stat-label">Recent Transactions</div>
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
