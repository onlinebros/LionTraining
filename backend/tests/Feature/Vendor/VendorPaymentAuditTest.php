<?php

namespace Tests\Feature\Vendor;

use App\Models\CommissionLedger;
use App\Models\Role;
use App\Models\User;
use App\Models\VendorLead;
use App\Models\VendorPaymentAudit;
use App\Services\Vendor\VendorPaymentAuditor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Stripe\ApiRequestor;
use Tests\Support\FakeStripe;
use Tests\TestCase;

/**
 * The audit of what the vendor's Stripe says became of each order.
 *
 * The intents are shaped on what PlasmaGuard's live account actually returned
 * for the four stuck orders of 6–7 October 2026: two Affirm cancellations, one
 * expired Affirm redirect and one bank decline.
 */
class VendorPaymentAuditTest extends TestCase
{
    use RefreshDatabase;

    private FakeStripe $stripe;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeSecond();

        config()->set('vendors.vendors.plasmaguard.stripe.secret', 'rk_test_fake');

        $this->stripe = new FakeStripe;
        ApiRequestor::setHttpClient($this->stripe);
        Cache::flush();
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);

        parent::tearDown();
    }

    public function test_a_bank_decline_is_recorded_with_stripes_reason(): void
    {
        $lead = $this->order('pi_declined');
        $this->intent('pi_declined', 'requires_payment_method',
            error: ['type' => 'card_error', 'code' => 'payment_intent_payment_attempt_failed', 'decline_code' => 'generic_decline', 'message' => 'The payment failed.'],
            charge: ['type' => 'card', 'outcome' => 'issuer_declined', 'reason' => 'payment_intent_generic_payment_failed', 'seller_message' => 'The bank did not return any further details with this decline.']);

        $this->artisan('vendors:audit-payments', ['vendor' => 'plasmaguard'])
            ->expectsOutputToContain('1 order(s) checked — declined 1')
            ->assertSuccessful();

        $audit = $lead->paymentAudit()->firstOrFail();
        $this->assertSame(VendorPaymentAudit::VERDICT_DECLINED, $audit->verdict);
        $this->assertSame('generic_decline', $audit->decline_code);
        $this->assertSame('card', $audit->payment_method_type);
        $this->assertSame('The bank did not return any further details with this decline.', $audit->reason());

        // Read-only: the order is exactly as it was.
        $this->assertSame(VendorLead::STATUS_HANDED_OFF, $lead->refresh()->status);
    }

    public function test_a_cancelled_or_expired_affirm_checkout_is_abandoned_not_declined(): void
    {
        $cancelled = $this->order('pi_affirm_cancel');
        $this->intent('pi_affirm_cancel', 'requires_payment_method',
            error: ['type' => 'card_error', 'code' => 'payment_intent_payment_attempt_failed', 'decline_code' => 'affirm_checkout_canceled', 'message' => 'The payment failed.'],
            charge: ['type' => 'affirm', 'outcome' => 'issuer_declined', 'reason' => 'affirm_checkout_canceled', 'seller_message' => 'The user cancelled their Affirm checkout session.']);

        $expired = $this->order('pi_affirm_expired');
        $this->intent('pi_affirm_expired', 'requires_payment_method',
            error: ['type' => 'card_error', 'code' => 'payment_intent_payment_attempt_expired', 'message' => 'The Affirm redirect URL of this PaymentIntent has expired.'],
            charge: ['type' => 'affirm', 'outcome' => 'issuer_declined', 'reason' => 'customer_request_expired', 'seller_message' => 'The customer did not complete the payment in time and the session expired.']);

        $this->artisan('vendors:audit-payments')->assertSuccessful();

        $this->assertSame(VendorPaymentAudit::VERDICT_ABANDONED, $cancelled->paymentAudit()->value('verdict'));
        $this->assertSame(VendorPaymentAudit::VERDICT_ABANDONED, $expired->paymentAudit()->value('verdict'));
        $this->assertSame('Affirm', $expired->paymentAudit()->firstOrFail()->methodLabel());
    }

    public function test_a_payment_stripe_took_but_we_never_recorded_is_flagged_and_not_confirmed(): void
    {
        $lead = $this->order('pi_lost_webhook');
        $this->intent('pi_lost_webhook', 'succeeded', charge: ['type' => 'card', 'outcome' => 'authorized', 'seller_message' => 'Payment complete.']);

        $this->artisan('vendors:audit-payments')->assertSuccessful();

        $this->assertSame(VendorPaymentAudit::VERDICT_PAID_NOT_RECORDED, $lead->paymentAudit()->value('verdict'));

        // Confirming a sale belongs to the webhook and the event sync alone.
        $this->assertSame(VendorLead::STATUS_HANDED_OFF, $lead->refresh()->status);
        $this->assertSame(0, CommissionLedger::count());
    }

    public function test_a_confirmed_order_is_checked_against_stripe_both_ways(): void
    {
        $paid = $this->order('pi_paid', VendorLead::STATUS_CONVERTED);
        $this->intent('pi_paid', 'succeeded');

        $phantom = $this->order('pi_phantom', VendorLead::STATUS_CONVERTED);
        $this->intent('pi_phantom', 'requires_payment_method');

        $this->artisan('vendors:audit-payments')->assertSuccessful();

        $this->assertSame(VendorPaymentAudit::VERDICT_PAID, $paid->paymentAudit()->value('verdict'));
        $this->assertSame(VendorPaymentAudit::VERDICT_RECORDED_NOT_PAID, $phantom->paymentAudit()->value('verdict'));
    }

    public function test_an_intent_nobody_tried_to_pay_is_never_submitted(): void
    {
        $lead = $this->order('pi_idle');
        $this->intent('pi_idle', 'requires_payment_method');

        $this->artisan('vendors:audit-payments')->assertSuccessful();

        $this->assertSame(VendorPaymentAudit::VERDICT_NOT_SUBMITTED, $lead->paymentAudit()->value('verdict'));
    }

    public function test_every_attempt_is_kept_and_outlives_stripes_event_log(): void
    {
        $lead = $this->order('pi_retry');
        $this->intent('pi_retry', 'requires_payment_method',
            error: ['type' => 'card_error', 'code' => 'card_declined', 'decline_code' => 'insufficient_funds', 'message' => 'Your card has insufficient funds.']);

        $this->stripe->event('evt_1', 'payment_intent.payment_failed', [
            'id' => 'pi_retry', 'object' => 'payment_intent',
            'last_payment_error' => ['code' => 'payment_intent_payment_attempt_failed', 'decline_code' => 'affirm_checkout_canceled', 'payment_method' => ['type' => 'affirm']],
        ], now()->subHours(3));
        $this->stripe->event('evt_2', 'payment_intent.payment_failed', [
            'id' => 'pi_retry', 'object' => 'payment_intent',
            'last_payment_error' => ['code' => 'card_declined', 'decline_code' => 'insufficient_funds', 'payment_method' => ['type' => 'card']],
        ], now()->subHours(1));
        // Someone else's sale on the vendor's account: not ours to record.
        $this->stripe->event('evt_theirs', 'payment_intent.succeeded', ['id' => 'pi_not_ours', 'object' => 'payment_intent'], now()->subHours(2));

        $this->artisan('vendors:audit-payments')->assertSuccessful();

        $attempts = $lead->paymentAudit()->firstOrFail()->attempts;
        $this->assertSame(['evt_1', 'evt_2'], array_column($attempts, 'event'));
        $this->assertSame(['affirm', 'card'], array_column($attempts, 'method'));

        // Stripe forgets after 30 days; we do not.
        $this->stripe->events = [];
        $this->artisan('vendors:audit-payments')->assertSuccessful();

        $this->assertCount(2, $lead->paymentAudit()->firstOrFail()->attempts);
    }

    public function test_a_failed_read_keeps_the_last_good_verdict(): void
    {
        $lead = $this->order('pi_flaky');
        $this->intent('pi_flaky', 'requires_payment_method',
            error: ['type' => 'card_error', 'code' => 'card_declined', 'decline_code' => 'do_not_honor', 'message' => 'Declined.']);

        $this->artisan('vendors:audit-payments')->assertSuccessful();

        unset($this->stripe->paymentIntents['pi_flaky']);
        $this->artisan('vendors:audit-payments')->assertSuccessful();

        $audit = $lead->paymentAudit()->firstOrFail();
        $this->assertSame(VendorPaymentAudit::VERDICT_DECLINED, $audit->verdict);
        $this->assertSame('do_not_honor', $audit->decline_code);
        $this->assertStringContainsString('No such payment_intent', (string) $audit->check_error);
    }

    public function test_an_order_with_no_reading_yet_is_unreadable(): void
    {
        $lead = $this->order('pi_missing');

        $this->artisan('vendors:audit-payments')->assertSuccessful();

        $this->assertSame(VendorPaymentAudit::VERDICT_UNREADABLE, $lead->paymentAudit()->value('verdict'));
    }

    public function test_the_access_probe_names_what_the_key_may_and_may_not_read(): void
    {
        $this->stripe->denied = ['/v1/charges', '/v1/balance', '/v1/payouts'];
        $this->stripe->webhookEndpoints = [
            ['id' => 'we_ours', 'object' => 'webhook_endpoint', 'url' => 'https://app.q3.life/api/webhooks/vendor/plasmaguard', 'status' => 'enabled', 'enabled_events' => ['payment_intent.succeeded']],
            ['id' => 'we_theirs', 'object' => 'webhook_endpoint', 'url' => 'https://plasmaguard.example/hook', 'status' => 'enabled', 'enabled_events' => ['*']],
        ];

        $access = app(VendorPaymentAuditor::class)->access('plasmaguard');

        $this->assertSame('allowed', $access['probes']['paymentIntents']['result']);
        $this->assertSame('allowed', $access['probes']['events']['result']);
        $this->assertSame('denied', $access['probes']['charges']['result']);
        $this->assertSame('Charges and Refunds Read (charge_read)', $access['probes']['charges']['detail']);
        $this->assertSame('denied', $access['probes']['balance']['result']);
        $this->assertSame(['we_ours'], array_column($access['webhooks'], 'id'));
    }

    public function test_the_admin_page_shows_the_verdicts_and_refreshes(): void
    {
        $role  = Role::firstOrCreate(['name' => Role::SUPER_ADMIN], ['display_name' => 'Super Admin', 'is_admin' => true, 'level' => 100]);
        $admin = User::factory()->create(['role_id' => $role->id, 'is_active' => true]);

        $lead = $this->order('pi_page');
        $this->intent('pi_page', 'requires_payment_method',
            error: ['type' => 'card_error', 'code' => 'payment_intent_payment_attempt_failed', 'decline_code' => 'affirm_checkout_canceled', 'message' => 'The payment failed.'],
            charge: ['type' => 'affirm', 'outcome' => 'issuer_declined', 'reason' => 'affirm_checkout_canceled', 'seller_message' => 'The user cancelled their Affirm checkout session.']);

        $this->actingAs($admin)
            ->post(route('admin.vendor-leads.payment-audit.refresh'), ['vendor' => 'plasmaguard'])
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->actingAs($admin)->get(route('admin.vendor-leads.payment-audit', ['verdict' => 'attention']))
            ->assertOk()
            ->assertSee($lead->public_ref)
            ->assertSee('Abandoned')
            ->assertSee('The user cancelled their Affirm checkout session.')
            ->assertSee('What our key can see');

        $this->actingAs($admin)->get(route('admin.vendor-leads.show', $lead))
            ->assertOk()
            ->assertSee('Abandoned');
    }

    public function test_members_cannot_see_the_audit(): void
    {
        $this->actingAs(User::factory()->create(['is_active' => true]))
            ->get(route('admin.vendor-leads.payment-audit'))
            ->assertForbidden();
    }

    private function order(string $intentId, string $status = VendorLead::STATUS_HANDED_OFF): VendorLead
    {
        return VendorLead::create([
            'public_ref'                 => 'QLV-'.strtoupper(Str::random(10)),
            'vendor'                     => 'plasmaguard',
            'product_key'                => 'pro-in-duct',
            'first_name'                 => 'Dana',
            'email'                      => 'dana@example.com',
            'quantity'                   => 1,
            'status'                     => $status,
            'handed_off_at'              => now()->subDay(),
            'converted_at'               => $status === VendorLead::STATUS_CONVERTED ? now() : null,
            'provider_payment_intent_id' => $intentId,
            'amount_total'               => 604672,
            'currency'                   => 'USD',
        ]);
    }

    /**
     * @param  array<string,mixed>|null  $error
     * @param  array{type?:string, outcome?:string, reason?:string, seller_message?:string}|null  $charge
     */
    private function intent(string $id, string $status, ?array $error = null, ?array $charge = null): void
    {
        $this->stripe->paymentIntents[$id] = [
            'id'                 => $id,
            'object'             => 'payment_intent',
            'status'             => $status,
            'amount'             => 604672,
            'created'            => now()->subDay()->getTimestamp(),
            'last_payment_error' => $error,
            'next_action'        => null,
            'latest_charge'      => $charge === null ? null : [
                'id'                     => 'ch_'.$id,
                'object'                 => 'charge',
                'payment_method_details' => ['type' => $charge['type'] ?? 'card'],
                'outcome'                => [
                    'type'           => $charge['outcome'] ?? 'authorized',
                    'reason'         => $charge['reason'] ?? null,
                    'seller_message' => $charge['seller_message'] ?? null,
                    'risk_level'     => 'normal',
                ],
            ],
        ];
    }
}
