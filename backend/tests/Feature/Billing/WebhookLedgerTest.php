<?php

namespace Tests\Feature\Billing;

use App\Jobs\ProcessPaymentWebhookJob;
use App\Models\StripeWebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * C2 acceptance: the ledger stores everything, deduplicates, keeps rejected
 * payloads for inspection, and never processes in the request path.
 */
class WebhookLedgerTest extends TestCase
{
    use RefreshDatabase;

    private const ACCOUNT_SECRET = 'whsec_account_test';
    private const CONNECT_SECRET = 'whsec_connect_test';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'stripe.webhook_secret'         => self::ACCOUNT_SECRET,
            'stripe.webhook_connect_secret' => self::CONNECT_SECRET,
            'stripe.webhook_tolerance'      => 300,
        ]);
    }

    private function payload(string $id = 'evt_1', string $type = 'customer.subscription.updated'): string
    {
        return json_encode([
            'id'       => $id,
            'type'     => $type,
            'created'  => time() - 5,
            'livemode' => false,
            'data'     => ['object' => ['id' => 'sub_1', 'customer' => 'cus_1']],
        ]);
    }

    private function sign(string $payload, string $secret): string
    {
        $t = time();

        return "t={$t},v1=" . hash_hmac('sha256', "{$t}.{$payload}", $secret);
    }

    private function sendWebhook(string $url, string $payload, string $signature)
    {
        return $this->call('POST', $url, [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => $signature,
            'CONTENT_TYPE'          => 'application/json',
        ], $payload);
    }

    // ── Ingestion ─────────────────────────────────────────────────────────────

    public function test_a_signed_event_is_stored_and_queued_not_processed_inline(): void
    {
        Queue::fake();

        $payload = $this->payload();

        $this->sendWebhook('/api/webhooks/stripe', $payload, $this->sign($payload, self::ACCOUNT_SECRET))
            ->assertOk()
            ->assertJsonPath('idempotent', false);

        $row = StripeWebhookEvent::where('stripe_event_id', 'evt_1')->firstOrFail();

        $this->assertTrue($row->signature_valid);
        $this->assertSame('account', $row->endpoint);
        $this->assertNull($row->processed_at);

        // Processing happens on the queue. A provider that does not get a fast
        // 200 retries, so a slow synchronous handler turns one event into five.
        Queue::assertPushed(ProcessPaymentWebhookJob::class);
    }

    public function test_a_duplicate_delivery_inserts_nothing_and_still_returns_200(): void
    {
        Queue::fake();

        $payload = $this->payload();

        $this->sendWebhook('/api/webhooks/stripe', $payload, $this->sign($payload, self::ACCOUNT_SECRET))->assertOk();
        $this->sendWebhook('/api/webhooks/stripe', $payload, $this->sign($payload, self::ACCOUNT_SECRET))
            ->assertOk()
            ->assertJsonPath('idempotent', true);

        $this->assertSame(1, StripeWebhookEvent::where('stripe_event_id', 'evt_1')->count());

        // Only the first delivery is worth processing.
        Queue::assertPushed(ProcessPaymentWebhookJob::class, 1);
    }

    public function test_an_invalid_signature_is_rejected_but_stored_for_inspection(): void
    {
        Queue::fake();

        $payload = $this->payload('evt_bad');

        $this->sendWebhook('/api/webhooks/stripe', $payload, $this->sign($payload, 'whsec_wrong'))
            ->assertStatus(400);

        $row = StripeWebhookEvent::where('stripe_event_id', 'evt_bad')->firstOrFail();

        $this->assertFalse($row->signature_valid);

        // Stored, never acted on.
        Queue::assertNothingPushed();
    }

    public function test_the_endpoint_refuses_everything_when_no_secret_is_configured(): void
    {
        config(['stripe.webhook_secret' => '']);

        $payload = $this->payload();

        // An unsigned acceptance path is worse than a 503.
        $this->sendWebhook('/api/webhooks/stripe', $payload, $this->sign($payload, self::ACCOUNT_SECRET))
            ->assertStatus(503);
    }

    // ── The two endpoints ─────────────────────────────────────────────────────

    public function test_a_connect_event_signed_with_the_connect_secret_is_accepted_there(): void
    {
        Queue::fake();

        $payload = $this->payload('evt_connect', 'account.updated');

        $this->sendWebhook('/api/webhooks/stripe/connect', $payload, $this->sign($payload, self::CONNECT_SECRET))
            ->assertOk();

        $this->assertSame(
            'connect',
            StripeWebhookEvent::where('stripe_event_id', 'evt_connect')->value('endpoint'),
        );
    }

    public function test_a_connect_event_is_rejected_by_the_platform_endpoint(): void
    {
        Queue::fake();

        $payload = $this->payload('evt_crossed', 'account.updated');

        // Verified against the platform secret, which it was not signed with.
        // If this passed, having two secrets would be decorative.
        $this->sendWebhook('/api/webhooks/stripe', $payload, $this->sign($payload, self::CONNECT_SECRET))
            ->assertStatus(400);

        $this->assertFalse(
            (bool) StripeWebhookEvent::where('stripe_event_id', 'evt_crossed')->value('signature_valid'),
        );
    }

    // ── Replay ────────────────────────────────────────────────────────────────

    public function test_an_event_that_failed_signature_verification_cannot_be_replayed(): void
    {
        Queue::fake();

        $payload = $this->payload('evt_bad');
        $this->sendWebhook('/api/webhooks/stripe', $payload, $this->sign($payload, 'whsec_wrong'))->assertStatus(400);

        $row = StripeWebhookEvent::where('stripe_event_id', 'evt_bad')->firstOrFail();

        $admin = \App\Models\User::factory()->create([
            'role_id' => \App\Models\Role::create([
                'name' => \App\Models\Role::SUPER_ADMIN, 'display_name' => 'Super Admin',
                'is_admin' => true, 'level' => 99,
            ])->id,
        ]);

        // Never act on a payload that failed authentication, however convenient.
        $this->actingAs($admin)
            ->post(route('admin.billing.webhooks.replay', $row))
            ->assertSessionHasErrors('replay');

        Queue::assertNothingPushed();
    }
}
