@extends('layouts.member')

@section('title', 'Add the Training Program')
@section('page-title', 'Add the Training Program')

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

            @php($price = '$' . number_format($amount / 100, 2))
            @php($thresholdText = '$' . number_format($threshold))

            {{-- A partner on a business line that is not the membership got
                 here by choosing to, not by being stopped. Saying so is the
                 difference between an offer and an unpaid bill. --}}
            @if($optional ?? false)
                <div class="alert alert-info py-2 small mb-3">
                    <strong>This is optional.</strong>
                    Nothing in {{ $opportunity->name() }} needs a card, and your account keeps working
                    exactly as it does now if you close this page. Adding the Training Program below is what
                    opens the training program.
                </div>
            @endif

            <div class="card">
                <div class="card-body p-4">

                    <h4 class="mb-3">Quantum VISION</h4>
                    <p class="mb-2">
                        Quantum VISION is the Foundational HEART of The Quantum Solution.
                    </p>
                    <p class="mb-2">
                        The Rediscovery of the HEART Institute awakens the Creative Genius Code that is
                        dormant in 98% of adults.
                    </p>
                    <p class="mb-4">
                        Just imagine how productive your network will be when everyone is learning how to
                        live with Quantum VISION. The monthly tuition is <strong>{{ $price }}</strong>.
                    </p>

                    @if($errors->any())
                        <div class="alert alert-danger py-2 small">{{ $errors->first() }}</div>
                    @endif

                    <h6 class="fw-bold mb-2">Enrollment options</h6>

                    <div class="mb-4" role="radiogroup" aria-label="Enrollment options">
                        <label class="d-flex gap-3 border rounded p-3 mb-2" style="cursor:pointer;">
                            <input class="form-check-input mt-1 flex-shrink-0" type="radio" name="enrollment_choice"
                                   value="launch" @checked($enrollment === 'launch')>
                            <span>
                                <span class="fw-semibold d-block">I Want To Recover My Genius NOW!</span>
                                <span class="text-muted small">
                                    @if($awaitingLaunch)
                                        Your card is saved today and charged {{ $price }} as soon as the training
                                        program is ready. You'll get the date before then.
                                    @elseif($firstCharge)
                                        Your card is saved today and charged {{ $price }} on
                                        {{ $firstCharge->format('j F Y') }}, when the training program opens.
                                    @else
                                        Your card is charged {{ $price }} today and the training program opens
                                        right away.
                                    @endif
                                    Then {{ $price }} every {{ $interval }}.
                                </span>
                            </span>
                        </label>

                        <label class="d-flex gap-3 border rounded p-3" style="cursor:pointer;">
                            <input class="form-check-input mt-1 flex-shrink-0" type="radio" name="enrollment_choice"
                                   value="commission" @checked($enrollment === 'commission')>
                            <span>
                                <span class="fw-semibold d-block">
                                    I will wait until I've been paid at least {{ $thresholdText }} in commissions.
                                </span>
                                <span class="text-muted small">
                                    Your card is saved today and first charged {{ $price }} once your paid
                                    commissions add up to {{ $thresholdText }}. The training program opens when your
                                    billing starts, and you can start sooner from your Billing page at any time.
                                </span>
                            </span>
                        </label>
                    </div>

                    <h6 class="fw-bold mb-2">Payment method</h6>
                    <p class="text-muted small mb-3" id="charge-note">
                        @if($firstCharge === null)
                            <span data-for="launch">Your card will be charged {{ $price }} today.</span>
                            <span data-for="commission"><strong>You will not be charged today.</strong></span>
                        @else
                            <strong>You will not be charged today.</strong>
                        @endif
                    </p>

                    <div id="payment-element" class="mb-3"></div>
                    <div id="card-error" class="text-danger small mb-3" role="alert"></div>

                    <button id="submit-card" class="btn btn-primary w-100">
                        <span id="submit-text">Save card &amp; enroll</span>
                        <span id="submit-spinner" class="spinner-border spinner-border-sm d-none"></span>
                    </button>

                    <p class="text-muted small mt-3 mb-2">
                        Charges are non-refundable. A card can back only one Training Program subscription.
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
                <input type="hidden" name="enrollment" id="enrollment_input">
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

    const choices = document.querySelectorAll('input[name="enrollment_choice"]');
    const selected = () => document.querySelector('input[name="enrollment_choice"]:checked')?.value;

    // Only the note that matches the chosen option is shown.
    function showChargeNote() {
        document.querySelectorAll('#charge-note [data-for]').forEach(function (el) {
            el.classList.toggle('d-none', el.dataset.for !== selected());
        });
    }
    choices.forEach(c => c.addEventListener('change', showChargeNote));
    showChargeNote();

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
        errorEl.textContent = '';

        if (!selected()) {
            errorEl.textContent = 'Choose an enrollment option.';
            return;
        }

        busy(true);

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
        document.getElementById('enrollment_input').value = selected();
        document.getElementById('confirm-form').submit();
    });
});
</script>
@endif
@endpush
