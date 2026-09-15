{{--
    The launch special: a partner buying one of the first 100 systems.

    More than 100 systems will be sold. Only the first 100 qualify for the
    special (owner, 2026-09-15), so the copy says that plainly. It must not
    read as though only 100 systems exist.

    Fed by BuyYoursPromoComposer, which leaves $buyYours null once every
    qualifying place is taken, when no promotion is running, or when the vendor
    is switched off.

    No reward is named. The terms of the special are not written down yet, so
    partners are pointed to their sponsor. Who is paid on an own purchase is
    always stated, the same as on the order form.

    $variant: 'banner' (default) or 'hero', the larger version on Product Sales.
--}}
@php $hero = ($variant ?? 'banner') === 'hero'; @endphp

<div class="card q3-card-featured mb-0">
    <div class="card-body {{ $hero ? 'p-4' : '' }}">
        <div class="row g-4 align-items-center">
            <div class="col-lg-8">
                <span class="badge badge-light-warning mb-2">Launch special · first {{ $buyYours['cap'] }} only</span>
                <h5 class="mb-2 {{ $hero ? 'fs-4' : '' }}">
                    Buy one of the first {{ $buyYours['cap'] }} and get the launch special
                </h5>
                <p class="mb-3">
                    The launch special is only for the first {{ $buyYours['cap'] }} {{ $buyYours['product_name'] }} sold.
                    More will be available after that, but they won't qualify.
                    A system you buy for your own home or business counts toward the {{ $buyYours['cap'] }},
                    the same as a sale to a customer.
                    @if ($buyYours['has_sponsor'])
                        Ask your sponsor for the details of the special.
                    @endif
                </p>

                <div class="progress mb-2" style="height:8px;" role="progressbar" aria-label="Launch special places claimed"
                     aria-valuenow="{{ $buyYours['filled'] }}" aria-valuemin="0" aria-valuemax="{{ $buyYours['cap'] }}">
                    <div class="progress-bar" style="width: {{ $buyYours['percent'] }}%"></div>
                </div>
                <div class="text-muted small">
                    {{ $buyYours['filled'] }} of {{ $buyYours['cap'] }} claimed ·
                    {{ $buyYours['has_sponsor']
                        ? 'The commission on your own purchase goes to your sponsor.'
                        : 'No commission is paid on your own purchase.' }}
                </div>
            </div>

            <div class="col-lg-4 text-lg-end">
                <div class="q3-stat-value q3-stat-value--gold" style="font-size:{{ $hero ? '2.75rem' : '2.25rem' }};line-height:1;">
                    {{ $buyYours['remaining'] }}
                </div>
                <div class="q3-stat-label mb-3">left for the special</div>
                <div class="d-flex flex-wrap gap-2 justify-content-lg-end">
                    <a class="btn btn-primary" href="{{ $buyYours['buy_url'] }}">Buy yours</a>
                    @if ($showLeaderboard ?? true)
                        <a class="btn btn-outline-primary" href="{{ route('member.sales.promotion') }}">Leaderboard</a>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
