@extends('layouts.admin')

@section('title', 'Payout #' . $payout->id)
@section('page-title', 'Payout #' . $payout->id)

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('admin.commission-payouts.index') }}">Commission Payouts</a></li>
    <li class="breadcrumb-item active">#{{ $payout->id }}</li>
@endsection

@section('content')
<div class="row">
    <div class="col-md-4 mb-3">
        <div class="card h-100">
            <div class="card-header"><h5 class="mb-0">Payout Details</h5></div>
            <div class="card-body">
                @if(session('success'))
                    <div class="alert alert-success">{{ session('success') }}</div>
                @endif
                @if(session('error'))
                    <div class="alert alert-danger">{{ session('error') }}</div>
                @endif

                <dl class="mb-0">
                    <dt>Earner</dt>
                    <dd>{{ $payout->earner?->name }}<br><small class="text-muted">{{ $payout->earner?->email }}</small></dd>
                    <dt>Status</dt>
                    <dd>
                        @php $badge = match($payout->status) {'pending'=>'warning','approved'=>'info','paid'=>'success','cancelled'=>'secondary',default=>'secondary'}; @endphp
                        <span class="badge bg-{{ $badge }} fs-6">{{ ucfirst($payout->status) }}</span>
                    </dd>
                    <dt>Total Amount</dt>
                    <dd class="fs-4 fw-bold">${{ number_format($payout->total_amount, 2) }}</dd>
                    <dt>Period</dt>
                    <dd>
                        @if($payout->period_start || $payout->period_end)
                            {{ $payout->period_start?->format('M j, Y') }} – {{ $payout->period_end?->format('M j, Y') }}
                        @else
                            All time
                        @endif
                    </dd>
                    @if($payout->payment_reference)
                        <dt>Payment Reference</dt>
                        <dd><code>{{ $payout->payment_reference }}</code></dd>
                        <dt>Method</dt>
                        <dd>{{ $payout->payment_method ?? '—' }}</dd>
                        <dt>Paid At</dt>
                        <dd>{{ $payout->paid_at?->format('M j, Y g:i A') }}</dd>
                    @endif
                    @if($payout->notes)
                        <dt>Notes</dt>
                        <dd>{{ $payout->notes }}</dd>
                    @endif
                    @if($payout->processedBy)
                        <dt>Processed By</dt>
                        <dd>{{ $payout->processedBy->name }}</dd>
                    @endif
                    @if($payout->stripe_transfer_id)
                        <dt>Stripe Transfer</dt>
                        <dd>
                            <code>{{ $payout->stripe_transfer_id }}</code><br>
                            @php $transferBadge = match($payout->transfer_status) {'paid'=>'success','failed'=>'danger','reversed'=>'warning',default=>'secondary'}; @endphp
                            <span class="badge bg-{{ $transferBadge }}">{{ ucfirst($payout->transfer_status ?? 'unknown') }}</span>
                            <small class="text-muted d-block mt-1">
                                Sent {{ $payout->transferred_at?->format('M j, Y g:i A') }} to <code>{{ $payout->stripe_destination_account }}</code>
                            </small>
                            @if($payout->transfer_status === 'reversed' && $payout->transfer_failure_reason)
                                <small class="text-muted d-block">Reason: {{ $payout->transfer_failure_reason }}</small>
                            @endif
                        </dd>
                    @elseif($payout->transfer_failure_reason)
                        <dt>Last Stripe Attempt</dt>
                        <dd class="text-danger small">{{ $payout->transfer_failure_reason }}</dd>
                    @endif
                </dl>

                <hr>

                {{-- Pay through Stripe Connect --}}
                @if(! $payout->stripe_transfer_id && in_array($payout->status, ['pending', 'approved']))
                    @if($transferBlockedReason)
                        <div class="alert alert-warning py-2 small mb-2">
                            <strong>Cannot pay via Stripe:</strong> {{ $transferBlockedReason }}
                        </div>
                    @else
                        <form action="{{ route('admin.commission-payouts.send-transfer', $payout) }}" method="POST" class="mb-2"
                              onsubmit="return confirm('Send ${{ number_format($payout->total_amount, 2) }} to {{ $payout->earner?->name }} through Stripe? This moves real money and marks the payout paid.')">
                            @csrf
                            <button class="btn btn-primary w-100">Pay ${{ number_format($payout->total_amount, 2) }} via Stripe</button>
                        </form>
                    @endif
                @elseif($payout->transfer_status !== 'reversed')
                    <button class="btn btn-outline-warning w-100 mb-2" data-bs-toggle="collapse" data-bs-target="#reverseForm">Reverse Transfer</button>
                    <div class="collapse mb-2" id="reverseForm">
                        <div class="card card-body">
                            <p class="small text-muted mb-2">
                                Only works while the money is still on the partner's Stripe balance. Once Stripe has paid
                                it to their bank this will fail.
                            </p>
                            <form action="{{ route('admin.commission-payouts.reverse-transfer', $payout) }}" method="POST">
                                @csrf
                                <div class="mb-2">
                                    <label class="form-label form-label-sm">Reason</label>
                                    <input type="text" name="reason" class="form-control form-control-sm" maxlength="255">
                                </div>
                                <button class="btn btn-warning btn-sm w-100">Confirm Reversal</button>
                            </form>
                        </div>
                    </div>
                @endif

                {{-- Action buttons --}}
                @if($payout->isPending())
                    <form action="{{ route('admin.commission-payouts.approve', $payout) }}" method="POST" class="mb-2">
                        @csrf
                        <button class="btn btn-info w-100">Approve Payout</button>
                    </form>
                @endif

                @if(in_array($payout->status, ['pending', 'approved']))
                    <button class="btn btn-success w-100 mb-2" data-bs-toggle="collapse" data-bs-target="#markPaidForm">
                        Mark as Paid
                    </button>
                    <div class="collapse mb-2" id="markPaidForm">
                        <div class="card card-body">
                            <form action="{{ route('admin.commission-payouts.mark-paid', $payout) }}" method="POST">
                                @csrf
                                <div class="mb-2">
                                    <label class="form-label form-label-sm">Payment Reference <span class="text-danger">*</span></label>
                                    <input type="text" name="payment_reference" class="form-control form-control-sm" required>
                                </div>
                                <div class="mb-2">
                                    <label class="form-label form-label-sm">Method</label>
                                    <input type="text" name="payment_method" class="form-control form-control-sm" placeholder="Bank transfer, PayPal…">
                                </div>
                                <div class="mb-2">
                                    <label class="form-label form-label-sm">Notes</label>
                                    <textarea name="notes" class="form-control form-control-sm" rows="2"></textarea>
                                </div>
                                <button class="btn btn-success btn-sm w-100">Confirm Payment</button>
                            </form>
                        </div>
                    </div>

                    <form action="{{ route('admin.commission-payouts.cancel', $payout) }}" method="POST"
                          onsubmit="return confirm('Cancel this payout? Ledger entries will be released.')">
                        @csrf @method('DELETE')
                        <button class="btn btn-outline-danger w-100">Cancel Payout</button>
                    </form>
                @endif
            </div>
        </div>
    </div>

    <div class="col-md-8 mb-3">
        <div class="card">
            <div class="card-header"><h5 class="mb-0">Included Ledger Entries</h5></div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>#</th>
                                <th>Date</th>
                                <th>Type</th>
                                <th>Amount</th>
                                <th>Plan</th>
                                <th>Notes</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($payout->ledgerEntries as $entry)
                            <tr>
                                <td class="text-muted small">{{ $entry->id }}</td>
                                <td class="small">{{ $entry->created_at->format('M j, Y') }}</td>
                                <td>
                                    @if($entry->type === 'credit')
                                        <span class="badge bg-success">Credit</span>
                                    @else
                                        <span class="badge bg-danger">Debit</span>
                                    @endif
                                </td>
                                <td class="{{ $entry->type === 'debit' ? 'text-danger' : '' }} fw-semibold">
                                    {{ $entry->type === 'debit' ? '-' : '' }}${{ number_format($entry->amount, 2) }}
                                </td>
                                <td class="small">{{ $entry->commissionPlan?->name ?? '—' }}</td>
                                <td class="small text-muted">{{ $entry->notes }}</td>
                            </tr>
                            @empty
                            <tr><td colspan="6" class="text-center text-muted py-3">No entries.</td></tr>
                            @endforelse
                        </tbody>
                        @if($payout->ledgerEntries->count() > 0)
                        <tfoot class="table-light fw-bold">
                            <tr>
                                <td colspan="3">Total</td>
                                <td>${{ number_format($payout->total_amount, 2) }}</td>
                                <td colspan="2"></td>
                            </tr>
                        </tfoot>
                        @endif
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
