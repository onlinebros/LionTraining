<?php

namespace App\Jobs;

use App\Models\StripeWebhookEvent;
use App\Services\Stripe\StripeWebhookProcessor;
use App\Services\Vendor\VendorWebhookProcessor;
use App\Support\Vendors;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Processes one stored webhook event off the request path.
 *
 * The endpoint returns 200 as soon as the row is written; this does the work.
 * A provider that does not get a fast acknowledgement retries, so a slow
 * synchronous handler turns one event into five.
 *
 * Events arrive from more than one Stripe account, and which processor runs is
 * decided by the endpoint the event landed on — never by its contents. A
 * vendor's event carries customer and subscription ids from *their* account;
 * handing it to the processor that resolves ids against ours would at best miss
 * and at worst match something unrelated.
 */
class ProcessPaymentWebhookJob implements ShouldQueue
{
    use Queueable;

    /**
     * Retries are spaced out because the usual cause of failure is the provider
     * being briefly unavailable, and hammering it does not help.
     *
     * @var array<int,int>
     */
    public array $backoff = [10, 60, 300];

    public int $tries = 4;

    public function __construct(public int $eventId) {}

    public function handle(StripeWebhookProcessor $processor, VendorWebhookProcessor $vendorProcessor): void
    {
        $event = StripeWebhookEvent::find($this->eventId);

        if ($event === null) {
            return;
        }

        // Deliberately no "already processed, skip" guard. Replay exists so a
        // handler bug can be fixed and the affected events re-run, which is
        // impossible if the job refuses to touch a processed row. Safety comes
        // from the handlers being idempotent instead — re-running one reaches
        // the same end state rather than applying it twice.
        if (Vendors::slugFromEndpoint((string) $event->endpoint) !== null) {
            $vendorProcessor->process($event);

            return;
        }

        $processor->process($event);
    }
}
