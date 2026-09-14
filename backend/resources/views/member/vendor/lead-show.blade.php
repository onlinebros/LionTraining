@extends('layouts.member')

@section('title', 'Enquiry '.$lead->public_ref)
@section('page-title', 'Enquiry '.$lead->public_ref)

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('member.sales.index') }}">Product Sales</a></li>
    <li class="breadcrumb-item active">{{ $lead->public_ref }}</li>
@endsection

@section('content')

@php
    $statusColors = [
        'new' => 'secondary', 'handed_off' => 'info',
        'converted' => 'success', 'refunded' => 'danger', 'lost' => 'dark',
    ];
@endphp

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header py-3 d-flex justify-content-between align-items-center">
                <h5 class="mb-0">Customer</h5>
                <span class="badge bg-{{ $statusColors[$lead->status] ?? 'secondary' }}">
                    {{ Str::headline($lead->status) }}
                </span>
            </div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-sm-4 text-muted fw-normal">Name</dt>
                    <dd class="col-sm-8">{{ $lead->fullName() }}</dd>

                    <dt class="col-sm-4 text-muted fw-normal">Email</dt>
                    <dd class="col-sm-8"><a href="mailto:{{ $lead->email }}">{{ $lead->email }}</a></dd>

                    @if ($lead->phone)
                        <dt class="col-sm-4 text-muted fw-normal">Phone</dt>
                        <dd class="col-sm-8"><a href="tel:{{ $lead->phone }}">{{ $lead->phone }}</a></dd>
                    @endif

                    @if ($lead->company)
                        <dt class="col-sm-4 text-muted fw-normal">Company</dt>
                        <dd class="col-sm-8">{{ $lead->company }}</dd>
                    @endif

                    @if ($lead->address_line1)
                        <dt class="col-sm-4 text-muted fw-normal">Address</dt>
                        <dd class="col-sm-8">
                            {{ $lead->address_line1 }}@if($lead->address_line2), {{ $lead->address_line2 }}@endif<br>
                            {{ collect([$lead->city, $lead->state, $lead->postal_code])->filter()->implode(', ') }}
                        </dd>
                    @endif

                    @foreach ($lead->qualifiers ?? [] as $key => $value)
                        @if ($value)
                            <dt class="col-sm-4 text-muted fw-normal">{{ Str::headline($key) }}</dt>
                            <dd class="col-sm-8">{{ $value }}</dd>
                        @endif
                    @endforeach

                    @if ($lead->notes)
                        <dt class="col-sm-4 text-muted fw-normal">Notes</dt>
                        <dd class="col-sm-8">{{ $lead->notes }}</dd>
                    @endif
                </dl>
            </div>
            @if ($lead->crmContact)
                <div class="card-footer">
                    <a class="btn btn-sm btn-outline-primary"
                       href="{{ route('member.crm.contacts.show', $lead->crmContact->id) }}">
                        Open in CRM
                    </a>
                </div>
            @endif
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card">
            <div class="card-header py-3"><h5 class="mb-0">Order</h5></div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-6 text-muted fw-normal">Reference</dt>
                    <dd class="col-6 text-end"><code>{{ $lead->public_ref }}</code></dd>

                    <dt class="col-6 text-muted fw-normal">Product</dt>
                    <dd class="col-6 text-end">{{ $lead->productName() }}</dd>

                    <dt class="col-6 text-muted fw-normal">Units</dt>
                    <dd class="col-6 text-end">{{ $lead->quantity }}</dd>

                    <dt class="col-6 text-muted fw-normal">Captured</dt>
                    <dd class="col-6 text-end">{{ $lead->created_at->format('d M Y, H:i') }}</dd>

                    @if ($lead->handed_off_at)
                        <dt class="col-6 text-muted fw-normal">Sent to checkout</dt>
                        <dd class="col-6 text-end">{{ $lead->handed_off_at->format('d M Y, H:i') }}</dd>
                    @endif

                    @if ($lead->converted_at)
                        <dt class="col-6 text-muted fw-normal">Sold</dt>
                        <dd class="col-6 text-end">{{ $lead->converted_at->format('d M Y, H:i') }}</dd>

                        <dt class="col-6 text-muted fw-normal">Order total</dt>
                        <dd class="col-6 text-end fw-bold">{{ $lead->currency }} {{ $lead->amountDecimal() }}</dd>
                    @endif
                </dl>
            </div>
        </div>

        @if ($lead->isOwnPurchase())
            <div class="alert alert-info mt-3 mb-0 py-3 small">
                @if ((int) $lead->buyer_user_id === (int) auth()->id())
                    This is your own purchase. It counts as your sale, and the commission on it goes to your sponsor.
                @else
                    This order was placed by another partner buying for themselves. It counts as their sale,
                    and the commission goes to their sponsor.
                @endif
            </div>
        @elseif ($lead->attribution === 'review')
            <div class="alert alert-warning mt-3 mb-0 py-3 small">
                The details on this order match a partner account, so it is being checked before any
                commission is paid.
            </div>
        @endif

        @if (! $lead->isConverted() && $lead->status !== 'refunded')
            <div class="alert alert-info mt-3 mb-0 py-3 small">
                {{-- Managing the expectation directly. A partner who does not know
                     the vendor confirms separately reads a quiet pipeline as a
                     broken one and raises a support ticket. --}}
                This enquiry is with {{ $vendor['name'] ?? 'the supplier' }}. It shows as sold here once
                they confirm the payment, which can lag the customer's purchase.
            </div>
        @endif
    </div>
</div>

@endsection
