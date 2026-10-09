<?php

namespace App\Services\Vendor;

use App\Models\StripeWebhookEvent;
use App\Models\VendorLead;
use App\Models\VendorPaymentAudit;
use App\Support\Vendors;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\PermissionException;
use Throwable;

/**
 * Reads what the vendor's Stripe says happened to the orders we built on it.
 *
 * The webhook tells us about successes and refunds only. An order whose card
 * was declined, or whose buyer walked away from Affirm, just sits at
 * "handed off" with no explanation. This asks Stripe directly, with the
 * vendor's restricted key, and writes the answer to vendor_payment_audits.
 *
 * Strictly read-only towards both sides: no Stripe object is created or
 * changed, and no order is confirmed here. A payment Stripe holds as succeeded
 * that we have not recorded is flagged for a person, and recovered through the
 * event sync, so a sale is only ever confirmed one way.
 *
 * Stripe objects are read as arrays throughout. A missing property on a
 * StripeObject raises a notice, and a notice in a web request is a 500.
 */
class VendorPaymentAuditor
{
    /** The event types that describe a payment attempt and its result. */
    public const ATTEMPT_EVENTS = [
        'payment_intent.payment_failed',
        'payment_intent.requires_action',
        'payment_intent.processing',
        'payment_intent.canceled',
        'payment_intent.succeeded',
    ];

    /** Stripe keeps events for 30 days. */
    private const MAX_DAYS = 30;

    /** Enough for any real backlog; a bound so one run cannot spin forever. */
    private const MAX_ORDERS = 500;

    /**
     * Read-only calls that show what the key may do. Label, and why it matters
     * to us. `null` in the third slot means a list call on that service.
     *
     * @var array<string, array{0:string, 1:string}>
     */
    public const PROBES = [
        'paymentIntents'   => ['Payments (PaymentIntents)', 'Each order\'s status and the reason a payment failed. The audit depends on it.'],
        'events'           => ['Event log', 'Every attempt on an order, and recovery of missed webhooks. The audit depends on it.'],
        'webhookEndpoints' => ['Webhook endpoints', 'Lets us check their endpoint to us is still switched on.'],
        'customers'        => ['Customers', 'Needed to place orders. Read access is listed for completeness.'],
        'charges'          => ['Charges & refunds', 'Not required: the latest charge comes with the payment, and the rest from the event log.'],
        'balance'          => ['Balance', 'Not required, and not expected to be granted.'],
        'payouts'          => ['Payouts', 'Not required, and not expected to be granted.'],
    ];

    public function __construct(private readonly VendorStripeClient $stripe) {}

    public function skipReason(string $vendor): ?string
    {
        return match (true) {
            Vendors::find($vendor) === null        => 'not a registered vendor',
            ! $this->stripe->isConfigured($vendor) => 'no Stripe key is configured',
            default                                => null,
        };
    }

    /**
     * Audit every order placed on the vendor's Stripe in the last `$days`,
     * plus any still waiting on payment however old.
     *
     * @return array{checked:int, verdicts:array<string,int>, unreadable:int, events_error:?string}
     */
    public function audit(string $vendor, int $days = self::MAX_DAYS): array
    {
        $days   = min(self::MAX_DAYS, max(1, $days));
        $cutoff = now()->subDays($days);

        $leads = VendorLead::query()
            ->where('vendor', $vendor)
            ->whereNotNull('provider_payment_intent_id')
            ->where(fn ($q) => $q->where('handed_off_at', '>=', $cutoff)
                ->orWhere('status', VendorLead::STATUS_HANDED_OFF))
            ->orderBy('id')
            ->limit(self::MAX_ORDERS)
            ->get();

        $result = ['checked' => 0, 'verdicts' => [], 'unreadable' => 0, 'events_error' => null];

        if ($leads->isEmpty()) {
            return $result;
        }

        // One pass over the event log for every order, rather than a call each.
        try {
            $attempts = $this->attempts($vendor, $leads->pluck('provider_payment_intent_id')->all(), $days);
        } catch (Throwable $e) {
            // The intents alone still answer "why did it fail"; the history is
            // a bonus. Carry on without it rather than audit nothing.
            $attempts = [];
            $result['events_error'] = $e->getMessage();
            Log::warning('Vendor payment audit could not read the event log', ['vendor' => $vendor, 'error' => $e->getMessage()]);
        }

        foreach ($leads as $lead) {
            $audit = $this->auditLead($lead, $attempts[$lead->provider_payment_intent_id] ?? []);

            $result['checked']++;
            $result['verdicts'][$audit->verdict] = ($result['verdicts'][$audit->verdict] ?? 0) + 1;

            if ($audit->check_error !== null) {
                $result['unreadable']++;
            }
        }

        return $result;
    }

