<?php

namespace App\Services\Vendor;

use App\Jobs\ProcessPaymentWebhookJob;
use App\Models\StripeWebhookEvent;
use App\Support\Vendors;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * The backstop for a vendor webhook that never arrived.
 *
 * Reads the vendor's own event log with their restricted key and records any
 * event our endpoint has not seen, exactly as the endpoint would have: the same
 * ledger row, the same job, the same processor. A recovered event and a
 * delivered one cannot reach different conclusions about an order.
 *
 * Deduplication is the ledger's unique event id. An event the webhook already
 * delivered is skipped here, and one recovered here is acknowledged as a
 * duplicate if the webhook turns up late. Either order ends in one row and one
 * conversion.
 */
class VendorEventSync
{
    /**
     * The events the direct checkout depends on — the three the vendor's
     * webhook endpoint is subscribed to. Anything wider copies the rest of the
     * vendor's business into our ledger for no gain.
     */
    public const EVENT_TYPES = [
        'payment_intent.succeeded',
        'charge.succeeded',
        'charge.refunded',
    ];

    /** Stripe keeps events for 30 days; asking for more returns nothing extra. */
    private const MAX_LOOKBACK_HOURS = 720;

    public function __construct(private readonly VendorStripeClient $stripe) {}

    /** Why this vendor cannot be synced right now, or null if it can. */
    public function skipReason(string $vendor): ?string
    {
        $config = Vendors::find($vendor)['event_sync'] ?? [];

        return match (true) {
            Vendors::find($vendor) === null        => 'not a registered vendor',
            ! ($config['enabled'] ?? false)        => 'event sync is switched off',
            blank($config['since'] ?? null)        => 'no start time is configured',
            ! $this->stripe->isConfigured($vendor) => 'no Stripe key is configured',
            default                                => null,
        };
    }

    /**
     * @return array{from: CarbonImmutable, to: CarbonImmutable, seen: int, recovered: list<string>}
     */
    public function sync(string $vendor, bool $dryRun = false): array
    {
        [$from, $to] = $this->window($vendor);

        $result = ['from' => $from, 'to' => $to, 'seen' => 0, 'recovered' => []];

        // A start time still inside the grace period, or in the future.
        if ($from->greaterThanOrEqualTo($to)) {
            return $result;
        }

        $events = $this->stripe->for($vendor)->events->all([
            'types'   => self::EVENT_TYPES,
            'created' => ['gte' => $from->getTimestamp(), 'lte' => $to->getTimestamp()],
            'limit'   => 100,
        ]);

        foreach ($events->autoPagingIterator() as $event) {
            $result['seen']++;

            if ($this->recover($vendor, $event->toArray(), $dryRun)) {
                $result['recovered'][] = $event->id;
            }
        }

        return $result;
    }

    /**
     * Record one event unless the ledger already has it.
     *
     * @param  array<string,mixed>  $decoded
     */
    private function recover(string $vendor, array $decoded, bool $dryRun): bool
    {
        if ($dryRun) {
            return ! StripeWebhookEvent::where('stripe_event_id', $decoded['id'])->exists();
        }

        $inserted = StripeWebhookEvent::record(
            $decoded,
            json_encode($decoded, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            StripeWebhookEvent::SIGNATURE_API_FETCH,
            Vendors::endpointKey($vendor),
            // Not signed, but authentic: we asked Stripe for it with the
            // vendor's own key. The signature column records which it was.
            signatureValid: true,
        );

        if ($inserted === 0) {
            return false;
        }

        // A warning every time. One recovery is a blip; a run of them means the
        // vendor's endpoint is failing and someone should look at it.
        Log::warning('Vendor webhook event was missed; recovered from the event log', [
            'vendor' => $vendor,
            'event'  => $decoded['id'],
            'type'   => $decoded['type'] ?? null,
        ]);

        $row = StripeWebhookEvent::where('stripe_event_id', $decoded['id'])->firstOrFail();

        ProcessPaymentWebhookJob::dispatch($row->id);

        return true;
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function window(string $vendor): array
    {
        $config = Vendors::find($vendor)['event_sync'] ?? [];
        $now    = CarbonImmutable::now();

        // The newest events are left to the webhook, so an order confirmed by
        // reconciliation means delivery actually failed rather than lost a race.
        $to = $now->subMinutes(max(0, (int) ($config['grace_minutes'] ?? 10)));

        $hours = min(self::MAX_LOOKBACK_HOURS, max(1, (int) ($config['lookback_hours'] ?? 72)));

        // Never earlier than the configured start. Anything before it — a test
        // order paid before the webhook existed — was never meant to count. An
        // unset start parses as now, which leaves an empty window.
        $since = CarbonImmutable::parse((string) ($config['since'] ?? 'now'));

        return [$now->subHours($hours)->max($since), $to];
    }
}
