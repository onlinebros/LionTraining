<?php

namespace Tests\Feature\Vendor;

use App\Models\User;
use App\Models\VendorLead;
use App\Services\Vendor\Shipping\ShippingQuote;
use App\Services\Vendor\VendorOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The order arithmetic, isolated from Stripe.
 *
 * `automatic_tax` is off throughout: tax is the vendor's calculation against
 * their own registrations, and a unit test that reaches across the network to
 * assert someone else's tax rate is testing Michigan, not us.
 */
class VendorOrderTest extends TestCase
{
    use RefreshDatabase;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('vendors.vendors.plasmaguard.enabled', true);
        config()->set('vendors.vendors.plasmaguard.stripe.automatic_tax', false);
        config()->set('vendors.vendors.plasmaguard.products.pro-in-duct.price', 6000);
        config()->set('vendors.vendors.plasmaguard.pricing.shipping.mode', 'flat');
        config()->set('vendors.vendors.plasmaguard.pricing.shipping.flat_amount', 4200);
        config()->set('vendors.vendors.plasmaguard.pricing.handling.amount', 1200);
        config()->set('vendors.vendors.plasmaguard.pricing.handling.basis', 'order');

        /*
         * Pin the rater. Otherwise the container picks FedEx once its
         * credentials are configured, and this suite starts failing whenever
         * someone else's sandbox is down — which is how a test suite becomes
         * something people ignore.
         */
        $this->app->bind(\App\Services\Vendor\Shipping\ShippingRater::class,
            fn () => new \App\Services\Vendor\Shipping\FlatShippingRater('plasmaguard'));

