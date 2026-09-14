<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Server-side check of a Cloudflare Turnstile token.
 *
 * Fails closed. No configured secret, an unreachable Cloudflare or an unusable
 * answer is UNAVAILABLE, never PASSED — the caller must not act on anything but
 * PASSED.
 */
class TurnstileVerifier
{
    public const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public const PASSED = 'passed';

    public const FAILED = 'failed';

    public const UNAVAILABLE = 'unavailable';

    /** @return self::PASSED|self::FAILED|self::UNAVAILABLE */
    public function verify(string $token, ?string $remoteIp = null): string
    {
        $secret = config('services.turnstile.secret_key');

        if (blank($secret)) {
            Log::error('Turnstile: no secret configured (TURNSTILE_SECRET_KEY); refusing to verify.');

            return self::UNAVAILABLE;
        }

        try {
            $response = Http::asForm()
                ->connectTimeout(3)
                ->timeout(5)
                ->post(self::VERIFY_URL, array_filter([
                    'secret' => $secret,
                    'response' => $token,
                    'remoteip' => $remoteIp,
                ]));
        } catch (ConnectionException $e) {
            Log::warning('Turnstile: siteverify unreachable.', ['error' => $e->getMessage()]);

            return self::UNAVAILABLE;
        }

        if ($response->failed() || ! is_array($response->json())) {
            Log::warning('Turnstile: siteverify returned an unusable response.', ['status' => $response->status()]);

            return self::UNAVAILABLE;
        }

        if ($response->json('success') !== true) {
            return self::FAILED;
        }

        // Empty allowlist skips the check: Cloudflare's test keys return a
        // dummy hostname. Production must set TURNSTILE_ALLOWED_HOSTNAMES.
        $allowed = array_map(fn ($host) => strtolower(trim($host)), config('services.turnstile.allowed_hostnames', []));
        $allowed = array_values(array_filter($allowed));

        if ($allowed !== [] && ! in_array(strtolower((string) $response->json('hostname')), $allowed, true)) {
            return self::FAILED;
        }

        return self::PASSED;
    }
}
