@extends('layouts.product-partner')

@section('title', 'Order '.$order->public_ref)
@section('page-title', 'Order '.$order->public_ref)

@php
    $fmt = fn ($m) => $m === null ? '—' : '$'.number_format(((int) $m) / 100, 2);
    $ctx = !empty($vendorSwitch) ? ['vendor' => $vendor] : [];
@endphp

@section('content')

<div class="mb-3">
    <a href="{{ route('product-partner.sales', $ctx) }}" class="btn btn-sm btn-outline-light">&larr; All sales</a>
</div>

<div class="row g-3">
    <div class="col-xl-7">
        <div class="card">
            <div class="card-header py-3 d-flex justify-content-between align-items-center">
                <h5 class="mb-0">Customer</h5>
                @if ($order->status === 'refunded')
                    <span class="badge bg-danger">Refunded {{ optional($order->refunded_at)->format('d M Y') }}</span>
                @else
                    <span class="badge bg-success">Confirmed {{ optional($order->converted_at)->format('d M Y') }}</span>
                @endif
            </div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-sm-4 f-light">Name</dt><dd class="col-sm-8">{{ $order->fullName() }}</dd>
                    @if ($order->company)
                        <dt class="col-sm-4 f-light">Company</dt><dd class="col-sm-8">{{ $order->company }}</dd>
                    @endif
                    <dt class="col-sm-4 f-light">Email</dt><dd class="col-sm-8">{{ $order->email }}</dd>
                    <dt class="col-sm-4 f-light">Phone</dt><dd class="col-sm-8">{{ $order->phone ?: '—' }}</dd>
                    <dt class="col-sm-4 f-light">Ship to</dt>
                    <dd class="col-sm-8">
                        {{ $order->address_line1 }}<br>
                        @if ($order->address_line2){{ $order->address_line2 }}<br>@endif
                        {{ $order->city }}, {{ $order->state }} {{ $order->postal_code }}<br>
                        {{ $order->country }}
                    </dd>
                    @if ($order->notes)
                        <dt class="col-sm-4 f-light">Notes</dt><dd class="col-sm-8">{{ $order->notes }}</dd>
                    @endif
                </dl>
            </div>
        </div>

        @if (!empty($order->qualifiers))
            <div class="card">
                <div class="card-header py-3"><h5 class="mb-0">What they told us about the site</h5></div>
                <div class="card-body">
                    <dl class="row mb-0">
                        @foreach ((array) $order->qualifiers as $key => $value)
                            <dt class="col-sm-4 f-light">{{ \Illuminate\Support\Str::headline($key) }}</dt>
                            <dd class="col-sm-8">{{ is_array($value) ? implode(', ', $value) : $value }}</dd>
                        @endforeach
                    </dl>
                </div>
            </div>
        @endif

        <div class="card">
            <div class="card-header py-3"><h5 class="mb-0">Fulfilment</h5></div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-sm-4 f-light">Status</dt>
                    <dd class="col-sm-8">
                        @if ($order->shipped_at)
                            Shipped {{ $order->shipped_at->format('d M Y') }}
                        @else
                            Not yet shipped
                        @endif
                    </dd>
                    <dt class="col-sm-4 f-light">Carrier</dt><dd class="col-sm-8">{{ $order->carrier ?: '—' }}</dd>
                    <dt class="col-sm-4 f-light">Tracking</dt><dd class="col-sm-8">{{ $order->tracking_number ?: '—' }}</dd>
                </dl>
                {{-- Recorded by us from what the vendor reports. Said plainly so
                     nobody waits for this screen to update itself. --}}
                <p class="f-light small mb-0 mt-3">
                    Tracking is recorded by Quantum 3 from what your team reports. Send it to your
                    Quantum 3 contact and it appears here, and on the partner's and customer's records.
                </p>
            </div>
        </div>
    </div>

    <div class="col-xl-5">
        <div class="card">
            <div class="card-header py-3"><h5 class="mb-0">The order</h5></div>
            <div class="card-body">
                <div class="d-flex justify-content-between py-2 border-bottom">
                    <span class="f-light">{{ $order->quantity }} × {{ $order->productName() }}</span>
                    <span>{{ $fmt($order->subtotal_amount) }}</span>
                </div>
                <div class="d-flex justify-content-between py-2 border-bottom">
                    <span class="f-light">Shipping</span><span>{{ $fmt($order->shipping_amount) }}</span>
                </div>
                <div class="d-flex justify-content-between py-2 border-bottom">
                    <span class="f-light">Handling</span><span>{{ $fmt($order->handling_amount) }}</span>
                </div>
                <div class="d-flex justify-content-between py-2 border-bottom">
                    <span class="f-light">Tax</span><span>{{ $fmt($order->tax_amount) }}</span>
                </div>
                <div class="d-flex justify-content-between py-2 border-bottom">
                    <span class="fw-bold">Charged</span>
                    <span class="fw-bold">{{ $order->currency }} {{ $order->amountDecimal() }}</span>
                </div>
                <div class="d-flex justify-content-between py-3">
                    <span class="f-light">Owed to Quantum 3</span>
                    <span class="q3-stat-value q3-stat-value--gold" style="font-size:1.2rem;">
                        {{ $fmt($order->our_share_amount) }}
                    </span>
                </div>

                <div class="f-light small">
                    @if ($order->settled_at)
                        Settled {{ $order->settled_at->format('d M Y') }}
                        @if ($order->invoice_reference) on invoice <code>{{ $order->invoice_reference }}</code>@endif.
                    @elseif ($order->invoiced_at)
                        Invoiced {{ $order->invoiced_at->format('d M Y') }}
                        @if ($order->invoice_reference) as <code>{{ $order->invoice_reference }}</code>@endif,
                        awaiting payment.
                    @else
                        Not yet invoiced.
                    @endif
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header py-3"><h5 class="mb-0">Sourced by</h5></div>
            <div class="card-body">
                @if ($order->member)
                    <div class="fw-bold">{{ $order->member->name }}</div>
                    <div class="f-light small">Quantum 3 partner &middot; code {{ $order->referral_code }}</div>
                    {{-- Contact details are not here on purpose: the partner is
                         our relationship, and reaching them goes through us
                         until there is a channel built for it. --}}
                    <p class="f-light small mb-0 mt-3">
                        To work this deal with the partner, ask your Quantum 3 contact to connect you.
                    </p>
                @else
                    <span class="f-light">No partner is attached to this order.</span>
                @endif
            </div>
        </div>
    </div>
</div>

@endsection
