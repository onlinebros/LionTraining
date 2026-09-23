<?php

namespace App\Http\Controllers;

use App\Exceptions\BillingException;
use App\Models\PaymentMethod;
use App\Models\Subscription;
use App\Services\Stripe\BillingService;
use App\Services\Stripe\CommissionBillingTrigger;
use App\Services\Stripe\StripeClientFactory;
use App\Support\Opportunity;
use App\Support\Prelaunch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Stripe\Exception\ApiErrorException;

/**
 * The partner's own billing screens.
 *
 * Every route here sits OUTSIDE the RequireActiveSubscription gate — see the
 * comment on that middleware and in routes/web.php. Gating the screen that
 * fixes an ungated state is a redirect loop.
 */
class MemberBillingController extends Controller
{
    public function __construct(
        private readonly BillingService $billing,
        private readonly StripeClientFactory $stripe,
        private readonly CommissionBillingTrigger $trigger,
    ) {}

    /** Card capture. Where the subscription gate sends an unsubscribed partner. */
    public function start()
    {
        $user = auth()->user();

        // Already covered — nothing to do here, and leaving them on a card form
        // invites a second subscription.
        if ($user->hasActiveMembership()) {
            return redirect()->route('member.billing.index');
        }

        /*
        | The training program is not on sale yet.
        |
        | While `enrollment_open` is false nobody is asked for a card anywhere,
        | and that has to include somebody who reaches this URL directly — from
        | a bookmark, an old email, or a link written before the switch. The
        | card form existing but being unreachable is the difference between a
        | closed shop and a shop with the lights off.
        |
        | They go to the Training Program section, which says when it opens and
        | takes their name for the day it does.
        */
        if (! Opportunity::membership()->enrollmentOpen()) {
            return redirect()->route('member.training-program');
        }

        [$firstCharge] = $this->billing->resolveTrialEnd();

        return view('member.billing.start', [
            'user'           => $user,
            'publishableKey' => $this->stripe->publishableKey(),
            'configured'     => $this->stripe->isConfigured(),
            'testMode'       => $this->stripe->isConfigured() && $this->stripe->isTestMode(),
            // Null when the training program is already open (charged today).
            // With no date published it is only a placeholder, never shown.
            'firstCharge'    => $firstCharge,
            'awaitingLaunch' => Prelaunch::endsAt() === null,
            'threshold'      => (float) config('stripe.subscription.commission_threshold'),
            // No option is pre-selected; the partner has to choose one.
            'enrollment'     => old('enrollment'),
            /*
            | Whether this partner is here because they have to be.
            |
            | A member whose business line is not the membership — the
            | PlasmaGuard B2B side — reaches this screen only by choosing to,
            | and nothing is gated behind it for them. The page has to say so,
            | or it reads as a bill they have failed to pay. See
            | config/opportunities.php.
            */
            'optional'       => ! $user->requiresMembership(),
            'opportunity'    => $user->opportunity(),
            'amount'         => (int) config('stripe.subscription.amount'),
            'interval'       => config('stripe.subscription.interval'),
        ]);
    }

    /**
     * Create a SetupIntent for the browser to confirm the card against.
     *
     * Only the client secret crosses back. Raw card details go straight from the
     * browser to the provider and never reach this server.
     */
    public function setupIntent(): JsonResponse
    {
        // The page is unreachable while the program is closed, but this is the
        // endpoint that actually reaches the card network, so it refuses on its
        // own rather than trusting that nobody kept the page open across the
        // switch being thrown.
        if (! Opportunity::membership()->enrollmentOpen()) {
            return response()->json(['error' => 'The training program is not open yet. No card is needed.'], 422);
        }

        try {
            $intent = $this->billing->createSetupIntent(auth()->user());
        } catch (BillingException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        } catch (ApiErrorException $e) {
            Log::error('SetupIntent failed', ['user_id' => auth()->id(), 'error' => $e->getMessage()]);

            return response()->json(['error' => 'Could not start card setup. No charge has been made.'], 422);
        }

        return response()->json(['client_secret' => $intent->client_secret]);
    }

