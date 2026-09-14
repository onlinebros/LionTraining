<?php

namespace App\Services\Stripe;

use Illuminate\Support\Facades\Log;
use Stripe\HttpClient\ClientInterface;

/**
 * Logs Stripe's advisory notices instead of letting them surface as PHP warnings.
 *
 * Stripe attaches a `Stripe-Notice` header to some responses (every v1 Accounts
 * call now carries "We recommend building your integration using Accounts v2"),
 * and stripe-php raises it with trigger_error(E_USER_WARNING). Laravel turns
 * warnings into exceptions during web requests, so every Connect call from the
 * site failed with a 500 even though Stripe had succeeded. Console commands
 * only printed the warning, which is why it went unnoticed there.
 *
 * Wraps Stripe's default HTTP client; installed in AppServiceProvider.
 */
class NoticeLoggingHttpClient implements ClientInterface
{
    public function __construct(private readonly ClientInterface $inner) {}

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        [$body, $status, $responseHeaders] = $this->inner->request($method, $absUrl, $headers, $params, $hasFile, $apiMode, $maxNetworkRetries);

        if (isset($responseHeaders['stripe-notice'])) {
            Log::notice('Stripe API notice', [
                'notice' => $responseHeaders['stripe-notice'],
                'path' => parse_url((string) $absUrl, PHP_URL_PATH),
            ]);

            unset($responseHeaders['stripe-notice']);
        }

        return [$body, $status, $responseHeaders];
    }
}
