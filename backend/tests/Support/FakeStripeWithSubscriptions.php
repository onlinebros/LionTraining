<?php

namespace Tests\Support;

/** FakeStripe plus an in-memory subscriptions endpoint. */
class FakeStripeWithSubscriptions extends FakeStripe
{
    /** @var array<string, array<string, mixed>> */
    public array $subscriptions = [];

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        $method = strtolower($method);
        $path   = (string) parse_url($absUrl, PHP_URL_PATH);
        $params = is_array($params) ? $params : [];

        if ($method === 'post' && $path === '/v1/subscriptions') {
            $this->calls[] = ['method' => $method, 'path' => $path, 'params' => $params];
            $id = 'sub_fake'.(count($this->subscriptions) + 1);
            $trialEnd = isset($params['trial_end']) ? (int) $params['trial_end'] : null;

            $this->subscriptions[$id] = [
                'id'        => $id,
                'object'    => 'subscription',
                'status'    => $trialEnd ? 'trialing' : 'active',
                'trial_end' => $trialEnd,
                'metadata'  => $params['metadata'] ?? [],
                'cancel_at_period_end' => false,
                'items'     => ['object' => 'list', 'data' => [[
                    'id' => 'si_'.$id, 'object' => 'subscription_item',
                    'price' => ['id' => 'price_fake', 'object' => 'price', 'unit_amount' => 4999, 'currency' => 'usd'],
                ]]],
            ];

            return [json_encode($this->subscriptions[$id]), 200, []];
        }

        if (preg_match('#^/v1/subscriptions/([^/]+)$#', $path, $m) && isset($this->subscriptions[$m[1]])) {
            $this->calls[] = ['method' => $method, 'path' => $path, 'params' => $params];
            $sub = &$this->subscriptions[$m[1]];

            if ($method === 'post') {
                if (($params['trial_end'] ?? null) === 'now') {
                    $sub['trial_end'] = now()->getTimestamp();
                    $sub['status'] = 'active';
                } elseif (isset($params['trial_end'])) {
                    $sub['trial_end'] = (int) $params['trial_end'];
                }

                if (array_key_exists('cancel_at_period_end', $params)) {
                    // The client sends booleans as the strings "true" / "false".
                    $sub['cancel_at_period_end'] = filter_var($params['cancel_at_period_end'], FILTER_VALIDATE_BOOLEAN);
                }

                $sub['metadata'] = array_merge($sub['metadata'], $params['metadata'] ?? []);
            }

            if ($method === 'delete') {
                $sub['status'] = 'canceled';
                $sub['canceled_at'] = $sub['ended_at'] = now()->getTimestamp();
            }

            return [json_encode($sub), 200, []];
        }

        return parent::request($method, $absUrl, $headers, $params, $hasFile, $apiMode, $maxNetworkRetries);
    }
}
