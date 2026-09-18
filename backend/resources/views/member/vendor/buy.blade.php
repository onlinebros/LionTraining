@extends('layouts.member')

@section('title', 'Buy for Yourself')
@section('page-title', 'Buy for Yourself')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('member.sales.index') }}">Product Sales</a></li>
    <li class="breadcrumb-item active">Buy for Yourself</li>
@endsection

@section('content')

@php
    $nameParts = preg_split('/\s+/', trim((string) $user->name), 2);
@endphp

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header py-3">
                <h5 class="mb-0">{{ $product['name'] }}</h5>
                <span class="text-muted small">
                    Sold and shipped by {{ $vendor['legal_name'] ?? $vendor['name'] }}.
                    You will see shipping, tax and the total before you pay.
                </span>
            </div>
            <div class="card-body">
                @if ($errors->any())
                    <div class="alert alert-danger py-2">{{ $errors->first() }}</div>
                @endif

                <form method="POST" action="{{ route('member.sales.buy.store', [$vendorSlug, $productKey]) }}">
                    @csrf
                    <div class="row g-3">
                        <div class="col-sm-6">
                            <label class="form-label" for="first_name">First name</label>
                            <input class="form-control" id="first_name" name="first_name" required maxlength="100"
                                   autocomplete="given-name" value="{{ old('first_name', $nameParts[0] ?? '') }}">
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label" for="last_name">Last name</label>
                            <input class="form-control" id="last_name" name="last_name" maxlength="100"
                                   autocomplete="family-name" value="{{ old('last_name', $nameParts[1] ?? '') }}">
                        </div>

                        <div class="col-sm-6">
                            <label class="form-label" for="email">Email</label>
                            <input class="form-control" id="email" type="email" value="{{ $user->email }}" readonly
                                   aria-describedby="email-help">
                            <div class="form-text" id="email-help">Your order is placed under your account email.</div>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label" for="phone">Phone</label>
                            <input class="form-control" id="phone" name="phone" type="tel" maxlength="40"
                                   autocomplete="tel" value="{{ old('phone', $user->phone) }}">
                        </div>

                        <div class="col-12">
                            <label class="form-label" for="company">Company <span class="text-muted">(optional)</span></label>
                            <input class="form-control" id="company" name="company" maxlength="150"
                                   autocomplete="organization" value="{{ old('company') }}">
                        </div>

                        <div class="col-12">
                            <label class="form-label" for="address_line1">Shipping address</label>
                            <input class="form-control" id="address_line1" name="address_line1" required maxlength="191"
                                   autocomplete="address-line1" value="{{ old('address_line1', $user->address_line1) }}">
                        </div>
                        <div class="col-12">
                            <label class="form-label visually-hidden" for="address_line2">Address line 2</label>
                            <input class="form-control" id="address_line2" name="address_line2" maxlength="191"
                                   placeholder="Apartment, suite, unit (optional)" autocomplete="address-line2"
                                   value="{{ old('address_line2', $user->address_line2) }}">
                        </div>
                        <div class="col-sm-5">
                            <label class="form-label" for="city">City</label>
                            <input class="form-control" id="city" name="city" required maxlength="100"
                                   autocomplete="address-level2" value="{{ old('city', $user->city) }}">
                        </div>
                        <div class="col-sm-3">
                            <label class="form-label" for="state">State</label>
                            <input class="form-control" id="state" name="state" required maxlength="100"
                                   autocomplete="address-level1" value="{{ old('state', $user->state) }}">
                        </div>
                        <div class="col-sm-4">
                            <label class="form-label" for="postal_code">ZIP code</label>
                            <input class="form-control" id="postal_code" name="postal_code" required maxlength="20"
                                   autocomplete="postal-code" value="{{ old('postal_code', $user->postal_code) }}">
                        </div>

                        <div class="col-sm-4">
                            <label class="form-label" for="quantity">Systems</label>
                            <input class="form-control" id="quantity" name="quantity" type="number" min="1" max="99" required
                                   value="{{ old('quantity', 1) }}" aria-describedby="quantity-help">
                        </div>
                        <div class="col-sm-8 d-flex align-items-end">
                            <div class="form-text" id="quantity-help">
                                {{ $product['sizing']['help'] ?? 'One system per furnace or air handler.' }}
                            </div>
                        </div>

                        <div class="col-12">
                            <label class="form-label" for="notes">Notes <span class="text-muted">(optional)</span></label>
                            <textarea class="form-control" id="notes" name="notes" rows="3" maxlength="2000">{{ old('notes') }}</textarea>
                        </div>

                        <div class="col-12">
                            <button class="btn btn-primary">Continue to shipping and payment</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card">
            <div class="card-header py-3"><h5 class="mb-0">How your own purchase is credited</h5></div>
            <div class="card-body small">
                <ul class="mb-0 ps-3">
                    @php $terms = \App\Services\Vendor\PromotionBonuses::terms($promotion); @endphp
                    <li class="mb-2">
                        @if ($promotion)
                            It counts as your sale, including toward <strong>{{ $promotion['name'] }}</strong>.
                        @else
                            It counts as your sale.
                        @endif
                    </li>
                    @if ($terms)
                        <li class="mb-2">
                            While places remain, each system you buy earns <strong>you</strong> the
                            ${{ number_format($terms['place_amount']) }} launch bonus and a share of the bonus pool,
                            once the sale is confirmed.
                        </li>
                    @endif
                    <li class="mb-2">
                        {{-- Sale commission, separate from the launch bonus above. --}}
                        @if ($sponsor)
                            The sale commission on your own purchase goes to your sponsor,
                            <strong>{{ $sponsor->name }}</strong>, not to you.
                        @else
                            No sale commission is paid on your own purchase: it would go to your sponsor,
                            and your account has none. This is shown to accounts at the top of the team.
                        @endif
                    </li>
                    <li>
                        The same applies to any share link, yours or another partner's: an order placed with
                        your account's email or phone number counts as your own purchase.
                    </li>
                </ul>
            </div>
        </div>
    </div>
</div>

@endsection
