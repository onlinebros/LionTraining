@extends('layouts.admin')

@section('title', 'Commission Ledger')
@section('page-title', 'Commission Ledger')

@section('breadcrumb')
    <li class="breadcrumb-item active">Commission Ledger</li>
@endsection

@section('content')
<div class="row">
    <div class="col-sm-12">

        {{-- Manual entry card --}}
        <div class="card mb-3">
            <div class="card-header"><h6 class="mb-0">Record Manual Credit</h6></div>
            <div class="card-body">
                <form action="{{ route('admin.commission-ledger.store') }}" method="POST" class="row g-2 align-items-end">
                    @csrf
                    <div class="col-md-3">
                        <label class="form-label form-label-sm">Earner</label>
                        <select name="earner_id" class="form-select form-select-sm" required>
                            <option value="">Select user…</option>
                            @foreach($users as $u)
                                <option value="{{ $u->id }}">{{ $u->name }} ({{ $u->email }})</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label form-label-sm">Plan</label>
                        <select name="commission_plan_id" class="form-select form-select-sm" required>
                            <option value="">Select plan…</option>
                            @foreach($plans as $p)
                                <option value="{{ $p->id }}">{{ $p->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label form-label-sm">Amount ($)</label>
                        <input type="number" name="amount" step="0.01" min="0.01" class="form-control form-control-sm" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label form-label-sm">Notes</label>
                        <input type="text" name="notes" class="form-control form-control-sm" placeholder="Optional">
                    </div>
                    <div class="col-md-1">
                        <button class="btn btn-primary btn-sm w-100">Add</button>
                    </div>
                </form>
            </div>
        </div>

        {{-- Ledger table --}}
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <h5 class="mb-0">Ledger Entries</h5>
                <form method="GET" class="d-flex gap-2 flex-wrap">
                    <select name="earner_id" class="form-select form-select-sm" style="width:auto">
                        <option value="">All Earners</option>
                        @foreach($users as $u)
                            <option value="{{ $u->id }}" {{ request('earner_id') == $u->id ? 'selected' : '' }}>
                                {{ $u->name }}
                            </option>
                        @endforeach
                    </select>
                    <select name="type" class="form-select form-select-sm" style="width:auto">
                        <option value="">All Types</option>
                        <option value="credit" {{ request('type') === 'credit' ? 'selected' : '' }}>Credits</option>
                        <option value="debit"  {{ request('type') === 'debit'  ? 'selected' : '' }}>Debits</option>
                    </select>
                    <select name="status" class="form-select form-select-sm" style="width:auto">
                        <option value="">All Statuses</option>
                        @foreach(['pending','approved','paid','voided'] as $s)
                            <option value="{{ $s }}" {{ request('status') === $s ? 'selected' : '' }}>{{ ucfirst($s) }}</option>
                        @endforeach
                    </select>
                    <input type="date" name="from" class="form-control form-control-sm" style="width:auto" value="{{ request('from') }}">
                    <input type="date" name="to"   class="form-control form-control-sm" style="width:auto" value="{{ request('to') }}">
                    <button class="btn btn-outline-secondary btn-sm">Filter</button>
                    <a href="{{ route('admin.commission-ledger.index') }}" class="btn btn-outline-secondary btn-sm">Reset</a>
                </form>
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
                                <th>Date</th>
                                <th>Earner</th>
                                <th>Type</th>
                                <th>Amount</th>
                                <th>Plan</th>
                                <th>Status</th>
                                <th>Clawback By</th>
                                <th>Payout</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($entries as $entry)
                            <tr>
                                <td class="text-muted small">{{ $entry->id }}</td>
                                <td class="small">{{ $entry->created_at->format('M j, Y') }}</td>
                                <td>{{ $entry->earner?->name ?? '—' }}</td>
                                <td>
                                    @if($entry->type === 'credit')
                                        <span class="badge bg-success">Credit</span>
                                    @else
                                        <span class="badge bg-danger">Debit</span>
                                    @endif
                                </td>
                                <td class="fw-semibold {{ $entry->type === 'debit' ? 'text-danger' : '' }}">
                                    {{ $entry->type === 'debit' ? '-' : '' }}${{ number_format($entry->amount, 2) }}
                                </td>
                                <td class="small">{{ $entry->commissionPlan?->name ?? '—' }}</td>
                                <td>
                                    @php
                                        $badge = match($entry->status) {
                                            'pending'  => 'warning',
                                            'approved' => 'info',
                                            'paid'     => 'success',
                                            'voided'   => 'secondary',
                                            default    => 'secondary',
                                        };
                                    @endphp
                                    <span class="badge bg-{{ $badge }}">{{ ucfirst($entry->status) }}</span>
                                </td>
                                <td class="small text-muted">
                                    {{ $entry->clawback_eligible_until
                                        ? $entry->clawback_eligible_until->format('M j, Y')
                                        : '—' }}
                                </td>
                                <td class="small">
                                    @if($entry->payout_id)
                                        <a href="{{ route('admin.commission-payouts.show', $entry->payout_id) }}">#{{ $entry->payout_id }}</a>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td>
                                    @if($entry->type === 'credit' && $entry->status !== 'voided')
                                        @if($entry->status === 'pending')
                                            <form action="{{ route('admin.commission-ledger.approve', $entry) }}" method="POST" class="d-inline">
                                                @csrf
                                                <button class="btn btn-xs btn-outline-info">Approve</button>
                                            </form>
                                        @endif
                                        @if(!$entry->isPaid())
                                            <form action="{{ route('admin.commission-ledger.void', $entry) }}" method="POST" class="d-inline"
                                                  onsubmit="return confirm('Void this entry?')">
                                                @csrf
                                                <button class="btn btn-xs btn-outline-secondary">Void</button>
                                            </form>
                                            <a href="{{ route('admin.commission-clawbacks.index', ['ledger_id' => $entry->id]) }}"
                                               class="btn btn-xs btn-outline-danger">Clawback</a>
                                        @endif
                                    @endif
                                </td>
                            </tr>
                            @empty
                            <tr><td colspan="10" class="text-center text-muted py-4">No ledger entries found.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="p-3">{{ $entries->links() }}</div>
            </div>
        </div>
    </div>
</div>
@endsection