        $this->member = User::factory()->create(['referral_code' => 'PARTNER1', 'is_active' => true]);
    }

    public function test_a_single_unit_quote_adds_up(): void
    {
        $q = app(VendorOrderService::class)->quote($this->lead(1));

        $this->assertSame(600000, $q['subtotal']);
        $this->assertSame(4200,   $q['shipping']);
        $this->assertSame(1200,   $q['handling']);
        $this->assertSame(605400, $q['total']);
        $this->assertSame(300000, $q['our_share']);
        $this->assertSame(1,      $q['cartons']);
    }

    public function test_quantity_multiplies_cartons_shipping_and_our_share(): void
    {
        $q = app(VendorOrderService::class)->quote($this->lead(3));

        // One unit per carton — three systems is three parcels, not one big box.
        $this->assertSame(3,       $q['cartons']);
        $this->assertSame(12600,   $q['shipping']);   // 3 x $42
        $this->assertSame(1800000, $q['subtotal']);
        $this->assertSame(900000,  $q['our_share']);  // 3 x $3,000 flat
        // Handling is per ORDER, so it does not multiply.
        $this->assertSame(1200,    $q['handling']);
    }

    public function test_handling_can_be_charged_per_unit_instead(): void
    {
        config()->set('vendors.vendors.plasmaguard.pricing.handling.basis', 'unit');

        $this->assertSame(3600, app(VendorOrderService::class)->quote($this->lead(3))['handling']);
    }

    public function test_a_large_order_is_sent_to_a_human_for_freight(): void
    {
        // 45 to a pallet: past the parcel threshold, freight beats parcel by
        // enough that a parcel quote would be wrong rather than merely rough.
        config()->set('vendors.vendors.plasmaguard.pricing.shipping.freight_threshold_units', 8);

        $q = app(VendorOrderService::class)->quote($this->lead(12));

        $this->assertFalse($q['quotable']);
        $this->assertSame(ShippingQuote::SOURCE_FREIGHT, $q['shipping_quote']->source);
        $this->assertStringContainsString('freight', strtolower((string) $q['reason']));
    }

    public function test_an_unquotable_order_cannot_be_placed(): void
    {
        config()->set('vendors.vendors.plasmaguard.pricing.shipping.freight_threshold_units', 2);

        $this->expectException(\RuntimeException::class);

        app(VendorOrderService::class)->place($this->lead(9));
    }

    public function test_live_shipping_with_no_carrier_registered_refuses_to_invent_a_number(): void
    {
        // The failure this guards: silently quoting published rates, or zero,
        // because the carrier account was never wired up.
        config()->set('vendors.vendors.plasmaguard.pricing.shipping.mode', 'live');
        config()->set('vendors.vendors.plasmaguard.pricing.shipping.flat_amount', 0);

        $q = app(VendorOrderService::class)->quote($this->lead(1));

        $this->assertFalse($q['quotable']);
        $this->assertStringContainsString('carrier account', (string) $q['reason']);
    }

    public function test_an_unpriced_product_cannot_be_ordered(): void
    {
        config()->set('vendors.vendors.plasmaguard.products.pro-in-duct.price', null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/No price configured/');

        app(VendorOrderService::class)->quote($this->lead(1));
    }

    public function test_our_share_is_of_the_goods_only_not_shipping_or_tax(): void
    {
        // Raising shipping and handling must not move our cut by a cent — the
        // carrier's money and the state's money are not ours to share in.
        config()->set('vendors.vendors.plasmaguard.pricing.shipping.flat_amount', 50000);
        config()->set('vendors.vendors.plasmaguard.pricing.handling.amount', 9900);

        $this->assertSame(300000, app(VendorOrderService::class)->quote($this->lead(1))['our_share']);
    }

    // ── Staged checkout ───────────────────────────────────────────────────────

    public function test_stage_one_saves_the_lead_without_an_address(): void
    {
        config()->set('vendors.vendors.plasmaguard.checkout.mode', 'direct');

        // The whole point of splitting the form: the lead exists before the
        // address is asked for, so abandoning at shipping still leaves the
        // partner someone to call.
        $this->post('/p/PARTNER1/plasmaguard/pro-in-duct', [
            'first_name'    => 'Dana',
            'email'         => 'dana@example.com',
            'phone'         => '555-0100',
            'furnace_count' => 3,
            'quantity'      => 3,
        ])->assertRedirect();

        $lead = VendorLead::firstOrFail();

        $this->assertSame(3, $lead->quantity);
        $this->assertNull($lead->address_line1);
        $this->assertSame(VendorLead::STATUS_NEW, $lead->status);
        // The sizing answer is kept — it is the reasoning behind the quantity.
        $this->assertSame(3, $lead->qualifiers['furnace_count']);
    }

    public function test_stage_one_sends_a_direct_vendor_to_our_own_checkout(): void
    {
        config()->set('vendors.vendors.plasmaguard.checkout.mode', 'direct');

        $this->post('/p/PARTNER1/plasmaguard/pro-in-duct', [
            'first_name' => 'Dana', 'email' => 'dana@example.com',
        ])->assertRedirect(route('vendor.order', VendorLead::firstOrFail()->public_ref));
    }

    public function test_stage_two_asks_for_an_address_then_quotes(): void
    {
        $lead = $this->lead(2);
        $lead->forceFill(['address_line1' => null, 'postal_code' => null])->save();

        // Nothing to price yet, so it asks rather than showing a broken total.
        $this->get("/p/order/{$lead->public_ref}")
            ->assertOk()
            ->assertSee('Where should it ship?');

        $this->post("/p/order/{$lead->public_ref}/address", [
            'address_line1' => '100 Main St',
            'city'          => 'Ann Arbor',
            'state'         => 'MI',
            'postal_code'   => '48104',
        ])->assertRedirect(route('vendor.order', $lead->public_ref));

        // 2 x $6,000 goods + 2 x $42 shipping + $12 handling.
        $this->get("/p/order/{$lead->public_ref}")
            ->assertOk()
            ->assertSee('$12,000.00')
            ->assertSee('$96.00');
    }

    public function test_stage_two_requires_a_usable_address(): void
    {
        $lead = $this->lead(1);

        $this->post("/p/order/{$lead->public_ref}/address", ['address_line1' => '100 Main St'])
            ->assertSessionHasErrors(['city', 'state', 'postal_code']);
    }

    public function test_a_completed_order_cannot_be_edited(): void
    {
        $lead = $this->lead(1);
        $lead->forceFill(['status' => VendorLead::STATUS_CONVERTED])->save();

        $this->get("/p/order/{$lead->public_ref}")
            ->assertRedirect(route('vendor.complete', $lead->public_ref));
    }

    // ── Payment form regressions ──────────────────────────────────────────────

    public function test_the_payment_form_never_hands_stripe_js_a_null(): void
    {
        /*
         * Regression: a customer with no phone and no second address line got
         * `phone: null` and `line2: null` in confirmParams. Stripe.js throws on a
         * null rather than returning an error, so the Pay button sat on
         * "Processing…" with nothing on screen and the card was never tried.
         */
        config()->set('vendors.vendors.plasmaguard.stripe.key', 'pk_test_example');
        $lead = $this->lead(1);   // no phone, no line2

        $this->get("/p/order/{$lead->public_ref}")
            ->assertOk()
            ->assertSee('q3-payment-element', false)
            ->assertDontSee('"phone":null', false)
            ->assertDontSee('"line2":null', false)
            ->assertDontSee('phone: null', false);
    }

    public function test_the_payment_form_supplies_the_billing_fields_it_hides(): void
    {
        // The Element hides billing country and postcode with 'never'; they must
        // then be passed at confirm time, or confirmPayment throws for everyone.
        config()->set('vendors.vendors.plasmaguard.stripe.key', 'pk_test_example');
        $lead = $this->lead(1);

        $this->get("/p/order/{$lead->public_ref}")
            ->assertOk()
            ->assertSee('payment_method_data', false)
            ->assertSee('"country":"US"', false);
    }

    public function test_the_browser_never_tries_to_set_shipping(): void
    {
        /*
         * Regression: shipping is written onto the PaymentIntent server-side with
         * the vendor's restricted key. Stripe then refuses to let a publishable
         * key change it — "The shipping information on this PaymentIntent was
         * last set with a restricted key and therefore cannot be changed with a
         * publishable key" — so confirmParams must not carry shipping at all.
         */
        config()->set('vendors.vendors.plasmaguard.stripe.key', 'pk_test_example');
        $lead = $this->lead(1);

        $this->get("/p/order/{$lead->public_ref}")
            ->assertOk()
            ->assertSee('payment_method_data', false)   // billing still passed
            ->assertDontSee('shipping: shipping', false)
            ->assertDontSee('var shipping', false);
    }

    public function test_a_thrown_payment_error_cannot_leave_the_button_stuck(): void
    {
        config()->set('vendors.vendors.plasmaguard.stripe.key', 'pk_test_example');
        $lead = $this->lead(1);

        // Everything runs through pay().catch(), so a thrown IntegrationError
        // resets the button and shows a message instead of hanging silently.
        $this->get("/p/order/{$lead->public_ref}")
            ->assertOk()
            ->assertSee('pay().catch(', false);
    }

    public function test_idempotency_keys_follow_the_order_parameters(): void
    {
        $service = app(VendorOrderService::class);
        $key     = new \ReflectionMethod($service, 'idempotencyKey');
        $lead    = $this->lead(1);

        $wi  = $key->invoke($service, 'pi', $lead, ['amount' => 605400, 'shipping' => ['address' => ['state' => 'WI']]]);
        $wi2 = $key->invoke($service, 'pi', $lead, ['shipping' => ['address' => ['state' => 'WI']], 'amount' => 605400]);
        $tx  = $key->invoke($service, 'pi', $lead, ['amount' => 605400, 'shipping' => ['address' => ['state' => 'TX']]]);

        // Parameter order is irrelevant: a genuine retry must dedupe.
        $this->assertSame($wi, $wi2);
        // Same total, different address — a different request. Reusing the key
        // here is what makes Stripe refuse and the checkout wedge.
        $this->assertNotSame($wi, $tx);
        $this->assertStringStartsWith("qlv_pi_{$lead->public_ref}_", $wi);
    }

    private function lead(int $qty): VendorLead
    {
        return VendorLead::create([
            'public_ref'    => 'QLV-'.strtoupper(\Illuminate\Support\Str::random(10)),
            'vendor'        => 'plasmaguard',
            'product_key'   => 'pro-in-duct',
            'member_id'     => $this->member->id,
            'referral_code' => $this->member->referral_code,
            'first_name'    => 'Dana',
            'email'         => 'dana@example.com',
            'address_line1' => '100 Main St',
            'city'          => 'Ann Arbor',
            'state'         => 'MI',
            'postal_code'   => '48104',
            'country'       => 'US',
            'quantity'      => $qty,
            'status'        => VendorLead::STATUS_NEW,
        ]);
    }
}
