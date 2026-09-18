<?php

namespace App\Http\Controllers\Concerns;

use App\Jobs\ProcessPaymentWebhookJob;
use App\Models\StripeWebhookEvent;
use App\Services\StripeSignatureVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The landing-pad half of a Stripe-signed webhook endpoint.
 *
 * Shared because we now terminate signed events from more than one account:
 * our own platform and Connect endpoints, and a vendor's account whose orders
 * our partners refer. The verification, ledger write and dispatch are identical
 * in every case — only the secret and the endpoint discriminator differ, and
 * those are the two arguments.
 *
 * The contract is deliberately narrow: verify the signature against the raw
 * body, insert into the idempotency-keyed ledger, queue the processing, return
 * 200. No processing happens in the request path — a provider that does not get
 * a fast acknowledgement retries, so a slow synchronous handler turns one event
 * into five.
 */
trait IngestsSignedWebhooks
{
    protected function ingest(Request $request, string $endpoint, string $secret): JsonResponse
    {
        if ($secret === '') {
            // Refuse to silently accept anything when the gate is not
            // configured — an unsigned acceptance path is worse than a 503.
            return response()->json(['error' => 'webhook_not_configured'], 503);
        }

        $rawPayload      = $request->getContent();
        $signatureHeader = $request->header('Stripe-Signature');

        $verifier = new StripeSignatureVerifier(
            $secret,
            (int) config('stripe.webhook_tolerance', 300),
        );

        $result  = $verifier->verify($rawPayload, $signatureHeader);
        $decoded = json_decode($rawPayload, true);

        if ($result['ok'] !== true) {
            // Stored rather than dropped, so an unexplained gap in the provider's
            // delivery log can be investigated from this side. Recorded with
            // signature_valid = false and never dispatched for processing.
            if (is_array($decoded) && isset($decoded['id']) && is_string($decoded['id'])) {
                $this->recordWebhookEvent($decoded, $rawPayload, $signatureHeader, $endpoint, signatureValid: false);
            }

            return response()->json(['error' => $result['reason']], 400);
        }

        if (! is_array($decoded) || ! isset($decoded['id']) || ! is_string($decoded['id'])) {
            return response()->json(['error' => 'invalid_payload'], 400);
        }

        $eventId = $decoded['id'];

        // insertOrIgnore rather than create()-and-catch. On Postgres this
        // compiles to ON CONFLICT DO NOTHING, the only form safe inside a
        // surrounding transaction: a raised unique violation aborts the whole
        // Postgres transaction (SQLSTATE 25P02) and every later statement in it
        // fails, so catch-and-continue works on MySQL and sqlite but corrupts
        // the request on Postgres.
        $inserted = $this->recordWebhookEvent($decoded, $rawPayload, $signatureHeader, $endpoint, signatureValid: true);

        if ($inserted === 0) {
            return response()->json([
                'received'   => true,
                'idempotent' => true,
                'event_id'   => $eventId,
            ]);
        }

        $row = StripeWebhookEvent::where('stripe_event_id', $eventId)->first();

        if ($row !== null) {
            ProcessPaymentWebhookJob::dispatch($row->id);
        }

        return response()->json([
            'received'   => true,
            'idempotent' => false,
            'event_id'   => $eventId,
        ]);
    }

    /**
     * @param  array<string,mixed>  $decoded
     * @return int Rows inserted — 0 means the event was already recorded.
     */
    protected function recordWebhookEvent(
        array $decoded,
        string $rawPayload,
        ?string $signatureHeader,
        string $endpoint,
        bool $signatureValid,
    ): int {
        return StripeWebhookEvent::record($decoded, $rawPayload, $signatureHeader, $endpoint, $signatureValid);
    }
}
