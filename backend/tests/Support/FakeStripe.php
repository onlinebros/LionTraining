<?php

namespace Tests\Support;

use Stripe\HttpClient\ClientInterface;

/**
 * Stands in for Stripe's HTTP API.
 *
 * Installed with Stripe\ApiRequestor::setHttpClient(), so StripeClient and its
 * services run unchanged and only the network call is replaced. It keeps an
 * in-memory set of payment methods, which is all card capture needs.
 */
class FakeStripe implements ClientInterface
{
    /** @var array<string, array<string, mixed>> */
    public array $paymentMethods = [];

    /** @var list<array{0: string, 1: string}> method and path of every request */
    public array $requests = [];

    /** A card payment method, optionally already attached to a customer. */
    public function card(string $id, string $fingerprint, ?string $customer = null, array $card = []): string
    {
        $this->paymentMethods[$id] = [
            'id' => $id,
            'object' => 'payment_method',
            'type' => 'card',
            'customer' => $customer,
            'card' => $card + [
                'fingerprint' => $fingerprint,
                'brand' => 'visa',
                'last4' => '4242',
                'exp_month' => 12,
                'exp_year' => 2030,
                'country' => 'US',
                'funding' => 'credit',
                'wallet' => null,
            ],
        ];

        return $id;
    }

    public function wasDetached(string $id): bool
    {
        return in_array(['post', "/v1/payment_methods/{$id}/detach"], $this->requests, true);
    }

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        $method = strtolower($method);
        $path = (string) parse_url($absUrl, PHP_URL_PATH);

        $this->requests[] = [$method, $path];

        if (preg_match('#^/v1/payment_methods/([^/]+)(?:/(attach|detach))?$#', $path, $m) && isset($this->paymentMethods[$m[1]])) {
            match ($m[2] ?? null) {
                'attach' => $this->paymentMethods[$m[1]]['customer'] = $params['customer'] ?? null,
                'detach' => $this->paymentMethods[$m[1]]['customer'] = null,
                default => null,
            };

            return $this->respond($this->paymentMethods[$m[1]]);
        }

        if (preg_match('#^/v1/customers/([^/]+)$#', $path, $m)) {
            return $this->respond(['id' => $m[1], 'object' => 'customer']);
        }

        return $this->respond([
            'error' => ['type' => 'invalid_request_error', 'message' => "FakeStripe has no route for {$method} {$path}"],
        ], 404);
    }

    private function respond(array $body, int $status = 200): array
    {
        return [json_encode($body), $status, []];
    }
}
