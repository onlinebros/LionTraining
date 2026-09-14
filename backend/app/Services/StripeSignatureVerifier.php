<?php

namespace App\Services;

/**
 * Verifies the Stripe-Signature header against a raw payload using the
 * v1 HMAC-SHA256 scheme. Implemented locally so we don't take a hard
 * dependency on the Stripe SDK for the webhook ingress path — only the
 * cryptographic primitive (`hash_hmac`) and a constant-time compare are
 * required, and both ship with PHP.
 *
 * Header format (from Stripe docs):
 *   t=1492774577,v1=5257a869e7ec...,v1=...
 *
 * The signed payload is the literal string `t.{timestamp}.{raw_body}`.
 * A request is valid when at least one v1 signature matches AND the
 * timestamp is within the tolerance window from "now".
 */
class StripeSignatureVerifier
{
    public function __construct(
        private readonly string $secret,
        private readonly int $toleranceSeconds = 300,
    ) {}

    /**
     * @return array{ok: true, timestamp: int}|array{ok: false, reason: string}
     */
    public function verify(string $rawPayload, ?string $header, ?int $now = null): array
    {
        if ($header === null || $header === '') {
            return ['ok' => false, 'reason' => 'missing_signature_header'];
        }

        $parsed = $this->parseHeader($header);
        if ($parsed === null) {
            return ['ok' => false, 'reason' => 'malformed_signature_header'];
        }

        [$timestamp, $signatures] = $parsed;
        if ($signatures === []) {
            return ['ok' => false, 'reason' => 'no_v1_signatures'];
        }

        $now ??= time();
        if (abs($now - $timestamp) > $this->toleranceSeconds) {
            return ['ok' => false, 'reason' => 'timestamp_outside_tolerance'];
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$rawPayload, $this->secret);

        foreach ($signatures as $candidate) {
            if (hash_equals($expected, $candidate)) {
                return ['ok' => true, 'timestamp' => $timestamp];
            }
        }

        return ['ok' => false, 'reason' => 'signature_mismatch'];
    }

    /**
     * @return array{0: int, 1: array<int, string>}|null
     */
    private function parseHeader(string $header): ?array
    {
        $timestamp  = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            $kv = explode('=', trim($part), 2);
            if (count($kv) !== 2) {
                continue;
            }
            [$key, $value] = $kv;
            if ($key === 't') {
                if (!ctype_digit($value)) {
                    return null;
                }
                $timestamp = (int) $value;
            } elseif ($key === 'v1') {
                $signatures[] = $value;
            }
        }

        if ($timestamp === null) {
            return null;
        }

        return [$timestamp, $signatures];
    }
}
