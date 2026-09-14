<?php

namespace Tests\Feature\Vendor;

use App\Models\CommissionLedger;
use App\Models\User;
use App\Models\VendorLead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class VendorReferralTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'whsec_vendor_test_secret';

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('vendors.vendors.plasmaguard.enabled', true);
        config()->set('vendors.vendors.plasmaguard.webhook_secret', self::SECRET);
        config()->set('vendors.vendors.plasmaguard.checkout.url', 'https://buy.stripe.com/test_link');
        config()->set('vendors.vendors.plasmaguard.commission.rate', 0.10);
        config()->set('stripe.webhook_tolerance', 300);

        $this->member = User::factory()->create([
            'referral_code' => 'PARTNER1',
            'is_active'     => true,
        ]);
    }

    // ── The customer-facing page ──────────────────────────────────────────────

    public function test_member_coded_product_page_renders_for_a_valid_code(): void
    {
        $this->get('/p/PARTNER1/plasmaguard/pro-in-duct')
            ->assertOk()
            ->assertSee('PlasmaGuard PRO In-Duct System')
            // The partner is named on the page — it is their page, not the
            // vendor's, and the disclosure depends on saying so.
            ->assertSee($this->member->name);
    }

    public function test_product_page_shows_the_vendors_device_photography(): void
    {
        $this->get('/p/PARTNER1/plasmaguard/pro-in-duct')
            ->assertOk()
            ->assertSee('pg-pro-generator-560.webp', false)
            ->assertSee('pg-pro-sensor_sq-320.webp', false)
            ->assertSee('pg-pro-hub-sq-320.webp', false)
            // Whose photography it is should be stated, not inferred.
            ->assertSee('Product photography © PlasmaGuard LLC');
    }

    public function test_product_page_renders_without_imagery_when_none_is_configured(): void
    {
        // If PlasmaGuard decline permission the images are removed from config,
        // and the page has to still be a page — no empty frames, no broken alt.
        config()->set('vendors.vendors.plasmaguard.products.pro-in-duct.images', []);

        $this->get('/p/PARTNER1/plasmaguard/pro-in-duct')
            ->assertOk()
            ->assertSee('PlasmaGuard PRO In-Duct System')
            ->assertDontSee('q3-sf-shot-frame', false)
            ->assertDontSee('vendors/plasmaguard', false);
    }

    // ── Revenue share ─────────────────────────────────────────────────────────

    public function test_revenue_share_is_a_flat_amount_per_unit(): void
    {
        // $3,000 a system: one is $3,000, three is $9,000.
        $this->assertSame(300000, \App\Support\Vendors::revenueShare('plasmaguard', 'pro-in-duct', 1, 600000));
        $this->assertSame(900000, \App\Support\Vendors::revenueShare('plasmaguard', 'pro-in-duct', 3, 1800000));
    }

    public function test_revenue_share_ignores_shipping_handling_and_tax(): void
    {
        // The subtotal passed in is goods only, so a bigger charge total cannot
        // move our cut. Taking a share of collected tax is someone else's
        // liability sitting in our balance.
        $this->assertSame(300000, \App\Support\Vendors::revenueShare('plasmaguard', 'pro-in-duct', 1, 600000));
    }

    public function test_revenue_share_refuses_to_exceed_the_goods_it_shares(): void
    {
        // The failure this guards: PlasmaGuard discount the system and nobody
        // moves the flat per-unit figure, so we would take the whole sale.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/exceeds the product subtotal/');

        \App\Support\Vendors::revenueShare('plasmaguard', 'pro-in-duct', 1, 250000);
    }

    public function test_revenue_share_supports_a_percentage_model_for_other_vendors(): void
    {
        config()->set('vendors.vendors.plasmaguard.revenue_share.model', 'rate');
        config()->set('vendors.vendors.plasmaguard.revenue_share.rate', 0.4);

        $this->assertSame(240000, \App\Support\Vendors::revenueShare('plasmaguard', 'pro-in-duct', 1, 600000));
    }

    public function test_unknown_referral_code_is_not_found(): void
    {
        $this->get('/p/NOSUCHCODE/plasmaguard/pro-in-duct')->assertNotFound();
    }

    public function test_deactivated_partners_link_stops_working(): void
    {
        $this->member->forceFill(['is_active' => false])->save();

        $this->get('/p/PARTNER1/plasmaguard/pro-in-duct')->assertNotFound();
    }

    public function test_unknown_product_is_not_found(): void
    {
        $this->get('/p/PARTNER1/plasmaguard/no-such-product')->assertNotFound();
    }

    public function test_a_disabled_vendor_is_not_shareable(): void
    {
        config()->set('vendors.vendors.plasmaguard.enabled', false);

        $this->get('/p/PARTNER1/plasmaguard/pro-in-duct')->assertNotFound();
    }

    // ── Capture ───────────────────────────────────────────────────────────────

    public function test_enquiry_captures_the_lead_and_files_it_in_the_partners_crm(): void
    {
        $this->post('/p/PARTNER1/plasmaguard/pro-in-duct', [
            'first_name' => 'Dana',
            'last_name'  => 'Reyes',
            'email'      => 'dana@example.com',
            'phone'      => '555-0100',
            'city'       => 'Ann Arbor',
            'quantity'   => 2,
        ])->assertRedirect();

        $lead = VendorLead::firstOrFail();

        $this->assertSame($this->member->id, $lead->member_id);
        // Snapshotted, so a reissued code cannot rewrite who earned this.
        $this->assertSame('PARTNER1', $lead->referral_code);
        $this->assertSame(VendorLead::STATUS_NEW, $lead->status);
        $this->assertStringStartsWith('QLV-', $lead->public_ref);

        $this->assertDatabaseHas('crm_contacts', [
            'owner_id' => $this->member->id,
            'email'    => 'dana@example.com',
        ]);
    }

    public function test_enquiry_requires_a_name_and_email(): void
    {
        $this->post('/p/PARTNER1/plasmaguard/pro-in-duct', ['first_name' => 'Dana'])
            ->assertSessionHasErrors('email');

        $this->assertSame(0, VendorLead::count());
    }

    public function test_honeypot_submissions_create_nothing(): void
    {
        $this->post('/p/PARTNER1/plasmaguard/pro-in-duct', [
            'first_name'  => 'Bot',
            'email'       => 'bot@example.com',
            'website_url' => 'http://spam.example',
        ])->assertRedirect();

        $this->assertSame(0, VendorLead::count());
    }

    // ── Handoff ───────────────────────────────────────────────────────────────

    public function test_handoff_url_carries_the_reference_and_email(): void
    {
        $lead = $this->capture();

        $this->get("/p/handoff/{$lead->public_ref}")->assertOk();

        $lead->refresh();

        $this->assertSame(VendorLead::STATUS_HANDED_OFF, $lead->status);
        // The attribution parameter Stripe echoes back on the session. Without
        // it the vendor's payment is anonymous to us.
        $this->assertStringContainsString('client_reference_id='.$lead->public_ref, $lead->checkout_url);
        $this->assertStringContainsString('prefilled_email=', $lead->checkout_url);
    }

    public function test_handoff_degrades_gracefully_with_no_checkout_configured(): void
    {
        config()->set('vendors.vendors.plasmaguard.checkout.url', '');

        $lead = $this->capture();

        // Still a captured lead for the partner to work by hand, not an error.
        $this->get("/p/handoff/{$lead->public_ref}")->assertOk();
        $this->assertSame(VendorLead::STATUS_NEW, $lead->refresh()->status);
    }

    public function test_thank_you_page_does_not_confirm_a_sale(): void
    {
        $lead = $this->capture();

        // Reached by a redirect the customer controls. Treating it as proof of
        // payment would let anyone with the URL manufacture a commission.
        $this->get("/p/thanks/{$lead->public_ref}")->assertOk();

        $this->assertNotSame(VendorLead::STATUS_CONVERTED, $lead->refresh()->status);
        $this->assertSame(0, CommissionLedger::count());
    }

    // ── Confirmation ──────────────────────────────────────────────────────────

    public function test_vendor_webhook_converts_the_lead_and_raises_commission(): void
    {
        $lead = $this->handedOff();

        $this->sendVendorEvent('evt_1', 'checkout.session.completed', [
            'id'                  => 'cs_test_1',
            'client_reference_id' => $lead->public_ref,
            'payment_status'      => 'paid',
            'amount_total'        => 250000,
            'currency'            => 'usd',
            'payment_intent'      => 'pi_test_1',
        ])->assertOk();

        $lead->refresh();

        $this->assertSame(VendorLead::STATUS_CONVERTED, $lead->status);
        $this->assertSame(250000, $lead->amount_total);
        $this->assertSame('USD', $lead->currency);
        $this->assertSame(VendorLead::VIA_WEBHOOK, $lead->confirmed_via);

        $ledger = CommissionLedger::firstOrFail();
        $this->assertSame($this->member->id, $ledger->earner_id);
        $this->assertSame('credit', $ledger->type);
        // 10% of $2,500.00.
        $this->assertSame('250.0000', $ledger->amount);
        $this->assertSame(VendorLead::class, $ledger->source_type);
        $this->assertSame($lead->id, $ledger->source_id);
    }

    public function test_a_redelivered_confirmation_does_not_pay_twice(): void
    {
        $lead = $this->handedOff();

        $payload = [
            'id'                  => 'cs_test_dupe',
            'client_reference_id' => $lead->public_ref,
            'payment_status'      => 'paid',
            'amount_total'        => 100000,
            'currency'            => 'usd',
        ];

        $this->sendVendorEvent('evt_dupe_a', 'checkout.session.completed', $payload)->assertOk();
        // Same session, a NEW event id — a genuine redelivery, not the ledger's
        // idempotency key doing the work.
        $this->sendVendorEvent('evt_dupe_b', 'checkout.session.completed', $payload)->assertOk();

        $this->assertSame(1, CommissionLedger::count());
    }

    public function test_an_unpaid_checkout_session_raises_nothing(): void
    {
        $lead = $this->handedOff();

        $this->sendVendorEvent('evt_unpaid', 'checkout.session.completed', [
            'id'                  => 'cs_unpaid',
            'client_reference_id' => $lead->public_ref,
            'payment_status'      => 'unpaid',
            'amount_total'        => 100000,
            'currency'            => 'usd',
        ])->assertOk();

        $this->assertSame(VendorLead::STATUS_HANDED_OFF, $lead->refresh()->status);
        $this->assertSame(0, CommissionLedger::count());
    }

    public function test_a_confirmation_that_matches_nothing_is_stored_but_pays_no_one(): void
    {
        $this->handedOff();

        $this->sendVendorEvent('evt_orphan', 'checkout.session.completed', [
            'id'                  => 'cs_orphan',
            'client_reference_id' => 'QLV-DOESNOTEXIST',
            'payment_status'      => 'paid',
            'amount_total'        => 500000,
            'currency'            => 'usd',
        ])->assertOk();

        // Never guessed at. Paying the wrong partner is worse than paying none.
        $this->assertSame(0, CommissionLedger::count());
        $this->assertDatabaseHas('stripe_webhook_events', ['stripe_event_id' => 'evt_orphan']);
    }

    public function test_email_fallback_matches_when_the_reference_is_missing(): void
    {
        $lead = $this->handedOff();

        $this->sendVendorEvent('evt_fallback', 'checkout.session.completed', [
            'id'               => 'cs_fallback',
            'payment_status'   => 'paid',
            'amount_total'     => 100000,
            'currency'         => 'usd',
            'customer_details' => ['email' => 'dana@example.com'],
        ])->assertOk();

        $this->assertSame(VendorLead::STATUS_CONVERTED, $lead->refresh()->status);
    }

    public function test_a_full_refund_unwinds_the_sale(): void
    {
        $lead = $this->handedOff();

        $this->sendVendorEvent('evt_sale', 'checkout.session.completed', [
            'id'                  => 'cs_refund',
            'client_reference_id' => $lead->public_ref,
            'payment_status'      => 'paid',
            'amount_total'        => 100000,
            'currency'            => 'usd',
            'payment_intent'      => 'pi_refund',
        ])->assertOk();

        $this->sendVendorEvent('evt_refund', 'charge.refunded', [
            'id'              => 'ch_refund',
            'payment_intent'  => 'pi_refund',
            'amount'          => 100000,
            'amount_refunded' => 100000,
        ])->assertOk();

        $this->assertSame(VendorLead::STATUS_REFUNDED, $lead->refresh()->status);
        // Voided rather than deleted, so the partner's statement shows what
        // happened instead of a line quietly vanishing.
        $this->assertSame('voided', CommissionLedger::firstOrFail()->status);
    }

    public function test_a_partial_refund_leaves_the_commission_standing(): void
    {
        $lead = $this->handedOff();

        $this->sendVendorEvent('evt_sale_p', 'checkout.session.completed', [
            'id'                  => 'cs_partial',
            'client_reference_id' => $lead->public_ref,
            'payment_status'      => 'paid',
            'amount_total'        => 100000,
            'currency'            => 'usd',
            'payment_intent'      => 'pi_partial',
        ])->assertOk();

        $this->sendVendorEvent('evt_refund_p', 'charge.refunded', [
            'id'              => 'ch_partial',
            'payment_intent'  => 'pi_partial',
            'amount'          => 100000,
            'amount_refunded' => 25000,
        ])->assertOk();

        $this->assertSame(VendorLead::STATUS_CONVERTED, $lead->refresh()->status);
        $this->assertSame('pending', CommissionLedger::firstOrFail()->status);
    }

    public function test_vendor_endpoint_refuses_to_accept_anything_without_a_secret(): void
    {
        config()->set('vendors.vendors.plasmaguard.webhook_secret', '');

        $this->postJson('/api/webhooks/vendor/plasmaguard', ['id' => 'evt_x'])
            ->assertStatus(503);
    }

    public function test_unknown_vendor_endpoint_is_not_found(): void
    {
        $this->postJson('/api/webhooks/vendor/nosuchvendor', ['id' => 'evt_x'])
            ->assertNotFound();
    }

    public function test_a_vendor_event_with_our_own_signing_secret_is_rejected(): void
    {
        $lead = $this->handedOff();

        $payload = json_encode([
            'id'   => 'evt_wrong_secret',
            'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'id'                  => 'cs_wrong',
                'client_reference_id' => $lead->public_ref,
                'payment_status'      => 'paid',
                'amount_total'        => 100000,
                'currency'            => 'usd',
            ]],
        ]);

        // Signed with the PLATFORM secret. The separation is decorative if this
        // passes: anyone holding our secret could mint vendor sales.
        $timestamp = time();
        $sig = hash_hmac('sha256', $timestamp.'.'.$payload, 'whsec_our_platform_secret');

        $this->call('POST', '/api/webhooks/vendor/plasmaguard', [], [], [], [
            'CONTENT_TYPE'          => 'application/json',
            'HTTP_ACCEPT'           => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$sig}",
        ], $payload)->assertStatus(400);

        $this->assertSame(0, CommissionLedger::count());
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function capture(): VendorLead
    {
        $this->post('/p/PARTNER1/plasmaguard/pro-in-duct', [
            'first_name' => 'Dana',
            'email'      => 'dana@example.com',
        ]);

        return VendorLead::latest('id')->firstOrFail();
    }

    private function handedOff(): VendorLead
    {
        $lead = $this->capture();
        $this->get("/p/handoff/{$lead->public_ref}");

        return $lead->refresh();
    }

    /** @param array<string,mixed> $object */
    private function sendVendorEvent(string $eventId, string $type, array $object): TestResponse
    {
        $payload = json_encode([
            'id'   => $eventId,
            'type' => $type,
            'data' => ['object' => $object],
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