    /**
     * Read one order's intent and record what it says.
     *
     * @param  list<array<string,mixed>>  $newAttempts  from the event log, if already fetched
     */
    public function auditLead(VendorLead $lead, array $newAttempts = []): VendorPaymentAudit
    {
        $existing = VendorPaymentAudit::firstWhere('vendor_lead_id', $lead->id);
        $attempts = $this->mergeAttempts($existing?->attempts ?? [], $newAttempts);

        try {
            $intent = $this->stripe->for($lead->vendor)->paymentIntents
                ->retrieve($lead->provider_payment_intent_id, ['expand' => ['latest_charge']])
                ->toArray();
        } catch (Throwable $e) {
            // A blip must not wipe what we already know about the order. Keep
            // the last good reading and note why this one failed.
            return VendorPaymentAudit::updateOrCreate(['vendor_lead_id' => $lead->id], [
                'vendor'            => $lead->vendor,
                'payment_intent_id' => $lead->provider_payment_intent_id,
                'verdict'           => $existing->verdict ?? VendorPaymentAudit::VERDICT_UNREADABLE,
                'attempts'          => $attempts,
                'check_error'       => $e->getMessage(),
                'checked_at'        => now(),
            ]);
        }

        $error  = (array) ($intent['last_payment_error'] ?? []);
        $charge = is_array($intent['latest_charge'] ?? null) ? $intent['latest_charge'] : [];

        return VendorPaymentAudit::updateOrCreate(['vendor_lead_id' => $lead->id], [
            'vendor'              => $lead->vendor,
            'payment_intent_id'   => $lead->provider_payment_intent_id,
            'verdict'             => $this->verdict($lead, $intent, $error, $charge),
            'intent_status'       => $intent['status'] ?? null,
            'intent_amount'       => $intent['amount'] ?? null,
            'intent_created_at'   => isset($intent['created']) ? CarbonImmutable::createFromTimestamp($intent['created']) : null,
            'cancellation_reason' => $intent['cancellation_reason'] ?? null,
            'next_action'         => $intent['next_action']['type'] ?? null,
            'payment_method_type' => $charge['payment_method_details']['type']
                ?? $error['payment_method']['type'] ?? null,
            'failure_type'        => $error['type'] ?? null,
            'failure_code'        => $error['code'] ?? null,
            'decline_code'        => $error['decline_code'] ?? null,
            'failure_message'     => $error['message'] ?? null,
            'outcome_type'        => $charge['outcome']['type'] ?? null,
            'outcome_reason'      => $charge['outcome']['reason'] ?? null,
            'seller_message'      => $charge['outcome']['seller_message'] ?? null,
            'risk_level'          => $charge['outcome']['risk_level'] ?? null,
            'attempts'            => $attempts,
            'check_error'         => null,
            'checked_at'          => now(),
        ]);
    }

