@extends('layouts.member')

@section('title', 'Product Sales')
@section('page-title', 'Product Sales')

@section('breadcrumb')
    <li class="breadcrumb-item active">Product Sales</li>
@endsection

@section('content')

@php
    $statusColors = [
        'new'        => 'secondary',
        'handed_off' => 'info',
        'converted'  => 'success',
        'refunded'   => 'danger',
        'lost'       => 'dark',
    ];
@endphp

{{-- ── First-100 offer, while places remain ─────────────────────────────── --}}
@if ($buyYours)
    <div class="mb-3">
        @include('member.vendor.partials.buy-yours', ['variant' => 'hero'])
    </div>
@endif

{{-- Gold is spent on the one metric that matters. Making all four gold would
     leave the partner no idea which number to read first. --}}
<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3">
        <div class="card mb-0"><div class="card-body text-center py-4">
            <div class="q3-stat-value">{{ $counts->sum() }}</div>
            <div class="q3-stat-label">Total Enquiries</div>
        </div></div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card mb-0"><div class="card-body text-center py-4">
            <div class="q3-stat-value">{{ $counts['handed_off'] ?? 0 }}</div>
            <div class="q3-stat-label">At Checkout</div>
        </div></div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card mb-0"><div class="card-body text-center py-4">
            <div class="q3-stat-value">{{ $counts['converted'] ?? 0 }}</div>
            <div class="q3-stat-label">Sold</div>
        </div></div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card mb-0"><div class="card-body text-center py-4">
            <div class="q3-stat-value q3-stat-value--gold">${{ number_format($earned, 2) }}</div>
            {{-- Accrued, not banked. Commission is pending until a payout run
                 approves it and the vendor's refund window has closed. --}}
            <div class="q3-stat-label">Commission Accrued</div>
        </div></div>
    </div>
</div>

{{-- ── Promotion, once every place is taken ─────────────────────────────── --}}
@if ($promotion && ! $buyYours)
    <div class="card mb-3">
        <div class="card-body py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h5 class="mb-0">{{ $promotion['promotion']['name'] }}</h5>
                <span class="text-muted small">
                    {{ $promotion['full'] ? 'All '.$promotion['cap'].' places are filled.' : $promotion['filled'].' of '.$promotion['cap'].' sold.' }}
                </span>
            </div>
            <a class="btn btn-sm btn-outline-primary" href="{{ route('member.sales.promotion') }}">View leaderboard</a>
        </div>
    </div>
@endif

{{-- ── Share links ──────────────────────────────────────────────────────── --}}
<div class="card mb-3">
    <div class="card-header py-3">
        <h5 class="mb-0">Your Share Links</h5>
        <span class="text-muted small">Every sale made through these links is recorded against your code
            <strong>{{ $user->referral_code }}</strong>.</span>
    </div>
    <div class="card-body">
        @forelse ($vendors as $slug => $vendor)
            @foreach ($vendor['products'] ?? [] as $productKey => $product)
                @php $shareUrl = route('vendor.product', [$user->referral_code, $slug, $productKey]); @endphp
                <div class="mb-3 pb-3 border-bottom">
                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2">
                        <div>
                            <div class="fw-semibold">{{ $product['name'] }}</div>
                            <div class="text-muted small">{{ $vendor['name'] }}</div>
                        </div>
                        <a class="btn btn-sm {{ $buyYours ? 'btn-primary' : 'btn-outline-primary' }}"
                           href="{{ route('member.sales.buy', [$slug, $productKey]) }}">
                            {{ $buyYours ? 'Buy yours' : 'Buy for yourself' }}
                        </a>
                    </div>
                    <div class="input-group">
                        <input type="text" class="form-control" readonly value="{{ $shareUrl }}"
                               id="share-{{ $slug }}-{{ $productKey }}">
                        <button class="btn btn-primary js-copy" type="button"
                                data-target="share-{{ $slug }}-{{ $productKey }}">Copy</button>
                        <a class="btn btn-outline-secondary" href="{{ $shareUrl }}" target="_blank" rel="noopener">Preview</a>
                    </div>
                </div>
            @endforeach
            @if ($loop->last)
                {{-- Said where the links are, because this is where a partner
                     would think of using their own. --}}
                <p class="text-muted small mb-0">
                    Buying for yourself? Use <strong>{{ $buyYours ? 'Buy yours' : 'Buy for yourself' }}</strong>. Your own purchase counts as your
                    sale, and the commission on it goes to your sponsor. The same applies through any share link:
                    an order placed with your account's email or phone number is treated as your own purchase.
                </p>
            @endif
        @empty
            <p class="text-muted mb-0">No products are available to sell yet.</p>
        @endforelse
    </div>
</div>

{{-- ── Leads ────────────────────────────────────────────────────────────── --}}
<div class="card">
    <div class="card-header py-3"><h5 class="mb-0">Your Enquiries</h5></div>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>Reference</th><th>Customer</th><th>Product</th>
                    <th>Status</th><th>Order Total</th><th>Captured</th><th></th>
                </tr>
            </thead>
            <tbody>
            @forelse ($leads as $lead)
                <tr>
                    <td><code>{{ $lead->public_ref }}</code></td>
                    <td>
                        {{ $lead->fullName() }}
                        <div class="text-muted small">{{ $lead->email }}</div>
                    </td>
                    <td>{{ $lead->productName() }}</td>
                    <td>
                        <span class="badge bg-{{ $statusColors[$lead->status] ?? 'secondary' }}">
                            {{ Str::headline($lead->status) }}
                        </span>
                        @if ($lead->isOwnPurchase())
                            <span class="badge bg-info ms-1">
                                {{ (int) $lead->buyer_user_id === (int) $user->id ? 'Your own purchase' : "A partner's own purchase" }}
                            </span>
                        @elseif ($lead->attribution === 'review')
                            <span class="badge bg-warning ms-1">Being checked</span>
                        @endif
                    </td>
                    <td>{{ $lead->amountDecimal() ? $lead->currency.' '.$lead->amountDecimal() : '—' }}</td>
                    <td class="text-muted small">{{ $lead->created_at->format('d M Y') }}</td>
                    <td class="text-end">
                        <a class="btn btn-sm btn-outline-primary"
                           href="{{ route('member.sales.show', $lead->id) }}">View</a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="text-center text-muted py-4">
                    No enquiries yet. Share one of the links above to get started.
                </td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @if ($leads->hasPages())
        <div class="card-footer">{{ $leads->links() }}</div>
    @endif
</div>

{{-- ── Partners' own purchases ──────────────────────────────────────────── --}}
@if ($teamPurchases->isNotEmpty())
    <div class="card mt-3">
        <div class="card-header py-3">
            <h5 class="mb-0">Your Partners' Own Purchases</h5>
            <span class="text-muted small">Partners you sponsor who bought for themselves. The commission on these is yours.</span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr><th>Partner</th><th>Product</th><th>Units</th><th>Status</th><th>Commission</th><th>Sold</th></tr>
                </thead>
                <tbody>
                @foreach ($teamPurchases as $purchase)
                    <tr>
                        <td>{{ $purchase->buyer?->name ?? 'Former partner' }}</td>
                        <td>{{ $purchase->productName() }}</td>
                        <td>{{ $purchase->quantity }}</td>
                        <td>
                            <span class="badge bg-{{ $statusColors[$purchase->status] ?? 'secondary' }}">
                                {{ Str::headline($purchase->status) }}
                            </span>
                        </td>
                        <td>
                            @if ($purchase->commissionCredit && $purchase->commissionCredit->status !== 'voided')
                                ${{ number_format((float) $purchase->commissionCredit->amount, 2) }}
                            @else
                                —
                            @endif
                        </td>
                        <td class="text-muted small">{{ optional($purchase->converted_at)->format('d M Y') }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif

@endsection

@push('scripts')
<script>
    document.querySelectorAll('.js-copy').forEach(function (button) {
        button.addEventListener('click', function () {
            var field = document.getElementById(button.dataset.target);
            // execCommand fallback: navigator.clipboard needs a secure context,
            // which a local or plain-HTTP staging host is not.
            if (navigator.clipboard) {
                navigator.clipboard.writeText(field.value);
            } else {
                field.select();
                document.execCommand('copy');
            }
            var original = button.textContent;
            button.textContent = 'Copied';
            setTimeout(function () { button.textContent = original; }, 1500);
        });
    });
</script>
@endpush
