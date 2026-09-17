<?php

namespace App\Jobs;

use App\Models\PartnerWebhookDelivery;
use App\Services\Partner\PartnerWebhookDispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Delivers one recorded event to a partner company's endpoint.
 *
 * Off the request path because a claim must not fail, or wait, on somebody
 * else's server being up — the member is standing in front of the form.
 *
 * Retries are spaced widely. A partner endpoint that is failing is almost
 * always deployed, restarting or briefly down; retrying in ten seconds and then
 * giving up would miss all three, and retrying fast would just add our traffic
 * to whatever is already wrong. The last attempt lands roughly two hours out,
 * which covers an ordinary deploy window without a human touching anything.
 * Past that an admin replays it from the deliveries screen.
 */
class DeliverPartnerWebhookJob implements ShouldQueue
{
    use Queueable;

    /** @var array<int,int> */
    public array $backoff = [30, 300, 1800, 3600];

    public int $tries = 5;

    public function __construct(public int $deliveryId) {}

    public function handle(PartnerWebhookDispatcher $dispatcher): void
    {
        $delivery = PartnerWebhookDelivery::with('company')->find($this->deliveryId);

        if ($delivery === null || $delivery->isDelivered()) {
            return;
        }

        if ($dispatcher->deliver($delivery)) {
            return;
        }

        // Throwing is what tells the queue to retry with backoff. On the last
        // attempt Laravel calls failed() below, which is where the row gets its
        // final state — marking it failed here would mark it on attempt one.
        throw new \RuntimeException(
            "Partner webhook {$delivery->event_id} was not accepted: "
            . ($delivery->error ?? 'no response'),
        );
    }

    public function failed(\Throwable $e): void
    {
        $delivery = PartnerWebhookDelivery::find($this->deliveryId);

        if ($delivery !== null && ! $delivery->isDelivered()) {
            app(PartnerWebhookDispatcher::class)->abandon(
                $delivery,
                "Gave up after {$delivery->attempts} attempt(s). Last error: "
                . ($delivery->error ?? $e->getMessage()),
            );
        }
    }
}