    /**
     * What the vendor's key can read, tried call by call. Only the outcome is
     * kept, never the data a call returns.
     *
     * @return array{checked_at:string, probes:array<string,array{label:string, why:string, result:string, detail:?string}>, webhooks:list<array<string,mixed>>|null}
     */
    public function access(string $vendor, bool $fresh = false): array
    {
        $key = "vendor-payment-audit:access:{$vendor}";

        if ($fresh) {
            Cache::forget($key);
        }

        return Cache::remember($key, now()->addMinutes(30), function () use ($vendor) {
            $client   = $this->stripe->for($vendor);
            $probes   = [];
            $webhooks = null;

            foreach (self::PROBES as $service => [$label, $why]) {
                try {
                    $response = $service === 'balance'
                        ? $client->balance->retrieve()
                        : $client->{$service}->all(['limit' => $service === 'webhookEndpoints' ? 100 : 1]);

                    $probes[$service] = ['label' => $label, 'why' => $why, 'result' => 'allowed', 'detail' => null];

                    if ($service === 'webhookEndpoints') {
                        $webhooks = $this->ourWebhooks($vendor, $response->toArray()['data'] ?? []);
                    }
                } catch (Throwable $e) {
                    $denied = $e instanceof PermissionException
                        || ($e instanceof ApiErrorException && $e->getHttpStatus() === 403)
                        || str_contains($e->getMessage(), 'required permissions');

                    $probes[$service] = [
                        'label'  => $label,
                        'why'    => $why,
                        'result' => $denied ? 'denied' : 'error',
                        // Stripe's denial names the permission that would allow
                        // it, which is exactly what to ask the vendor for.
                        'detail' => $denied ? $this->missingPermission($e->getMessage()) : $e->getMessage(),
                    ];
                }
            }

            return ['checked_at' => now()->toIso8601String(), 'probes' => $probes, 'webhooks' => $webhooks];
        });
    }

    /**
     * Our own side of the monitoring: what would let a payment go unnoticed.
     *
     * @return list<array{ok:bool, label:string, detail:string}>
     */
    public function health(string $vendor): array
    {
        $config   = Vendors::find($vendor) ?? [];
        $sync     = $config['event_sync'] ?? [];
        $endpoint = Vendors::endpointKey($vendor);

        $last   = StripeWebhookEvent::where('endpoint', $endpoint)->latest('received_at')->first();
        $failed = StripeWebhookEvent::where('endpoint', $endpoint)
            ->where('status', StripeWebhookEvent::STATUS_FAILED)
            ->where('received_at', '>=', now()->subDays(30))
            ->count();

        return [
            [
                'ok'     => filled($config['webhook_secret'] ?? null),
                'label'  => 'Webhook signing secret installed',
                'detail' => filled($config['webhook_secret'] ?? null)
                    ? 'Successful payments are confirmed the moment Stripe sends them.'
                    : 'The endpoint refuses every delivery until it is set.',
            ],
            [
                'ok'     => ($sync['enabled'] ?? false) && filled($sync['since'] ?? null),
                'label'  => 'Missed-webhook recovery running',
                'detail' => ($sync['enabled'] ?? false) && filled($sync['since'] ?? null)
                    ? 'Every 15 minutes, from '.$sync['since'].'.'
                    : 'Switched off: PLASMAGUARD_EVENT_SYNC_SINCE is not set, so a payment whose webhook is lost is never picked up.',
            ],
            [
                'ok'     => $last !== null,
                'label'  => 'Last webhook received',
                'detail' => $last ? $last->received_at->diffForHumans().' ('.$last->type.')' : 'None ever received on this server.',
            ],
            [
                'ok'     => $failed === 0,
                'label'  => 'Webhook processing failures (30 days)',
                'detail' => $failed === 0 ? 'None.' : "{$failed} event(s) from this vendor failed to process.",
            ],
        ];
    }

    // ── Internals ─────────────────────────────────────────────────────────────

    /**
     * @param  array<string,mixed>  $intent
     * @param  array<string,mixed>  $error
     * @param  array<string,mixed>  $charge
     */
    private function verdict(VendorLead $lead, array $intent, array $error, array $charge): string
    {
        $recorded = in_array($lead->status, [VendorLead::STATUS_CONVERTED, VendorLead::STATUS_REFUNDED], true);
        $status   = $intent['status'] ?? null;

        if ($status === 'succeeded') {
            return $recorded ? VendorPaymentAudit::VERDICT_PAID : VendorPaymentAudit::VERDICT_PAID_NOT_RECORDED;
        }

        if ($recorded) {
            return VendorPaymentAudit::VERDICT_RECORDED_NOT_PAID;
        }

        return match ($status) {
            'processing', 'requires_capture' => VendorPaymentAudit::VERDICT_PROCESSING,
            'requires_action'                => VendorPaymentAudit::VERDICT_AWAITING_CUSTOMER,
            'canceled'                       => VendorPaymentAudit::VERDICT_CANCELED,
            'requires_payment_method'        => match (true) {
                $error === []                       => VendorPaymentAudit::VERDICT_NOT_SUBMITTED,
                $this->abandoned($error, $charge)   => VendorPaymentAudit::VERDICT_ABANDONED,
                default                             => VendorPaymentAudit::VERDICT_DECLINED,
            },
            default => VendorPaymentAudit::VERDICT_NOT_SUBMITTED,
        };
    }

