<?php

namespace Tests\Support;

use Stripe\HttpClient\ClientInterface;

/**
 * Stands in for Stripe's HTTP API.
 *
 * Installed with Stripe\ApiRequestor::setHttpClient(), so StripeClient and its
 * services run unchanged and only the network call is replaced. It keeps
 * in-memory payment methods and connected accounts, and records every request
 * with its parameters.
 */
class FakeStripe implements ClientInterface
{
    /** @var array<string, array<string, mixed>> */
    public array $paymentMethods = [];

    /** @var array<string, array<string, mixed>> */
    public array $accounts = [];

    /** @var list<array{0: string, 1: string}> method and path of every request */
    public array $requests = [];

    /** @var list<array{method: string, path: string, params: array}> */
    public array $calls = [];

    /** Refuse the next account create that carries prefilled individual details. */
    public bool $rejectPrefill = false;

    /** A Stripe error code the next transfer create fails with. */
    public ?string $transferError = null;

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

    /** A connected account, as GET /v1/accounts/{id} returns it. */
    public function connectedAccount(string $id, array $overrides = []): string
    {
        $this->accounts[$id] = array_replace_recursive([
            'id' => $id,
            'object' => 'account',
            'charges_enabled' => false,
            'payouts_enabled' => false,
            'details_submitted' => false,
            'capabilities' => ['transfers' => 'inactive'],
            'controller' => ['requirement_collection' => 'application'],
            'requirements' => [
                'currently_due' => [],
                'eventually_due' => [],
                'past_due' => [],
                'pending_verification' => [],
                'disabled_reason' => null,
                'current_deadline' => null,
                'errors' => [],
            ],
            'future_requirements' => [
                'currently_due' => [],
                'eventually_due' => [],
                'current_deadline' => null,
                'errors' => [],
            ],
            'external_accounts' => ['object' => 'list', 'data' => []],
        ], $overrides);

        return $id;
    }

    public function wasDetached(string $id): bool
    {
        return in_array(['post', "/v1/payment_methods/{$id}/detach"], $this->requests, true);
    }

    /** @return list<array> the parameters of every request to this method and path */
    public function paramsFor(string $method, string $path): array
    {
        return array_values(array_map(
            fn ($call) => $call['params'],
            array_filter($this->calls, fn ($call) => $call['method'] === strtolower($method) && $call['path'] === $path),
        ));
    }

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        $method = strtolower($method);
        $path = (string) parse_url($absUrl, PHP_URL_PATH);
        $params = is_array($params) ? $params : [];

        $this->requests[] = [$method, $path];
        $this->calls[] = ['method' => $method, 'path' => $path, 'params' => $params];

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

        if ($method === 'post' && $path === '/v1/accounts') {
            if ($this->rejectPrefill && isset($params['individual'])) {
                $this->rejectPrefill = false;

                return $this->error('Invalid phone number.', 'individual[phone]');
            }

            $id = $this->connectedAccount('acct_fake'.(count($this->accounts) + 1), ['email' => $params['email'] ?? null]);

            return $this->respond($this->accounts[$id]);
        }

        if (preg_match('#^/v1/accounts/([^/]+)$#', $path, $m) && isset($this->accounts[$m[1]])) {
            return $this->respond($this->accounts[$m[1]]);
        }

        if ($method === 'post' && $path === '/v1/account_sessions') {
            return $this->respond(['object' => 'account_session', 'account' => $params['account'] ?? null, 'client_secret' => 'accs_secret_fake']);
        }

        if ($method === 'post' && $path === '/v1/transfers') {
            if ($code = $this->transferError) {
                $this->transferError = null;

                return $this->error('Transfer refused.', null, $code);
            }

            return $this->respond([
                'id' => 'tr_fake'.count($this->calls),
                'object' => 'transfer',
                'amount' => (int) ($params['amount'] ?? 0),
                'destination' => $params['destination'] ?? null,
                'reversed' => false,
            ]);
        }

        if ($method === 'post' && preg_match('#^/v1/transfers/([^/]+)/reversals$#', $path, $m)) {
            return $this->respond(['id' => 'trr_fake', 'object' => 'transfer_reversal', 'transfer' => $m[1]]);
        }

        return $this->respond([
            'error' => ['type' => 'invalid_request_error', 'message' => "FakeStripe has no route for {$method} {$path}"],
        ], 404);
    }

    private function error(string $message, ?string $param = null, ?string $code = null): array
    {
        return $this->respond([
            'error' => array_filter(['type' => 'invalid_request_error', 'message' => $message, 'param' => $param, 'code' => $code]),
        ], 400);
    }

    private function respond(array $body, int $status = 200): array
    {
        return [json_encode($body), $status, []];
    }
}
