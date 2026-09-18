<?php

namespace App\Services\Vendor;

use App\Models\StripeWebhookEvent;
use App\Models\VendorLead;
use App\Support\Vendors;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Turns a vendor's payment event into a confirmed sale on our side.
 *
 * Deliberately separate from StripeWebhookProcessor. That one resolves objects
 * against *our* Stripe account — it re-reads subscriptions, looks up customers
 * by `provider_customer_id`. Run a vendor's event through it and every lookup
 * either misses or, worse, collides with an unrelated object of ours that
 * happens to share an id shape.
 *
 * This processor never calls the vendor's API. It has no credentials for it and
 * should not have any: everything it needs is in the signed payload.
 */
class VendorWebhookProcessor
{
    public function __construct(private readonly VendorReferralService $referrals) {}

    public function process(StripeWebhookEvent $event): void
    {
        $event->increment('processing_attempts');

        try {
            $vendor = Vendors::slugFromEndpoint((string) $event->endpoint);

            if ($vendor === null || Vendors::find($vendor) === null) {
                throw new \RuntimeException("Event is not attributable to a known vendor: {$event->endpoint}");
            }

            $payload = json_decode($event->payload, true, 512, JSON_THROW_ON_ERROR);
            $object  = $payload['data']['object'] ?? [];

            // An event recovered from the vendor's event log says so on the
            // order, so a sale confirmed that way shows the webhook missed it.
            $via = $event->wasFetchedFromApi() ? VendorLead::VIA_RECONCILIATION : VendorLead::VIA_WEBHOOK;

            match ($event->type) {
                'checkout.session.completed',
                'checkout.session.async_payment_succeeded' => $this->handleCheckoutCompleted($vendor, $object, $via),

                // Direct-charge mode: we build the PaymentIntent ourselves on the
                // vendor's account, so the sale arrives as a PaymentIntent event
                // rather than a Checkout Session.
                'payment_intent.succeeded' => $this->handlePaymentIntentSucceeded($vendor, $object, $via),

                /*
                 * The only place the receipt URL is reachable.
                 *
                 * A payment_intent.succeeded payload carries `latest_charge` as a
                 * bare id, Stripe does not expand objects in webhooks, and the
                 * restricted key the vendor issued denies reading charges — so
                 * fetching it is not an option either. The charge event's object
                 * IS the charge, receipt_url included.
                 */
                'charge.succeeded' => $this->handleChargeSucceeded($vendor, $object),

                'charge.refunded' => $this->handleRefund($vendor, $object),

                // Vendors send far more than we care about. Stored and
                // acknowledged, not treated as a failure.
                default => Log::debug('Unhandled vendor webhook type', [
                    'vendor' => $vendor,
                    'type'   => $event->type,
                ]),
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

            Log::error('Vendor webhook processing failed', [
                'event_id' => $event->stripe_event_id,
                'type'     => $event->type,
                'error'    => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * @param  array<string,mixed>  $object  A Stripe Checkout Session.
     */
    private function handleCheckoutCompleted(string $vendor, array $object, string $via): void
    {
        // An async payment method that has not cleared is not a sale yet.
        // Paying commission on it means clawing it back when it fails.
        $paymentStatus = $object['payment_status'] ?? null;

        if ($paymentStatus !== null && $paymentStatus !== 'paid' && $paymentStatus !== 'no_payment_required') {
            Log::info('Vendor checkout completed but not paid', [
                'vendor'  => $vendor,
                'session' => $object['id'] ?? null,
                'status'  => $paymentStatus,
            ]);

            return;
        }

        $lead = $this->referrals->resolve($vendor, $object);

        if ($lead === null) {
            // Unattributed, not lost. It sits on the admin reconciliation screen
            // with the raw event still in the ledger, and a human decides.
            Log::warning('Vendor sale could not be attributed to a lead', [
                'vendor'  => $vendor,
                'session' => $object['id'] ?? null,
                'email'   => $object['customer_details']['email'] ?? null,
            ]);

            return;
        }

        $this->referrals->convert($lead, [
            'session_id'        => $object['id'] ?? null,
            'payment_intent_id' => is_string($object['payment_intent'] ?? null) ? $object['payment_intent'] : null,
            'vendor_order_ref'  => $object['metadata']['order_id'] ?? ($object['id'] ?? null),
            // Their number, always. See VendorReferralService::convert().
            'amount_total'      => isset($object['amount_total']) ? (int) $object['amount_total'] : null,
            'currency'          => isset($object['currency']) ? strtoupper((string) $object['currency']) : null,
        ], $via);
    }

    /**
     * A sale we built ourselves on the vendor's account.
     *
     * @param  array<string,mixed>  $object  A Stripe PaymentIntent.
     */
    private function handlePaymentIntentSucceeded(string $vendor, array $object, string $via): void
    {
        $reference = $object['metadata']['order_reference'] ?? null;

        $lead = is_string($reference) && $reference !== ''
            ? VendorLead::forVendor($vendor)->where('public_ref', $reference)->first()
            : null;

        // Fall back to the intent id, then to the shared email/session logic.
        $lead ??= VendorLead::forVendor($vendor)
            ->where('provider_payment_intent_id', $object['id'] ?? '')
            ->first();

        $lead ??= $this->referrals->resolve($vendor, $object);

        if ($lead === null) {
            Log::warning('Vendor payment succeeded but matched no order', [
                'vendor' => $vendor,
                'intent' => $object['id'] ?? null,
                'ref'    => $reference,
            ]);

            return;
        }

        /*
         * The receipt lives on the charge, not the intent. Storing the URL means
         * support can hand a customer their proof of purchase without asking the
         * vendor for it — the single most common "can you check on my order"
         * request, answerable from our own screen.
         */
        $charge = $object['latest_charge'] ?? null;

        if (is_array($charge)) {
            $chargeId   = $charge['id'] ?? null;
            $receiptUrl = $charge['receipt_url'] ?? null;
        } else {
            $chargeId   = is_string($charge) ? $charge : null;
            $receiptUrl = null;
        }

        $lead->forceFill(array_filter([
            'stripe_charge_id'   => $chargeId,
            'stripe_receipt_url' => $receiptUrl,
        ]))->save();

        $this->referrals->convert($lead, [
            'payment_intent_id' => $object['id'] ?? null,
            'vendor_order_ref'  => $chargeId ?? ($object['id'] ?? null),
            // Their number, always — quantity or address can change what tax
            // was actually due between our quote and their charge.
            'amount_total'      => isset($object['amount_received']) ? (int) $object['amount_received'] : null,
            'currency'          => isset($object['currency']) ? strtoupper((string) $object['currency']) : null,
        ], $via);
    }

    /**
     * Store the customer's receipt link against the order.
     *
     * Purely additive — it never converts anything. Ordering between this and
     * payment_intent.succeeded is not guaranteed, so each does its own job and
     * neither depends on having run first.
     *
     * @param  array<string,mixed>  $object  A Stripe Charge.
     */
    private function handleChargeSucceeded(string $vendor, array $object): void
    {
        $paymentIntent = $object['payment_intent'] ?? null;

        if (! is_string($paymentIntent) || $paymentIntent === '') {
            return;
        }

        $lead = VendorLead::forVendor($vendor)
            ->where('provider_payment_intent_id', $paymentIntent)
            ->first();

        if ($lead === null) {
            return;
        }

        $lead->forceFill(array_filter([
            'stripe_charge_id'   => $object['id'] ?? null,
            'stripe_receipt_url' => $object['receipt_url'] ?? null,
        ]))->save();
    }

    /**
     * @param  array<string,mixed>  $object  A Stripe Charge.
     */
    private function handleRefund(string $vendor, array $object): void
    {
        $paymentIntent = $object['payment_intent'] ?? null;

        if (! is_string($paymentIntent) || $paymentIntent === '') {
            return;
        }

        $lead = VendorLead::forVendor($vendor)
            ->where('provider_payment_intent_id', $paymentIntent)
            ->first();

        if ($lead === null) {
            return;
        }

        // Partial refunds are not treated as a reversal — the sale stands and
        // the commission with it. Only a full refund unwinds the lead.
        $amount   = (int) ($object['amount'] ?? 0);
        $refunded = (int) ($object['amount_refunded'] ?? 0);

        if ($amount > 0 && $refunded < $amount) {
            Log::info('Partial vendor refund — commission left standing', [
                'lead'     => $lead->public_ref,
                'refunded' => $refunded,
                'amount'   => $amount,
            ]);

            return;
        }

        $this->referrals->refund($lead);
    }
}
