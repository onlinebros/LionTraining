<?php

namespace App\Services\Stripe;

use App\Exceptions\BillingException;
use Stripe\StripeClient;

/**
 * The single place a configured StripeClient is built.
 *
 * Everything provider-facing resolves its client here, so the API version is
 * pinned in exactly one spot and no service reaches for the raw secret key.
 */
class StripeClientFactory
{
    private ?StripeClient $client = null;

    public function client(): StripeClient
    {
        if ($this->client !== null) {
            return $this->client;
        }

        $secret = config('stripe.secret');

        if (blank($secret)) {
            throw BillingException::notConfigured();
        }

        return $this->client = new StripeClient([
            'api_key'        => $secret,
            'stripe_version' => config('stripe.api_version'),
        ]);
    }

    /**
     * An idempotency key derived from the request parameters.
     *
     * Stripe remembers a key for 24 hours and rejects reuse of it with
     * *different* parameters. A fixed key like "subscription_user_7" is
     * therefore a trap: the moment the parameters change — a bug fix, a config
     * change, a partner editing their name — that user is wedged for a full day
     * and every retry fails identically.
     *
     * Hashing the parameters keeps the protection that matters (a double-clicked
     * form sends byte-identical parameters and dedupes) while making a genuine
     * parameter change a genuinely different request. Callers that must also
     * guard against near-simultaneous requests whose parameters differ slightly
     * take a lock as well — see BillingService::startSubscription().
     */
    public function idempotencyKey(string $scope, array $params): string
    {
        $normalize = function (array $value) use (&$normalize): array {
            ksort($value);

            foreach ($value as $k => $v) {
                if (is_array($v)) {
                    $value[$k] = $normalize($v);
                }
            }

            return $value;
        };

        $digest = substr(hash('sha256', json_encode($normalize($params))), 0, 32);

        return "{$scope}_{$digest}";
    }

    public function isConfigured(): bool
    {
        return filled(config('stripe.secret')) && filled(config('stripe.key'));
    }

    public function isTestMode(): bool
    {
        return str_starts_with((string) config('stripe.secret'), 'sk_test_');
    }

    public function publishableKey(): ?string
    {
        return config('stripe.key');
    }
}
