<?php

namespace App\Services\Stripe;

use App\Models\CardFingerprint;
use App\Models\PaymentMethod;
use App\Models\StripeWebhookEvent;
use App\Models\Subscription as SubscriptionModel;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Stripe\Subscription as StripeSubscription;
use Throwable;

/**
 * Turns a stored webhook event into local state.
 *
 * Every handler is idempotent: replaying the same event produces the same end
 * state. That is what makes a handler bug recoverable — fix the handler, replay
 * the affected events, done — and it is not optional, because providers redeliver
 * on their own schedule regardless of what this application would prefer.
 */
class StripeWebhookProcessor
{
    public function __construct(
        private readonly StripeClientFactory $stripe,
        private readonly BillingService $billing,
    ) {}

    /**
     * Process one stored event.
     *
     * Success and failure are both recorded on the row. A failure leaves
     * `processing_error` set and the row replayable rather than throwing away
     * the only record that something went wrong.
     */
    public function process(StripeWebhookEvent $event): void
    {
        $event->increment('processing_attempts');

        try {
            $payload = json_decode($event->payload, true, 512, JSON_THROW_ON_ERROR);
            $object  = $payload['data']['object'] ?? [];

            match ($event->type) {
                'customer.subscription.created',
                'customer.subscription.updated',
                'customer.subscription.deleted' => $this->handleSubscription($object),

                'invoice.paid',
                'invoice.payment_succeeded'     => $this->handleInvoicePaid($object),
                'invoice.payment_failed'        => $this->handleInvoiceFailed($object),

                'payment_method.attached'       => $this->handlePaymentMethodAttached($object),
                'payment_method.detached'       => $this->handlePaymentMethodDetached($object),

                'charge.refunded'               => $this->handleChargeRefunded($object),

                // Unhandled types are stored and acknowledged, not treated as
                // errors — a provider sends far more event types than any one
                // application cares about.
                default => Log::debug('Unhandled webhook type', ['type' => $event->type]),
            };

            $event->forceFill([
                'status'           => StripeWebhookEvent::STATUS_PROCESSED,
                'processed_at'     => now(),
                'processing_error' => null,
            ])->save();
        } catch (Throwable $e) {
            $event->forceFill([
                'status'           => StripeWebhookEvent::STATUS_FAILED,
                'processing_error' => $e->getMessage(),
            ])->save();

            Log::error('Webhook processing failed', [
                'event_id' => $event->stripe_event_id,
                'type'     => $event->type,
                'error'    => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    // ── Handlers ──────────────────────────────────────────────────────────────

    /**
     * The webhook is the source of truth for subscription state.
     *
     * The subscription is re-read from the provider rather than trusted from the
     * payload: webhooks can arrive out of order, and a stale `updated` event
     * landing after a newer one would otherwise roll the mirror backwards.
     */
    private function handleSubscription(array $object): void
    {
        $subscriptionId = $object['id'] ?? null;

        if ($subscriptionId === null) {
            return;
        }

        $user = $this->resolveUser($object);

        if ($user === null) {
            Log::warning('Subscription webhook for unknown user', ['subscription' => $subscriptionId]);

            return;
        }

        $remote = $this->stripe->client()->subscriptions->retrieve($subscriptionId);

        $this->billing->syncSubscription($user, $remote);
    }

    private function handleInvoicePaid(array $object): void
    {
        $subscriptionId = $this->subscriptionIdFromInvoice($object);

        if ($subscriptionId === null) {
            return;
        }

        $local = SubscriptionModel::where('provider_subscription_id', $subscriptionId)->first();

        if ($local === null) {
            return;
        }

        // Re-read rather than infer the new period from the invoice: the
        // provider has already computed it, and duplicating that arithmetic here
        // is how the mirror and the truth drift apart.
        $remote = $this->stripe->client()->subscriptions->retrieve($subscriptionId);

        $this->billing->syncSubscription($local->user, $remote);
    }

    private function handleInvoiceFailed(array $object): void
    {
        $subscriptionId = $this->subscriptionIdFromInvoice($object);

        if ($subscriptionId === null) {
            return;
        }

        $local = SubscriptionModel::where('provider_subscription_id', $subscriptionId)->first();

        if ($local === null) {
            return;
        }

        $remote = $this->stripe->client()->subscriptions->retrieve($subscriptionId);
        $this->billing->syncSubscription($local->user, $remote);

        // TODO(H4/H5): notify the partner with a direct link to update their
        // card. A dunning message with no link converts far worse than one with.
        Log::info('Subscription payment failed', [
            'user_id'      => $local->user_id,
            'subscription' => $subscriptionId,
        ]);
    }

    private function handlePaymentMethodAttached(array $object): void
    {
        $customerId = $object['customer'] ?? null;
        $user = $customerId ? User::where('provider_customer_id', $customerId)->first() : null;

        if ($user === null) {
            return;
        }

        $card        = $object['card'] ?? [];
        $fingerprint = $card['fingerprint'] ?? null;

        // A card refused as a duplicate is attached by the browser's SetupIntent
        // and then detached by BillingService::attachPaymentMethod(). Stripe does
        // not deliver events in order, so this event can be processed after that
        // detach. Recording it would put the refused card on the second account.
        if ($fingerprint !== null && CardFingerprint::isHeldByAnother($fingerprint, $user)) {
            Log::info('Ignored attach of a card held by another account', [
                'user_id'        => $user->id,
                'payment_method' => $object['id'] ?? null,
            ]);

            return;
        }

        // The same ordering problem for a card the partner has since removed:
        // record it only if the provider still has it on this customer.
        $current = $this->stripe->client()->paymentMethods->retrieve($object['id']);

        if ($current->customer !== $customerId) {
            return;
        }

        if ($fingerprint !== null) {
            CardFingerprint::claim($fingerprint, $user);
        }

        PaymentMethod::updateOrCreate(
            ['provider_payment_method_id' => $object['id']],
            [
                'user_id'     => $user->id,
                'fingerprint' => $card['fingerprint'] ?? null,
                'brand'       => $card['brand'] ?? null,
                'last4'       => $card['last4'] ?? null,
                'exp_month'   => $card['exp_month'] ?? null,
                'exp_year'    => $card['exp_year'] ?? null,
                'country'     => $card['country'] ?? null,
                'funding'     => $card['funding'] ?? null,
            ],
        );
    }

    private function handlePaymentMethodDetached(array $object): void
    {
        // Idempotent by construction: deleting an already-deleted row is a no-op.
        PaymentMethod::where('provider_payment_method_id', $object['id'] ?? '')->delete();
    }

    private function handleChargeRefunded(array $object): void
    {
        // TODO(D3): when a refunded charge backs a qualifying earning event, this
        // is where the clawback is raised. Left as a log until the earnings
        // ledger exists — raising a clawback against a ledger that does not yet
        // exist would fail loudly on a live webhook.
        Log::info('Charge refunded', [
            'charge'          => $object['id'] ?? null,
            'amount_refunded' => $object['amount_refunded'] ?? null,
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Find the local user behind a subscription payload.
     *
     * Metadata first because it is what this application wrote and is stable
     * across customer changes; the customer id is the fallback for objects
     * created outside this application, such as in the provider's dashboard.
     */
    private function resolveUser(array $object): ?User
    {
        $userId = $object['metadata']['user_id'] ?? null;

        if ($userId !== null && $user = User::find($userId)) {
            return $user;
        }

        $customerId = $object['customer'] ?? null;

        if (is_string($customerId)) {
            return User::where('provider_customer_id', $customerId)->first();
        }

        return null;
    }

    /**
     * The subscription id on an invoice payload.
     *
     * Its location moved between API versions — top level in older ones, on the
     * parent/line item in the Basil versions — so several shapes are checked
     * rather than assuming the one this account happens to send today.
     */
    private function subscriptionIdFromInvoice(array $invoice): ?string
    {
        $candidates = [
            $invoice['subscription'] ?? null,
            $invoice['parent']['subscription_details']['subscription'] ?? null,
            $invoice['lines']['data'][0]['parent']['subscription_item_details']['subscription'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }

            if (is_array($candidate) && isset($candidate['id'])) {
                return $candidate['id'];
            }
        }

        return null;
    }
}
