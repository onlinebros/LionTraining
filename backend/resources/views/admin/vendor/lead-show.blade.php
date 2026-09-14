@extends('layouts.admin')

@section('title', 'Lead '.$lead->public_ref)
@section('page-title', 'Lead '.$lead->public_ref)

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('admin.vendor-leads.index') }}">Vendor Leads</a></li>
    <li class="breadcrumb-item active">{{ $lead->public_ref }}</li>
@endsection

@section('content')

@if(session('status'))<div class="alert alert-success py-2">{{ session('status') }}</div>@endif
@if($errors->any())<div class="alert alert-danger py-2">{{ $errors->first() }}</div>@endif

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header py-3"><h5 class="mb-0">Customer &amp; attribution</h5></div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-sm-4 text-muted fw-normal">Reference</dt>
                    <dd class="col-sm-8"><code>{{ $lead->public_ref }}</code></dd>

                    <dt class="col-sm-4 text-muted fw-normal">Customer</dt>
                    <dd class="col-sm-8">{{ $lead->fullName() }} — {{ $lead->email }}
                        @if ($lead->phone)<br>{{ $lead->phone }}@endif
                    </dd>

                    @if ($lead->address_line1)
                        <dt class="col-sm-4 text-muted fw-normal">Ship to</dt>
                        <dd class="col-sm-8">
                            {{ $lead->address_line1 }}@if($lead->address_line2), {{ $lead->address_line2 }}@endif<br>
                            {{ collect([$lead->city, $lead->state, $lead->postal_code, $lead->country])->filter()->implode(', ') }}
                        </dd>
                    @endif

                    <dt class="col-sm-4 text-muted fw-normal">Partner</dt>
                    <dd class="col-sm-8">
                        {{ $lead->member?->name ?? '(account removed)' }}
                        <span class="text-muted">— {{ $lead->referral_code }}</span>
                    </dd>

                    <dt class="col-sm-4 text-muted fw-normal">Product</dt>
                    <dd class="col-sm-8">{{ $lead->vendorName() }} — {{ $lead->productName() }} × {{ $lead->quantity }}</dd>

                    @if ($lead->notes)
                        <dt class="col-sm-4 text-muted fw-normal">Notes</dt>
                        <dd class="col-sm-8">{{ $lead->notes }}</dd>
                    @endif

                    @if ($lead->checkout_url)
                        <dt class="col-sm-4 text-muted fw-normal">Handoff URL</dt>
                        <dd class="col-sm-8"><small class="text-break">{{ $lead->checkout_url }}</small></dd>
                    @endif
                </dl>
            </div>
        </div>

        {{-- Who the sale counts for and who is paid, which is not always the
             partner whose link was used: a partner's own purchase counts for
             them and pays their sponsor. --}}
        @php
            $attributionLabels = [
                'customer' => ['Customer sale', 'secondary'],
                'self'     => ['Own purchase', 'info'],
                'review'   => ['Needs review', 'warning'],
            ];
            [$attributionLabel, $attributionColor] = $attributionLabels[$lead->attribution] ?? $attributionLabels['customer'];
        @endphp
        <div class="card mt-3">
            <div class="card-header py-3 d-flex justify-content-between align-items-center">
                <h5 class="mb-0">Credit &amp; commission</h5>
                <span class="badge bg-{{ $attributionColor }}">{{ $attributionLabel }}</span>
            </div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-sm-4 text-muted fw-normal">Share link</dt>
                    <dd class="col-sm-8">{{ $lead->member?->name ?? $lead->referral_code }}</dd>

                    <dt class="col-sm-4 text-muted fw-normal">Buyer</dt>
                    <dd class="col-sm-8">
                        @if ($lead->buyer)
                            <a href="{{ route('admin.users.show', $lead->buyer->id) }}">{{ $lead->buyer->name }}</a>
                            <span class="text-muted">(partner)</span>
                        @else
                            Customer
                        @endif
                    </dd>

                    <dt class="col-sm-4 text-muted fw-normal">Counts for</dt>
                    <dd class="col-sm-8">{{ $lead->creditedMember?->name ?? '—' }}</dd>

                    <dt class="col-sm-4 text-muted fw-normal">Commission to</dt>
                    <dd class="col-sm-8">
                        @if ($lead->attribution === 'review')
                            <span class="text-warning">Held until decided</span>
                        @elseif ($lead->earner)
                            {{ $lead->earner->name }}
                        @else
                            No one
                        @endif
                    </dd>

                    @if ($lead->attribution_reason)
                        <dt class="col-sm-4 text-muted fw-normal">Why</dt>
                        <dd class="col-sm-8">{{ $lead->attribution_reason }}</dd>
                    @endif

                    @if ($lead->attribution_resolved_at)
                        <dt class="col-sm-4 text-muted fw-normal">Decided</dt>
                        <dd class="col-sm-8">
                            {{ $lead->attributionResolvedBy?->name ?? 'An admin' }},
                            {{ $lead->attribution_resolved_at->format('d M Y H:i') }}
                        </dd>
                    @endif
                </dl>

                @if (! $lead->commission_ledger_id && ($lead->buyer || $lead->attribution !== 'customer'))
                    <hr class="my-3">
                    <p class="text-muted small mb-2">
                        Decide who this order counts for.
                        {{ $lead->isConverted()
                            ? 'Commission is raised as soon as you decide.'
                            : 'Your decision stands when the payment is confirmed.' }}
                    </p>
                    <form method="POST" action="{{ route('admin.vendor-leads.attribution', $lead->id) }}"
                          class="d-flex flex-wrap gap-2">
                        @csrf
                        @if ($lead->buyer)
                            <button class="btn btn-sm btn-outline-info" name="decision" value="self">
                                {{ $lead->buyer->name }}'s own purchase: pay
                                {{ $lead->buyer->sponsor?->name ?? 'no one (no sponsor)' }}
                            </button>
                        @endif
                        <button class="btn btn-sm btn-outline-secondary" name="decision" value="customer">
                            Customer sale: pay {{ $lead->member?->name ?? 'the link owner' }}
                        </button>
                    </form>
                @endif
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card">
            <div class="card-header py-3"><h5 class="mb-0">Confirmation</h5></div>
            <div class="card-body">
                @if ($lead->isConverted())
                    <dl class="row mb-0">
                        <dt class="col-6 text-muted fw-normal">Order total</dt>
                        <dd class="col-6 text-end fw-bold">{{ $lead->currency }} {{ $lead->amountDecimal() }}</dd>

                        <dt class="col-6 text-muted fw-normal">Confirmed via</dt>
                        <dd class="col-6 text-end">
                            <span class="badge bg-{{ $lead->confirmed_via === 'webhook' ? 'success' : 'warning' }}">
                                {{ Str::headline((string) $lead->confirmed_via) }}
                            </span>
                        </dd>

                        @if ($lead->confirmedBy)
                            <dt class="col-6 text-muted fw-normal">Confirmed by</dt>
                            <dd class="col-6 text-end">{{ $lead->confirmedBy->name }}</dd>
                        @endif

                        @if ($lead->vendor_order_ref)
                            <dt class="col-6 text-muted fw-normal">Vendor order</dt>
                            <dd class="col-6 text-end"><code>{{ $lead->vendor_order_ref }}</code></dd>
                        @endif

                        @if ($lead->subtotal_amount)
                            {{-- The quote as it was built, kept rather than
                                 recomputed: prices, rates and tax rules all move,
                                 and an order has to still explain itself later. --}}
                            <dt class="col-12 text-muted fw-normal mt-2" style="font-size:.72rem;letter-spacing:.09em;text-transform:uppercase;">Breakdown</dt>
                            <dt class="col-6 text-muted fw-normal">Equipment</dt>
                            <dd class="col-6 text-end">${{ number_format($lead->subtotal_amount / 100, 2) }}</dd>

                            <dt class="col-6 text-muted fw-normal">
                                Shipping
                                @if ($lead->shipping_rate_source)
                                    <span class="badge bg-{{ $lead->shipping_rate_source === 'carrier' ? 'success' : 'secondary' }} ms-1"
                                          title="How this figure was arrived at">{{ $lead->shipping_rate_source }}</span>
                                @endif
                            </dt>
                            <dd class="col-6 text-end">${{ number_format(($lead->shipping_amount ?? 0) / 100, 2) }}</dd>

                            <dt class="col-6 text-muted fw-normal">Handling</dt>
                            <dd class="col-6 text-end">${{ number_format(($lead->handling_amount ?? 0) / 100, 2) }}</dd>

                            <dt class="col-6 text-muted fw-normal">Tax</dt>
                            <dd class="col-6 text-end">${{ number_format(($lead->tax_amount ?? 0) / 100, 2) }}</dd>
                        @endif

                        @if ($lead->stripe_receipt_url)
                            <dt class="col-6 text-muted fw-normal">Receipt</dt>
                            <dd class="col-6 text-end">
                                {{-- Support's most common request, answerable here
                                     instead of by emailing the vendor. --}}
                                <a href="{{ $lead->stripe_receipt_url }}" target="_blank" rel="noopener">Customer receipt</a>
                            </dd>
                        @endif

                        <dt class="col-6 text-muted fw-normal">Owed to us</dt>
                        <dd class="col-6 text-end fw-bold">${{ number_format(($lead->our_share_amount ?? 0) / 100, 2) }}</dd>

                        <dt class="col-6 text-muted fw-normal">Billing</dt>
                        <dd class="col-6 text-end">
                            @if ($lead->settled_at)
                                <span class="badge bg-success">Settled</span>
                            @elseif ($lead->invoiced_at)
                                <span class="badge bg-info">Invoiced {{ $lead->invoice_reference }}</span>
                            @else
                                <a href="{{ route('admin.vendor-leads.reconciliation') }}">Not yet invoiced</a>
                            @endif
                        </dd>

                        <dt class="col-6 text-muted fw-normal">Commission</dt>
                        <dd class="col-6 text-end">
                            @if ($lead->commission_ledger_id)
                                <a href="{{ route('admin.commission-ledger.index') }}">
                                    Ledger #{{ $lead->commission_ledger_id }}
                                </a>
                            @else
                                <span class="text-warning">Not raised</span>
                            @endif
                        </dd>
                    </dl>

                    {{-- Fulfilment. They ship, so tracking reaches us however
                         they choose to send it; until there is an API for it an
                         admin types it in, and the customer and partner both
                         see it on their own screens. --}}
                    <hr class="my-3">
                    @if ($lead->tracking_number)
                        <div class="small">
                            <span class="text-muted">Shipped</span>
                            {{ optional($lead->shipped_at)->format('d M Y') }} —
                            <strong>{{ $lead->carrier }}</strong> <code>{{ $lead->tracking_number }}</code>
                        </div>
                    @else
                        <form method="POST" action="{{ route('admin.vendor-leads.fulfil', $lead->id) }}" class="row g-2 align-items-end">
                            @csrf
                            <div class="col-4">
                                <label class="form-label small text-muted mb-1">Carrier</label>
                                <input type="text" name="carrier" value="{{ $lead->carrier ?: 'FedEx' }}"
                                       class="form-control form-control-sm">
                            </div>
                            <div class="col-8">
                                <label class="form-label small text-muted mb-1">Tracking number</label>
                                <div class="input-group input-group-sm">
                                    <input type="text" name="tracking_number" class="form-control" required>
                                    <button class="btn btn-outline-primary">Record</button>
                                </div>
                            </div>
                        </form>
                    @endif
                @else
                    {{-- The manual path. Designed fallback for a vendor who will
                         not send webhooks, not a workaround — but it records that
                         a person asserted the amount rather than a signature
                         proving it. --}}
                    <p class="text-muted small">
                        No confirmation received. If {{ $lead->vendorName() }} has confirmed this order
                        out of band, record it here. Commission is raised on it at the same time, unless
                        the order is held for attribution review.
                    </p>

                    <form method="POST" action="{{ route('admin.vendor-leads.convert', $lead->id) }}">
                        @csrf
                        <div class="row g-2">
                            <div class="col-7">
                                <label class="form-label small text-muted mb-1">Order total</label>
                                <input type="number" step="0.01" min="0.01" name="amount"
                                       class="form-control form-control-sm" required>
                            </div>
                            <div class="col-5">
                                <label class="form-label small text-muted mb-1">Currency</label>
                                <input type="text" name="currency" value="USD" maxlength="3"
                                       class="form-control form-control-sm" required>
                            </div>
                            <div class="col-12">
                                <label class="form-label small text-muted mb-1">Vendor order ref (optional)</label>
                                <input type="text" name="vendor_order_ref" class="form-control form-control-sm">
                            </div>
                            <div class="col-12 mt-3">
                                <button class="btn btn-sm btn-success w-100">Mark converted &amp; raise commission</button>
                            </div>
                        </div>
                    </form>

                    <form method="POST" action="{{ route('admin.vendor-leads.lost', $lead->id) }}" class="mt-2">
                        @csrf
                        <button class="btn btn-sm btn-outline-secondary w-100">Mark as lost</button>
                    </form>
                @endif
            </div>
        </div>
    </div>
</div>

@endsection
