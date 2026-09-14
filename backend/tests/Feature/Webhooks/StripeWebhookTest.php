<?php

namespace Tests\Feature\Webhooks;

use App\Models\StripeWebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StripeWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'whsec_test_dummy_signing_secret';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('stripe.webhook_secret', self::SECRET);
        config()->set('stripe.webhook_tolerance', 300);
    }

    public function test_returns_503_when_secret_not_configured(): void
    {
        config()->set('stripe.webhook_secret', '');

        $this->postJson('/api/webhooks/stripe', ['id' => 'evt_x'])
            ->assertStatus(503)
            ->assertJsonPath('error', 'webhook_not_configured');
    }

    public function test_rejects_request_with_missing_signature(): void
    {
        $payload = json_encode(['id' => 'evt_no_sig', 'type' => 'charge.succeeded']);

        $this->call('POST', '/api/webhooks/stripe', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'  => 'application/json',
        ], (string) $payload)
            ->assertStatus(400);

        // Stored with signature_valid = false rather than dropped, so an
        // unexplained gap in the provider's delivery log is investigable from
        // this side. It is never dispatched for processing.
        $this->assertDatabaseHas('stripe_webhook_events', [
            'stripe_event_id' => 'evt_no_sig',
            'signature_valid' => false,
        ]);
    }

    public function test_rejects_request_with_bad_signature(): void
    {
        $payload = json_encode(['id' => 'evt_bad_sig', 'type' => 'charge.succeeded']);
        $timestamp = time();
        $header = "t={$timestamp},v1=deadbeef";

        $this->signedPost($payload, $header)
            ->assertStatus(400)
            ->assertJsonPath('error', 'signature_mismatch');

        $this->assertDatabaseHas('stripe_webhook_events', [
            'stripe_event_id' => 'evt_bad_sig',
            'signature_valid' => false,
        ]);
    }

    public function test_rejects_timestamp_outside_tolerance(): void
    {
        $payload = json_encode(['id' => 'evt_stale', 'type' => 'charge.succeeded']);
        $stale = time() - 3600; // one hour old
        $sig = hash_hmac('sha256', $stale.'.'.$payload, self::SECRET);
        $header = "t={$stale},v1={$sig}";

        $this->signedPost($payload, $header)
            ->assertStatus(400)
            ->assertJsonPath('error', 'timestamp_outside_tolerance');

        $this->assertDatabaseHas('stripe_webhook_events', [
            'stripe_event_id' => 'evt_stale',
            'signature_valid' => false,
        ]);
    }

    public function test_accepts_valid_signed_request_and_records_event(): void
    {
        $payload = json_encode([
            'id'          => 'evt_test_1',
            'type'        => 'invoice.paid',
            'api_version' => '2024-04-10',
            'created'     => time() - 5,
            'livemode'    => false,
            'data'        => ['object' => ['id' => 'in_123', 'amount_paid' => 9900]],
        ]);

        $response = $this->signedPost($payload, $this->sign($payload));

        $response->assertOk()
            ->assertJsonPath('received', true)
            ->assertJsonPath('idempotent', false)
            ->assertJsonPath('event_id', 'evt_test_1');

        $row = StripeWebhookEvent::where('stripe_event_id', 'evt_test_1')->firstOrFail();
        $this->assertSame('invoice.paid', $row->type);
        $this->assertSame('2024-04-10', $row->api_version);
        $this->assertSame((string) $payload, $row->payload);
        $this->assertNotNull($row->received_at);
        $this->assertTrue($row->signature_valid);

        // Ingestion dispatches ProcessPaymentWebhookJob. The test suite runs the
        // queue synchronously, so the row is already processed here; in
        // production it is 'received' until a worker picks it up.
        $this->assertSame(StripeWebhookEvent::STATUS_PROCESSED, $row->status);
    }

    public function test_duplicate_event_id_is_short_circuited(): void
    {
        $payload = json_encode([
            'id'      => 'evt_dup',
            'type'    => 'charge.succeeded',
            'created' => time() - 2,
        ]);

        $this->signedPost($payload, $this->sign($payload))->assertOk();
        $this->assertDatabaseCount('stripe_webhook_events', 1);

        // Re-deliver the SAME event (same id, freshly signed so signature passes).
        $response = $this->signedPost($payload, $this->sign($payload));

        $response->assertOk()
            ->assertJsonPath('received', true)
            ->assertJsonPath('idempotent', true)
            ->assertJsonPath('event_id', 'evt_dup');

        // Still exactly one row — duplicate INSERT was caught, not retried.
        $this->assertDatabaseCount('stripe_webhook_events', 1);
    }

    public function test_payload_without_id_is_rejected(): void
    {
        $payload = json_encode(['type' => 'charge.succeeded']);
        $this->signedPost($payload, $this->sign($payload))
            ->assertStatus(400)
            ->assertJsonPath('error', 'invalid_payload');
    }

    public function test_multiple_v1_signatures_any_match_accepted(): void
    {
        $payload   = json_encode(['id' => 'evt_multi', 'type' => 'charge.succeeded', 'created' => time()]);
        $timestamp = time();
        $good      = hash_hmac('sha256', $timestamp.'.'.$payload, self::SECRET);
        $header    = "t={$timestamp},v1=deadbeef,v1={$good}";

        $this->signedPost($payload, $header)->assertOk();
        $this->assertDatabaseHas('stripe_webhook_events', ['stripe_event_id' => 'evt_multi']);
    }

    private function sign(string $payload, ?int $timestamp = null): string
    {
        $timestamp ??= time();
        $sig = hash_hmac('sha256', $timestamp.'.'.$payload, self::SECRET);
        return "t={$timestamp},v1={$sig}";
    }

    private function signedPost(string $rawPayload, string $signatureHeader): \Illuminate\Testing\TestResponse
    {
        return $this->call('POST', '/api/webhooks/stripe', [], [], [], [
            'CONTENT_TYPE'          => 'application/json',
            'HTTP_ACCEPT'           => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => $signatureHeader,
        ], $rawPayload);
    }
}
