<?php

namespace App\Services\Vendor;

use App\Models\VendorLead;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * What the vendor's Stripe says became of a buyer's payment, in the terms the
 * buyer needs: has it been paid, is it on its way, or is it not happening.
 *
 * The completion page used to say "your order is placed" to everyone who came
 * back from Stripe, including buyers who had backed out of Affirm halfway
 * through. They believed they had ordered. Everything the buyer is told about
 * an order now comes from here, and only PAID — Stripe reporting the intent
 * `succeeded`, read server-side with the vendor's key, or our own webhook
 * having already converted the order — is allowed to say "placed".
 *
 * `requires_capture` is deliberately PENDING, not PAID: an authorisation is a
 * promise of funds, not funds.
 */
final class PaymentState
{
    /** Stripe reports the payment succeeded: funds received by the vendor. */
    public const PAID = 'paid';

    /** Submitted and accepted for processing (a bank payment clearing), not yet received. */
    public const PENDING = 'pending';

    /** Started but waiting on the buyer — a financing application or bank check left open. */
    public const ACTION = 'action';

    /** Cancelled, expired or declined. Nothing charged, nothing ordered. */
    public const NOT_PAID = 'not_paid';

    /** We could not ask Stripe. Never presented as an order. */
    public const UNKNOWN = 'unknown';

    private function __construct(
        public readonly string $state,
        public readonly ?string $method = null,
        public readonly bool $abandoned = false,
        public readonly ?string $nextAction = null,
        public readonly ?string $stripeStatus = null,
    ) {}

    /**
     * Read the lead's PaymentIntent from the vendor's account.
     *
     * A converted lead is PAID without a call: the webhook already proved it.
     */
    public static function for(VendorLead $lead, VendorStripeClient $stripe): self
    {
        if ($lead->isConverted()) {
            return new self(self::PAID);
        }

        if (! filled($lead->provider_payment_intent_id)) {
            return new self(self::UNKNOWN);
        }

        try {
            $client = $stripe->for($lead->vendor);
            // The vendor's restricted key may expand the charge (the audit
            // relies on it), which is where a completed payment's method lives.
            $intent = $client->paymentIntents
                ->retrieve($lead->provider_payment_intent_id, ['expand' => ['latest_charge']])
                ->toArray();
        } catch (Throwable $e) {
            Log::info('Could not read payment status for an order', [
                'lead'  => $lead->public_ref,
                'error' => $e->getMessage(),
            ]);

            return new self(self::UNKNOWN);
        }

        $state = self::fromIntent($intent);

        // An unfinished redirect carries the method only as an id. Reading it
        // needs a permission the vendor's key may not have; without it the
        // buyer gets the general wording rather than an error.
        if ($state->method === null && is_string($intent['payment_method'] ?? null)) {
            try {
                $type = $client->paymentMethods->retrieve($intent['payment_method'])->type ?? null;

                return new self($state->state, $type, $state->abandoned, $state->nextAction, $state->stripeStatus);
            } catch (Throwable) {
                // General wording it is.
            }
        }

        return $state;
    }

    /** @param  array<string,mixed>  $intent  A PaymentIntent as Stripe returns it. */
    public static function fromIntent(array $intent): self
    {
        $status = $intent['status'] ?? null;
        $error  = (array) ($intent['last_payment_error'] ?? []);
        $charge = is_array($intent['latest_charge'] ?? null) ? $intent['latest_charge'] : [];
        $action = $intent['next_action']['type'] ?? null;

        $method = $charge['payment_method_details']['type']
            ?? $error['payment_method']['type']
            ?? (is_array($intent['payment_method'] ?? null) ? ($intent['payment_method']['type'] ?? null) : null);

        $state = match ($status) {
            'succeeded'                                    => self::PAID,
            'processing', 'requires_capture'               => self::PENDING,
            'requires_action', 'requires_confirmation'     => self::ACTION,
            'requires_payment_method', 'canceled'          => self::NOT_PAID,
            default                                        => self::UNKNOWN,
        };

        return new self($state, $method, self::abandoned($error, $charge), $action, $status);
    }

    public function isPaid(): bool
    {
        return $this->state === self::PAID;
    }

    /** Not paid, and the buyer should hear so: everything but PAID and UNKNOWN. */
    public function isIncomplete(): bool
    {
        return in_array($this->state, [self::PENDING, self::ACTION, self::NOT_PAID], true);
    }

    /** Money may still arrive on this intent; a second payment would double-charge. */
    public function isInFlight(): bool
    {
        return $this->state === self::PAID || $this->state === self::PENDING;
    }

    /**
     * Left rather than refused: cancelled out of the provider or let it expire.
     * The payment audit asks the same question here, so the two never disagree.
     *
     * Klarna reports "the buyer cancelled" as its own code. Affirm does not:
     * `affirm_checkout_canceled` covers both the buyer closing it and Affirm
     * turning them down, so for Affirm "abandoned" means "one of the two".
     *
     * @param  array<string,mixed>  $error
     * @param  array<string,mixed>  $charge
     */
    public static function abandoned(array $error, array $charge): bool
    {
        $decline = (string) ($error['decline_code'] ?? '');
        $reason  = (string) ($charge['outcome']['reason'] ?? '');

        return ($error['code'] ?? null) === 'payment_intent_payment_attempt_expired'
            || ($error['code'] ?? null) === 'payment_method_customer_decline'
            || str_ends_with($decline, '_canceled')
            || str_ends_with($reason, '_canceled')
            || $reason === 'customer_request_expired';
    }
}
