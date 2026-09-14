@extends('layouts.member')

@section('title', 'Activate Membership')
@section('page-title', 'Activate Membership')

@section('breadcrumb')
    <li class="breadcrumb-item active">Billing</li>
@endsection

@section('content')
<div class="row justify-content-center">
    <div class="col-lg-7">

        @if(! $configured)
            <div class="alert alert-warning">
                <strong>Payments are not configured yet.</strong>
                Card capture is unavailable until the payment provider keys are set.
            </div>
        @else

            @if($testMode)
                <div class="alert alert-info py-2 small mb-3">
                    <strong>Test mode.</strong> Use card <code>4242 4242 4242 4242</code>, any future expiry and CVC.
                </div>
            @endif

            @php($price = strtoupper($currency) . ' ' . number_format($amount / 100, 2))

            <div class="card">
                <div class="card-body p-4">

                    <h5 class="mb-1">Add a payment method</h5>
                    <p class="text-muted small mb-4">
                        @if($awaitingLaunch)
                            Your {{ $trialDays }}-day free trial starts on launch day. Your first charge of
                            {{ $price }} is {{ $trialDays }} days after launch, then every {{ $interval }}.
                        @else
                            {{ $price }} per {{ $interval }}, starting {{ $trialEnd->format('j F Y') }}.
                        @endif
                        <strong>You will not be charged today.</strong>
                    </p>

                    @if($errors->any())
                        <div class="alert alert-danger py-2 small">{{ $errors->first() }}</div>
                    @endif

                    <div id="payment-element" class="mb-3"></div>
                    <div id="card-error" class="text-danger small mb-3" role="alert"></div>

                    <button id="submit-card" class="btn btn-primary w-100">
                        <span id="submit-text">Save card &amp; activate</span>
                        <span id="submit-spinner" class="spinner-border spinner-border-sm d-none"></span>
                    </button>

                    <p class="text-muted small mt-3 mb-2">
                        Charges after your trial are non-refundable. A card can back only one membership.
                        See the <a href="https://q3.life/terms" target="_blank" rel="noopener">Terms</a>
                        and <a href="https://q3.life/refunds" target="_blank" rel="noopener">Refund Policy</a>.
                    </p>
                    <p class="text-muted small mb-0">
                        Card details go directly to our payment provider and are never stored on our servers.
                    </p>
                </div>
            </div>

            <form id="confirm-form" method="POST" action="{{ route('member.billing.confirm') }}" class="d-none">
                @csrf
                <input type="hidden" name="payment_method" id="payment_method_id">
            </form>
        @endif
    </div>
</div>
@endsection

@push('scripts')
@if($configured)
<script src="https://js.stripe.com/v3/"></script>
<script>
document.addEventListener('DOMContentLoaded', async function () {
    const stripe = Stripe(@json($publishableKey));

    const button  = document.getElementById('submit-card');
    const text    = document.getElementById('submit-text');
    const spinner = document.getElementById('submit-spinner');
    const errorEl = document.getElementById('card-error');

    function busy(on) {
        button.disabled = on;
        text.classList.toggle('d-none', on);
        spinner.classList.toggle('d-none', !on);
    }

    function fail(message) {
        errorEl.textContent = message;
        busy(false);
    }

    // The SetupIntent is created server-side; only its client secret reaches
    // the browser. Raw card details never touch our server.
    let clientSecret;

    try {
        const res = await fetch(@json(route('member.billing.setup-intent')), {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                'Accept': 'application/json',
            },
        });
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || 'Could not start card setup.');
        clientSecret = data.client_secret;
    } catch (e) {
        fail(e.message);
        return;
    }

    const elements = stripe.elements({ clientSecret });

    // Card fields only. Apple Pay and Google Pay hand over a device-specific card
    // number, and Link offers saved cards and bank accounts, all of which would
    // slip past the one-card-per-account check. The server refuses them too.
    elements.create('payment', {
        wallets: { applePay: 'never', googlePay: 'never', link: 'never' },
    }).mount('#payment-element');

    button.addEventListener('click', async function () {
        busy(true);
        errorEl.textContent = '';

        const { error, setupIntent } = await stripe.confirmSetup({
            elements,
            redirect: 'if_required',
        });

        if (error) {
            fail(error.message);
            return;
        }

        // Hand the confirmed payment method to the server, which attaches it and
        // opens the subscription. The webhook corrects the row moments later —
        // this response is optimistic, never final.
        document.getElementById('payment_method_id').value = setupIntent.payment_method;
        document.getElementById('confirm-form').submit();
    });
});
</script>
@endif
@endpush