    /**
     * The buyer left rather than being refused: cancelled out of Affirm or
     * Klarna, or let the redirect expire. Stripe files both under card_error,
     * so they have to be told apart by code.
     *
     * @param  array<string,mixed>  $error
     * @param  array<string,mixed>  $charge
     */
    private function abandoned(array $error, array $charge): bool
    {
        $decline = (string) ($error['decline_code'] ?? '');
        $reason  = (string) ($charge['outcome']['reason'] ?? '');

        return ($error['code'] ?? null) === 'payment_intent_payment_attempt_expired'
            || str_ends_with($decline, '_canceled')
            || str_ends_with($reason, '_canceled')
            || $reason === 'customer_request_expired';
    }

    /**
     * Every attempt on the given intents, from the vendor's event log.
     *
     * @param  list<string>  $intentIds
     * @return array<string, list<array<string,mixed>>>
     */
    private function attempts(string $vendor, array $intentIds, int $days): array
    {
        $wanted = array_flip($intentIds);
        $found  = [];

        $events = $this->stripe->for($vendor)->events->all([
            'types'   => self::ATTEMPT_EVENTS,
            'created' => ['gte' => now()->subDays($days)->getTimestamp()],
            'limit'   => 100,
        ]);

        foreach ($events->autoPagingIterator() as $event) {
            $event  = $event->toArray();
            $intent = $event['data']['object'] ?? [];
            $id     = $intent['id'] ?? null;

            if ($id === null || ! isset($wanted[$id])) {
                continue;   // The vendor's own sales, not ours.
            }

            $error = (array) ($intent['last_payment_error'] ?? []);

            $found[$id][] = array_filter([
                'event'   => $event['id'],
                'at'      => $event['created'],
                'type'    => substr((string) $event['type'], strlen('payment_intent.')),
                'method'  => $error['payment_method']['type'] ?? null,
                'code'    => $error['code'] ?? null,
                'decline' => $error['decline_code'] ?? null,
                'message' => $error['message'] ?? null,
                'action'  => $intent['next_action']['type'] ?? null,
                'reason'  => $intent['cancellation_reason'] ?? null,
            ], static fn ($v) => $v !== null && $v !== '');
        }

        return $found;
    }

    /**
     * Union by event id, oldest first. Stored history outlives Stripe's 30 days.
     *
     * @param  list<array<string,mixed>>  $old
     * @param  list<array<string,mixed>>  $new
     * @return list<array<string,mixed>>
     */
    private function mergeAttempts(array $old, array $new): array
    {
        $byId = [];

        foreach ([...$old, ...$new] as $attempt) {
            $byId[$attempt['event'] ?? md5((string) json_encode($attempt))] = $attempt;
        }

        $merged = array_values($byId);
        usort($merged, static fn ($a, $b) => ($a['at'] ?? 0) <=> ($b['at'] ?? 0));

        return $merged;
    }

    /**
     * The vendor's endpoints that point at us, with their state.
     *
     * @param  list<array<string,mixed>>  $endpoints
     * @return list<array<string,mixed>>
     */
    private function ourWebhooks(string $vendor, array $endpoints): array
    {
        $path = "/api/webhooks/vendor/{$vendor}";

        return array_values(array_map(static fn ($e) => [
            'id'          => $e['id'] ?? null,
            'url'         => $e['url'] ?? null,
            'status'      => $e['status'] ?? null,
            'api_version' => $e['api_version'] ?? null,
            'events'      => $e['enabled_events'] ?? [],
        ], array_filter($endpoints, static fn ($e) => str_contains((string) ($e['url'] ?? ''), $path))));
    }

    /** "Enabling Charges and Refunds Read ('charge_read')…" → "Charges and Refunds Read (charge_read)". */
    private function missingPermission(string $message): string
    {
        return preg_match("/Enabling (.+?) \\('([a-z_]+)'\\)/", $message, $m)
            ? "{$m[1]} ({$m[2]})"
            : 'Not granted to this key.';
    }
}
