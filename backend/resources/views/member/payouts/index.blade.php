@extends('layouts.member')

@section('title', 'Get Paid')
@section('page-title', 'Get Paid')

@section('breadcrumb')
    <li class="breadcrumb-item active">Get Paid</li>
@endsection

@push('styles')
<style>
    .payout-steps { list-style: none; padding: 0; margin: 0; }
    .payout-steps li { display: flex; gap: 12px; align-items: flex-start; padding: 10px 0; }
    .payout-steps .step-num {
        flex: 0 0 26px; height: 26px; border-radius: 50%; display: grid; place-items: center;
        font-size: .8125rem; font-weight: 600; background: var(--q3-gold-tint-2); color: var(--q3-gold-high);
    }
    .payout-steps li.done .step-num { background: var(--q3-success); color: #fff; }
    #connect-container { min-height: 320px; }
    .req-chip { font-size: .75rem; }
</style>
@endpush

@section('content')
<div class="row">

    @if(session('error'))
        <div class="col-12"><div class="alert alert-danger">{{ session('error') }}</div></div>
    @endif
    @if(session('info'))
        <div class="col-12"><div class="alert alert-info">{{ session('info') }}</div></div>
    @endif

    @if(! $connectEnabled)
        <div class="col-12">
            <div class="alert alert-warning">Payout accounts are not available right now. Please check back soon.</div>
        </div>
    @else

    {{-- ── Status ─────────────────────────────────────────────────────────── --}}
    <div class="col-xl-4 col-lg-12 mb-3">
        <div class="card h-100">
            <div class="card-header"><h5 class="mb-0">Payout Status</h5></div>
            <div class="card-body">

                @if($user->canReceivePayouts())
                    <div class="text-center py-3">
                        <h5 class="text-success mb-1">You're all set</h5>
                        <p class="text-muted small mb-0">Commissions are paid straight to your bank account.</p>
                    </div>
                @else
                    <p class="mb-3">
                        Set up your payout account to receive commissions. It takes a few minutes and is
                        handled securely by Stripe, our payments provider.
                    </p>

                    @if($unpaidBalance > 0)
                        <div class="alert alert-warning py-2 small">
                            You have <strong>${{ number_format($unpaidBalance, 2) }}</strong> in commissions waiting.
                            We can't send them until this is finished.
                        </div>
                    @endif
                @endif

                <ul class="payout-steps mt-3">
                    <li class="{{ $user->hasConnectAccount() ? 'done' : '' }}">
                        <span class="step-num">{{ $user->hasConnectAccount() ? '✓' : '1' }}</span>
                        <span class="small">Start your payout account</span>
                    </li>
                    <li class="{{ $user->connect_details_submitted ? 'done' : '' }}">
                        <span class="step-num">{{ $user->connect_details_submitted ? '✓' : '2' }}</span>
                        <span class="small">Confirm your identity, tax details and bank</span>
                    </li>
                    <li class="{{ $user->connect_payouts_enabled ? 'done' : '' }}">
                        <span class="step-num">{{ $user->connect_payouts_enabled ? '✓' : '3' }}</span>
                        <span class="small">Payouts switched on</span>
                    </li>
                </ul>

                @if($outstanding)
                    <hr>
                    <p class="small fw-semibold mb-2">Stripe still needs:</p>
                    <div class="d-flex flex-wrap gap-1">
                        @foreach($outstanding as $item)
                            <span class="badge bg-danger req-chip">{{ $item }}</span>
                        @endforeach
                    </div>
                @endif

                @if($upcoming)
                    <hr>
                    <p class="small fw-semibold mb-2">Needed later, before your payouts reach $3,000:</p>
                    <div class="d-flex flex-wrap gap-1">
                        @foreach($upcoming as $item)
                            <span class="badge bg-secondary req-chip">{{ $item }}</span>
                        @endforeach
                    </div>
                @endif

                <hr>
                <form action="{{ route('member.payouts.refresh') }}" method="POST">
                    @csrf
                    <button class="btn btn-outline-secondary btn-sm w-100">Refresh status</button>
                </form>

                @if($user->connect_synced_at)
                    <p class="text-muted text-center mt-2 mb-0" style="font-size: .75rem">
                        Last checked {{ $user->connect_synced_at->diffForHumans() }}
                    </p>
                @endif
            </div>
        </div>
    </div>

    {{-- ── Embedded Stripe onboarding ─────────────────────────────────────── --}}
    <div class="col-xl-8 col-lg-12 mb-3">
        <div class="card h-100">
            <div class="card-header">
                <h5 class="mb-0">{{ $user->canReceivePayouts() ? 'Payouts' : 'Set Up Payouts' }}</h5>
            </div>
            <div class="card-body">

                @if(! $user->canReceivePayouts())
                    <div class="alert alert-light border small mb-4">
                        <p class="mb-2">
                            <strong>We've started the form with the details from your profile.</strong>
                            Check them as you go, then add your date of birth, Social Security number and the
                            bank account you want to be paid into.
                        </p>
                        <p class="mb-0 text-muted">
                            Your 1099 is issued in the name and tax ID you enter here. If you are paid through an
                            LLC or corporation with an EIN, enter the business instead of yourself.
                        </p>
                    </div>
                @endif

                <div id="connect-banner" class="mb-3"></div>

                <div id="connect-loading" class="text-center py-5">
                    <div class="spinner-border text-primary" role="status"></div>
                    <p class="text-muted small mt-3 mb-0">Loading the secure Stripe form…</p>
                </div>

                <div id="connect-error" class="alert alert-warning d-none">
                    <p class="mb-2"><strong>We couldn't load the secure form here.</strong></p>
                    <p class="mb-3 small" id="connect-error-detail">This can happen if a browser extension blocks Stripe.</p>
                    @if($hostedFallback)
                        <a href="{{ route('member.payouts.hosted') }}" class="btn btn-primary btn-sm">Continue on Stripe instead</a>
                    @endif
                </div>

                <div id="connect-container"></div>

                {{-- There is no Stripe dashboard for these accounts, so bank and
                     details changes happen here. --}}
                @if($user->canReceivePayouts())
                    <hr class="my-4">
                    <h6 class="mb-3">Account details</h6>
                    <div id="connect-manage"></div>
                @endif
            </div>
        </div>
    </div>

    @endif
</div>
@endsection

@push('scripts')
@if($connectEnabled && $publishableKey)
<script src="https://connect-js.stripe.com/v1.0/connect.js"></script>
<script>
(function () {
    const loading   = document.getElementById('connect-loading');
    const container = document.getElementById('connect-container');
    const errorBox  = document.getElementById('connect-error');
    const errorText = document.getElementById('connect-error-detail');
    const csrf      = document.querySelector('meta[name="csrf-token"]')?.content;
    const payoutsEnabled = @json((bool) $user->canReceivePayouts());

    const fail = (message) => {
        loading.classList.add('d-none');
        if (message) errorText.textContent = message;
        errorBox.classList.remove('d-none');
    };

    if (typeof StripeConnect === 'undefined') {
        fail('The Stripe library did not load.');
        return;
    }

    // Handed to Stripe rather than called once: sessions are short-lived and
    // Stripe asks again whenever one expires. Stripe reports anything thrown
    // here as a generic authentication error, so show the real reason first.
    const fetchClientSecret = async () => {
        try {
            const res = await fetch(@json(route('member.payouts.account-session')), {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
            });
            const body = await res.json();
            if (!res.ok) throw new Error(body.error || 'Could not start Stripe onboarding.');
            return body.client_secret;
        } catch (e) {
            fail(e.message);
            throw e;
        }
    };

    let instance;
    try {
        instance = StripeConnect.init({
            publishableKey: @json($publishableKey),
            fetchClientSecret,
            appearance: {
                overlays: 'dialog',
                // The Q3 dark theme (assets/css/q3-theme.css).
                variables: {
                    fontFamily: 'Inter, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif',
                    colorPrimary: '#D4AF37',
                    colorBackground: '#141416',
                    colorText: '#F5F1E8',
                    colorSecondaryText: '#A9A6A0',
                    colorBorder: '#2A2A2E',
                    colorDanger: '#B4483F',
                    buttonPrimaryColorBackground: '#D4AF37',
                    buttonPrimaryColorText: '#0B0B0C',
                    borderRadius: '8px',
                },
            },
        });
    } catch (e) {
        fail(e.message);
        return;
    }

    const component = payoutsEnabled ? instance.create('payouts') : instance.create('account-onboarding');

    let loaderStarted = false;
    component.setOnLoaderStart(() => {
        loaderStarted = true;
        loading.classList.add('d-none');
    });
    component.setOnLoadError((event) => fail(event?.error?.message || 'Stripe could not load the secure form.'));

    if (!payoutsEnabled) {
        // Collect what Stripe will need later too, so payouts are not paused
        // when a partner's earnings grow.
        component.setCollectionOptions({ fields: 'eventually_due', futureRequirements: 'include' });

        // Stripe may still be verifying when the partner finishes, so read the
        // real status rather than assuming completion.
        component.setOnExit(() => {
            fetch(@json(route('member.payouts.refresh')), {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
            }).finally(() => window.location.reload());
        });
    } else {
        document.getElementById('connect-banner').appendChild(instance.create('notification-banner'));
    }

    container.appendChild(component);

    const manage = document.getElementById('connect-manage');
    if (manage) manage.appendChild(instance.create('account-management'));

    // Generous on purpose: a weak mobile connection is exactly where giving up
    // early and sending someone to Stripe's hosted flow would be wrong.
    setTimeout(() => { if (!loaderStarted) fail('The secure form did not load in time.'); }, 30000);
})();
</script>
@endif
@endpush
