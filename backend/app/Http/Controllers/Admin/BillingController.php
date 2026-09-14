<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessPaymentWebhookJob;
use App\Models\StripeWebhookEvent;
use App\Models\Subscription;
use App\Services\Stripe\BillingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\ApiErrorException;

/**
 * Subscription and webhook oversight (C3).
 *
 * Three numbers are the health of the billing integration and are shown at the
 * top of both screens: counts by status, subscriptions whose mirror has not been
 * confirmed in 24 hours, and unprocessed webhook events. A drift in any of them
 * means webhooks are being missed, which is invisible until someone looks.
 */
class BillingController extends Controller
{
    public function __construct(private readonly BillingService $billing) {}

    public function subscriptions(Request $request)
    {
        $query = Subscription::query()->with('user');   // eager-loaded: this list must survive 10k rows

        if ($status = $request->string('status')->toString()) {
            $query->where('status', $status);
        }

        if ($search = $request->string('q')->toString()) {
            $query->whereHas('user', function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                    ->orWhere('email', 'ilike', "%{$search}%");
            });
        }

        return view('admin.billing.subscriptions', [
            'subscriptions' => $query->latest('id')->paginate(25)->withQueryString(),
            'byStatus'      => Subscription::selectRaw('status, count(*) as total')
                                  ->groupBy('status')->pluck('total', 'status'),
            'staleCount'    => Subscription::stale()->count(),
            'unprocessed'   => StripeWebhookEvent::where('status', '!=', StripeWebhookEvent::STATUS_PROCESSED)->count(),
            'filters'       => ['status' => $status, 'q' => $search],
        ]);
    }

    /** Re-read from the provider and repair the local row. */
    public function sync(Subscription $subscription)
    {
        try {
            $this->billing->refresh($subscription);
        } catch (ApiErrorException $e) {
            Log::error('Subscription sync failed', ['id' => $subscription->id, 'error' => $e->getMessage()]);

            return back()->withErrors(['sync' => 'Could not reach the payment provider.']);
        }

        return back()->with('status', 'Subscription re-synced from the provider.');
    }

    /**
     * Extend a trial.
     *
     * A support tool with an actor and a reason, not a free-form date edit. The
     * call goes to the provider and the webhook writes the result back, so the
     * mirror is never hand-edited into a state the provider disagrees with.
     */
    public function extendTrial(Request $request, Subscription $subscription)
    {
        $data = $request->validate([
            'trial_ends_at' => 'required|date|after:+48 hours',
            'reason'        => 'required|string|min:3|max:500',
        ]);

        try {
            $this->billing->setTrialEnd(
                $subscription,
                \Illuminate\Support\Carbon::parse($data['trial_ends_at']),
                $subscription->is_prelaunch_trial,
            );
        } catch (ApiErrorException $e) {
            return back()->withErrors(['trial_ends_at' => 'Provider refused: ' . $e->getMessage()]);
        }

        Log::info('Trial extended', [
            'subscription_id' => $subscription->id,
            'user_id'         => $subscription->user_id,
            'actor_id'        => auth()->id(),
            'new_trial_end'   => $data['trial_ends_at'],
            'reason'          => $data['reason'],
        ]);

        return back()->with('status', 'Trial extended and recorded.');
    }

    public function webhooks(Request $request)
    {
        $query = StripeWebhookEvent::query();

        if ($type = $request->string('type')->toString()) {
            $query->where('type', $type);
        }

        if ($request->filled('state')) {
            $request->string('state')->toString() === 'processed'
                ? $query->where('status', StripeWebhookEvent::STATUS_PROCESSED)
                : $query->where('status', '!=', StripeWebhookEvent::STATUS_PROCESSED);
        }

        return view('admin.billing.webhooks', [
            'events'      => $query->latest('id')->paginate(50)->withQueryString(),
            'types'       => StripeWebhookEvent::select('type')->distinct()->orderBy('type')->pluck('type'),
            'unprocessed' => StripeWebhookEvent::where('status', '!=', StripeWebhookEvent::STATUS_PROCESSED)->count(),
            'invalidSigs' => StripeWebhookEvent::where('signature_valid', false)->count(),
            'filters'     => ['type' => $type, 'state' => $request->string('state')->toString()],
        ]);
    }

    /**
     * Re-dispatch processing for a stored event.
     *
     * This is what makes a handler bug recoverable: fix the handler, replay the
     * affected events. Handlers are idempotent, so replaying a successful event
     * reaches the same end state rather than applying it twice.
     */
    public function replay(StripeWebhookEvent $event)
    {
        if ($event->signature_valid === false) {
            // Never act on a payload that failed authentication, however
            // convenient it would be. It is stored for inspection only.
            return back()->withErrors(['replay' => 'This event failed signature verification and cannot be replayed.']);
        }

        ProcessPaymentWebhookJob::dispatch($event->id);

        return back()->with('status', "Event {$event->stripe_event_id} queued for reprocessing.");
    }
}
