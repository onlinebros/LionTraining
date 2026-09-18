<?php

namespace Tests\Feature\Vendor;

use App\Models\CommissionLedger;
use App\Models\StripeWebhookEvent;
use App\Models\User;
use App\Models\VendorLead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Stripe\ApiRequestor;
use Tests\Support\FakeStripe;
use Tests\TestCase;

/**
 * The backstop for a vendor webhook that never arrived.
 *
 * Only Stripe's HTTP API is faked (Tests\Support\FakeStripe). Recovered events
 * run through the real ledger, job and processor, which is the point: a
 * recovered event must reach the same conclusion a delivered one would.
 */
class VendorEventSyncTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'whsec_vendor_test_secret';

    private FakeStripe $stripe;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeSecond();

        config()->set('vendors.vendors.plasmaguard.enabled', true);
        config()->set('vendors.vendors.plasmaguard.stripe.secret', 'rk_test_fake');
        config()->set('vendors.vendors.plasmaguard.webhook_secret', self::SECRET);
        config()->set('vendors.vendors.plasmaguard.commission.rate', 0.10);
        config()->set('vendors.vendors.plasmaguard.commission.basis', 'order_total');
        config()->set('vendors.vendors.plasmaguard.event_sync', [
            'enabled'        => true,
            'since'          => now()->subDay()->toIso8601String(),
            'lookback_hours' => 72,
            'grace_minutes'  => 10,
        ]);
        config()->set('stripe.webhook_tolerance', 300);

        $this->stripe = new FakeStripe;
        ApiRequestor::setHttpClient($this->stripe);

        User::factory()->create(['referral_code' => 'PARTNER1', 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);

        parent::tearDown();
    }

    public function test_a_payment_the_webhook_missed_is_recovered_and_confirms_the_order(): void
    {
        $lead = $this->placedOrder('pi_missed');
        $this->stripe->event('evt_missed', 'payment_intent.succeeded', $this->paymentIntent($lead), now()->subMinutes(30));

        $this->artisan('vendors:sync-events', ['vendor' => 'plasmaguard'])
            ->expectsOutputToContain('recovered 1 (evt_missed)')
            ->assertSuccessful();

        $lead->refresh();
        $this->assertSame(VendorLead::STATUS_CONVERTED, $lead->status);
        $this->assertSame(VendorLead::VIA_RECONCILIATION, $lead->confirmed_via);
        $this->assertSame(660000, $lead->amount_total);
        $this->assertSame(1, CommissionLedger::count());

        $row = StripeWebhookEvent::where('stripe_event_id', 'evt_missed')->firstOrFail();
        $this->assertSame('vendor:plasmaguard', $row->endpoint);
        $this->assertTrue($row->wasFetchedFromApi());
        $this->assertSame(StripeWebhookEvent::STATUS_PROCESSED, $row->status);
    }

    public function test_an_event_the_webhook_already_delivered_is_left_alone(): void
    {
        $lead   = $this->placedOrder('pi_delivered');
        $intent = $this->paymentIntent($lead);

        $this->sendVendorEvent('evt_delivered', 'payment_intent.succeeded', $intent)->assertOk();
        $this->stripe->event('evt_delivered', 'payment_intent.succeeded', $intent, now()->subMinutes(30));

        $this->artisan('vendors:sync-events')
            ->expectsOutputToContain('recovered 0')
            ->assertSuccessful();

        $this->assertSame(VendorLead::VIA_WEBHOOK, $lead->refresh()->confirmed_via);
        $this->assertSame(1, StripeWebhookEvent::count());
        $this->assertSame(1, CommissionLedger::count());
    }

    public function test_running_twice_confirms_the_order_once(): void
    {
        $lead = $this->placedOrder('pi_twice');
        $this->stripe->event('evt_twice', 'payment_intent.succeeded', $this->paymentIntent($lead), now()->subMinutes(30));

        $this->artisan('vendors:sync-events')->assertSuccessful();
        $this->artisan('vendors:sync-events')->assertSuccessful();

        $this->assertSame(1, StripeWebhookEvent::count());
        $this->assertSame(1, CommissionLedger::count());
    }

    public function test_nothing_before_the_configured_start_is_replayed(): void
    {
        // The $1 live test: paid before the webhook existed, and never meant to
        // become a sale with a commission attached.
        config()->set('vendors.vendors.plasmaguard.event_sync.since', now()->subHours(2)->toIso8601String());

        $lead = $this->placedOrder('pi_before');
        $this->stripe->event('evt_before', 'payment_intent.succeeded', $this->paymentIntent($lead), now()->subHours(5));

        $this->artisan('vendors:sync-events')->assertSuccessful();

        $this->assertSame(VendorLead::STATUS_HANDED_OFF, $lead->refresh()->status);
        $this->assertEquals(now()->subHours(2)->getTimestamp(), $this->stripe->paramsFor('get', '/v1/events')[0]['created']['gte']);
    }

    public function test_the_newest_events_are_left_for_the_webhook(): void
    {
        $lead = $this->placedOrder('pi_recent');
        $this->stripe->event('evt_recent', 'payment_intent.succeeded', $this->paymentIntent($lead), now()->subMinutes(2));

        $this->artisan('vendors:sync-events')->assertSuccessful();

        $this->assertSame(VendorLead::STATUS_HANDED_OFF, $lead->refresh()->status);
        $this->assertEquals(now()->subMinutes(10)->getTimestamp(), $this->stripe->paramsFor('get', '/v1/events')[0]['created']['lte']);
    }

    public function test_a_missed_refund_is_recovered_too(): void
    {
        $lead = $this->placedOrder('pi_refund');
        $this->sendVendorEvent('evt_sale', 'payment_intent.succeeded', $this->paymentIntent($lead))->assertOk();

        $this->stripe->event('evt_refund', 'charge.refunded', [
            'id'              => 'ch_refund',
            'object'          => 'charge',
            'payment_intent'  => 'pi_refund',
            'amount'          => 660000,
            'amount_refunded' => 660000,
        ], now()->subMinutes(30));

        $this->artisan('vendors:sync-events')->assertSuccessful();

        $this->assertSame(VendorLead::STATUS_REFUNDED, $lead->refresh()->status);
    }

    public function test_nothing_is_asked_of_stripe_until_a_start_is_configured(): void
    {
        config()->set('vendors.vendors.plasmaguard.event_sync.since', null);

        $this->artisan('vendors:sync-events')
            ->expectsOutputToContain('no start time is configured')
            ->assertSuccessful();

        $this->assertSame([], $this->stripe->requests);
    }

    public function test_a_dry_run_records_nothing(): void
    {
        $lead = $this->placedOrder('pi_dry');
        $this->stripe->event('evt_dry', 'payment_intent.succeeded', $this->paymentIntent($lead), now()->subMinutes(30));

        $this->artisan('vendors:sync-events', ['--dry-run' => true])
            ->expectsOutputToContain('would recover 1 (evt_dry)')
            ->assertSuccessful();

        $this->assertSame(0, StripeWebhookEvent::count());
        $this->assertSame(VendorLead::STATUS_HANDED_OFF, $lead->refresh()->status);
    }

    /** An order that reached payment on our checkout: captured, then handed off. */
    private function placedOrder(string $intentId): VendorLead
    {
        $this->post('/p/PARTNER1/plasmaguard/pro-in-duct', [
            'first_name' => 'Dana',
            'email'      => 'dana@example.com',
        ]);

        $lead = VendorLead::latest('id')->firstOrFail();

        $lead->forceFill([
            'status'                     => VendorLead::STATUS_HANDED_OFF,
            'handed_off_at'              => now()->subHour(),
            'provider_payment_intent_id' => $intentId,
        ])->save();

        return $lead;
    }

    /** @return array<string,mixed> A PaymentIntent as the vendor's event carries it. */
    private function paymentIntent(VendorLead $lead): array
    {
        return [
            'id'              => $lead->provider_payment_intent_id,
            'object'          => 'payment_intent',
            'status'          => 'succeeded',
            'amount'          => 660000,
            'amount_received' => 660000,
            'currency'        => 'usd',
            'latest_charge'   => 'ch_'.$lead->provider_payment_intent_id,
            'metadata'        => ['order_reference' => $lead->public_ref],
        ];
    }

    /** @param array<string,mixed> $object */
    private function sendVendorEvent(string $eventId, string $type, array $object): TestResponse
    {
        $payload = json_encode([
            'id'      => $eventId,
            'object'  => 'event',
            'type'    => $type,
            'created' => time(),
            'data'    => ['object' => $object],
        ], JSON_THROW_ON_ERROR);

        $timestamp = time();
        $sig = hash_hmac('sha256', $timestamp.'.'.$payload, self::SECRET);

        return $this->call('POST', '/api/webhooks/vendor/plasmaguard', [], [], [], [
            'CONTENT_TYPE'          => 'application/json',
            'HTTP_ACCEPT'           => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$sig}",
        ], $payload);
    }
}