    /**
     * Attach the confirmed card and open the subscription.
     *
     * This writes an optimistic row so the partner sees a result immediately.
     * The webhook is the source of truth and corrects it moments later — a
     * subscription can still be `incomplete` pending SCA at this point, so the
     * response here is never treated as final.
     */
    public function confirm(Request $request)
    {
        // The last gate before a subscription is opened. See setupIntent().
        if (! Opportunity::membership()->enrollmentOpen()) {
            return redirect()->route('member.training-program')
                ->with('status', 'The training program is not open yet, so nothing was charged and no card was saved.');
        }

        $data = $request->validate([
            'payment_method' => 'required|string|max:255',
            'enrollment'     => ['required', Rule::in(Subscription::TRIGGERS)],
        ], [
            'enrollment.required' => 'Choose an enrollment option.',
        ]);

        try {
            $subscription = $this->billing->startSubscription(auth()->user(), $data['payment_method'], $data['enrollment']);
        } catch (BillingException $e) {
            return back()->withInput($request->only('enrollment'))->withErrors(['payment_method' => $e->getMessage()]);
        } catch (ApiErrorException $e) {
            Log::error('Subscription confirm failed', ['user_id' => auth()->id(), 'error' => $e->getMessage()]);

            return back()->withInput($request->only('enrollment'))->withErrors([
                'payment_method' => 'We could not start your Training Program. No charge has been made.',
            ]);
        }

        return redirect()->route('member.billing.index')
            ->with('status', $this->statusFor($subscription));
    }

    /**
     * A partner waiting on commissions chooses to start billing now, which
     * opens the training program.
     */
    public function startNow()
    {
        $subscription = auth()->user()->activeSubscription();

        if ($subscription === null || ! $subscription->isCommissionHold()) {
            return back()->withErrors(['subscription' => 'Your Training Program billing has already started.']);
        }

        try {
            $subscription = $this->billing->startBillingOnLaunchSchedule($subscription);
        } catch (ApiErrorException $e) {
            Log::error('Start-now failed', ['user_id' => auth()->id(), 'error' => $e->getMessage()]);

            return back()->withErrors(['subscription' => 'We could not start your Training Program. Please try again.']);
        }

        return back()->with('status', $this->statusFor($subscription));
    }

    private function statusFor(Subscription $subscription): string
    {
        if ($subscription->billing_trigger === Subscription::TRIGGER_COMMISSION && $subscription->isCommissionHold()) {
            return 'Your card is on file. You will not be charged until your paid commissions reach $'
                . number_format((float) config('stripe.subscription.commission_threshold')) . '.';
        }

        if ($subscription->status !== Subscription::STATUS_TRIALING) {
            return 'Your Training Program is active and your card has been charged.';
        }

        return Prelaunch::endsAt() === null
            ? 'Your card is on file. It will be charged as soon as the training program is ready.'
            : 'Your card is on file. Your first charge is ' . $subscription->trial_ends_at?->format('j F Y') . ', when the training program opens.';
    }

    /** Manage screen: subscription state, cards, invoices. */
    public function index()
    {
        $user = auth()->user()->load(['subscriptions', 'paymentMethods']);

        return view('member.billing.index', [
            'awaitingLaunch' => Prelaunch::endsAt() === null,
            'threshold'      => $this->trigger->threshold(),
            'commissionPaid' => $this->trigger->paidTotal($user),
            'user'           => $user,
            'subscription'   => $user->activeSubscription() ?? $user->subscriptions()->latest('id')->first(),
            'paymentMethods' => $user->paymentMethods()->orderByDesc('is_default')->get(),
            'invoices'       => $this->billing->invoicesFor($user),
            'publishableKey' => $this->stripe->publishableKey(),
            'configured'     => $this->stripe->isConfigured(),
        ]);
    }

    public function replaceCard(Request $request)
    {
        $data = $request->validate(['payment_method' => 'required|string|max:255']);

        try {
            $this->billing->attachPaymentMethod(auth()->user(), $data['payment_method']);
        } catch (BillingException $e) {
            return back()->withErrors(['payment_method' => $e->getMessage()]);
        }

        return back()->with('status', 'Card updated.');
    }

    public function removeCard(PaymentMethod $paymentMethod)
    {
        abort_unless($paymentMethod->user_id === auth()->id(), 403);

        try {
            $this->billing->detachPaymentMethod(auth()->user(), $paymentMethod);
        } catch (BillingException $e) {
            return back()->withErrors(['card' => $e->getMessage()]);
        }

        return back()->with('status', 'Card removed.');
    }

    public function cancel()
    {
        $subscription = auth()->user()->activeSubscription();

        if ($subscription === null) {
            return back()->withErrors(['subscription' => 'There is no active Training Program subscription to cancel.']);
        }

        $this->billing->cancelAtPeriodEnd($subscription);

        return back()->with('status', 'Your Training Program will end at the close of the current period. You keep access until then.');
    }

    public function resume()
    {
        $subscription = auth()->user()->subscriptions()->latest('id')->first();

        if ($subscription === null) {
            return back()->withErrors(['subscription' => 'There is no Training Program subscription to resume.']);
        }

        try {
            $this->billing->resume($subscription);
        } catch (BillingException $e) {
            return back()->withErrors(['subscription' => $e->getMessage()]);
        }

        return back()->with('status', 'Your Training Program will continue.');
    }
}
