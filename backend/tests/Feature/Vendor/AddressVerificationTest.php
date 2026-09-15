<?php

namespace Tests\Feature\Vendor;

use App\Models\Role;
use App\Models\User;
use App\Models\VendorLead;
use App\Services\Vendor\Shipping\AddressVerification;
use App\Services\Vendor\VendorOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Verifying the delivery address before payment, on both order paths.
 *
 * FedEx is faked throughout with production-shaped replies (attribute values as
 * the strings "true" and "false"). The real sandbox returns one canned address
 * whatever is sent, so it cannot test any of this.
 */
class AddressVerificationTest extends TestCase
{
    use RefreshDatabase;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        config()->set('vendors.vendors.plasmaguard.enabled', true);
        config()->set('vendors.vendors.plasmaguard.checkout.mode', 'direct');
        config()->set('vendors.vendors.plasmaguard.stripe.automatic_tax', false);
        config()->set('vendors.vendors.plasmaguard.stripe.key', 'pk_test_example');
        config()->set('vendors.vendors.plasmaguard.products.pro-in-duct.price', 6000);
        config()->set('vendors.vendors.plasmaguard.pricing.shipping.mode', 'flat');
        config()->set('vendors.vendors.plasmaguard.pricing.shipping.flat_amount', 4200);

        config()->set('fedex.key', 'test_key');
        config()->set('fedex.secret', 'test_secret');
        config()->set('fedex.host', 'https://apis-sandbox.fedex.com');

        // Rating is not under test here.
        $this->app->bind(\App\Services\Vendor\Shipping\ShippingRater::class,
            fn () => new \App\Services\Vendor\Shipping\FlatShippingRater('plasmaguard'));

        $this->member = User::factory()->create([
            'name' => 'Pat Partner', 'referral_code' => 'PARTNER1', 'billing_exempt' => true,
        ]);
    }

    // ── Verified ──────────────────────────────────────────────────────────────

    public function test_a_deliverable_address_is_verified_and_payment_opens(): void
    {
        $this->fakeFedEx($this->reply());
        $lead = $this->lead();

        $this->submitAddress($lead)->assertRedirect(route('vendor.order', $lead->public_ref));

        $lead->refresh();
        $this->assertSame(AddressVerification::VERIFIED, $lead->address_status);
        $this->assertSame('RESIDENTIAL', $lead->address_classification);
        $this->assertTrue($lead->addressReadyForPayment());

        $this->get("/p/order/{$lead->public_ref}")
            ->assertOk()
            ->assertSee('Address verified by FedEx')
            ->assertSee('q3-pay-button', false);
    }

    public function test_fedex_is_sent_the_full_street_address(): void
    {
        $this->fakeFedEx($this->reply());

        $this->submitAddress($this->lead(), ['address_line2' => 'Unit 4']);

        Http::assertSent(function (HttpRequest $request) {
            if (! str_contains($request->url(), '/address/v1/addresses/resolve')) {
                return false;
            }

            $address = $request->data()['addressesToValidate'][0]['address'] ?? [];

            return $address['streetLines'] === ['201 Minor Ct', 'Unit 4']
                && $address['postalCode'] === '54303'
                && $address['countryCode'] === 'US';
        });
    }

    public function test_fedex_formatting_is_used_when_only_the_formatting_differs(): void
    {
        $this->fakeFedEx($this->reply());
        $lead = $this->lead();

        $this->submitAddress($lead, ['address_line1' => '201 Minor Court']);

        $lead->refresh();
        $this->assertSame(AddressVerification::VERIFIED, $lead->address_status);
        $this->assertSame('201 MINOR CT', $lead->address_line1);
    }

    public function test_a_unit_number_fedex_leaves_out_is_kept(): void
    {
        $this->fakeFedEx($this->reply());
        $lead = $this->lead();

        $this->submitAddress($lead, ['address_line2' => 'Unit 4']);

        $this->assertSame('Unit 4', $lead->refresh()->address_line2);
    }

    // ── A correction to choose ────────────────────────────────────────────────

    public function test_a_corrected_zip_is_offered_to_the_buyer_not_applied(): void
    {
        $this->fakeFedEx($this->reply(['postalCode' => '54304', 'parsedPostalCode' => ['base' => '54304', 'addOn' => '1234']]));
        $lead = $this->lead();

        $this->submitAddress($lead);
        $lead->refresh();

        $this->assertSame(AddressVerification::SUGGESTED, $lead->address_status);
        $this->assertSame('54303', $lead->postal_code);
        $this->assertFalse($lead->addressReadyForPayment());

        $this->get("/p/order/{$lead->public_ref}")
            ->assertOk()
            ->assertSee('FedEx suggests a correction')
            ->assertSee('54304-1234')
            ->assertDontSee('q3-pay-button', false);

        $this->post("/p/order/{$lead->public_ref}/address/confirm", ['choice' => 'suggested'])->assertRedirect();

        $lead->refresh();
        $this->assertSame('54304-1234', $lead->postal_code);
        $this->assertSame(AddressVerification::VERIFIED, $lead->address_status);
        $this->assertTrue($lead->addressReadyForPayment());
        $this->assertFalse($lead->needsAddressReview());
    }

    public function test_keeping_the_entered_address_flags_it_for_an_admin(): void
    {
        $this->fakeFedEx($this->reply(['postalCode' => '54304', 'parsedPostalCode' => ['base' => '54304']]));
        $lead = $this->lead();
        $this->submitAddress($lead);

        $this->post("/p/order/{$lead->public_ref}/address/confirm", ['choice' => 'entered'])->assertRedirect();

        $lead->refresh();
        $this->assertSame('54303', $lead->postal_code);
        $this->assertTrue($lead->addressReadyForPayment());
        $this->assertTrue($lead->needsAddressReview());

        $admin = $this->admin();

        $this->actingAs($admin)->get('/admin/vendor-leads')->assertOk()->assertSee('Check address');
        $this->actingAs($admin)->get("/admin/vendor-leads/{$lead->id}")->assertOk()->assertSee('I have checked this address');

        $this->actingAs($admin)->post("/admin/vendor-leads/{$lead->id}/address-reviewed")->assertRedirect();

        $lead->refresh();
        $this->assertFalse($lead->needsAddressReview());
        $this->assertSame($admin->id, $lead->address_reviewed_by);
    }

    // ── Not confirmed by FedEx ────────────────────────────────────────────────

    public function test_a_missing_unit_number_needs_the_buyer_to_tick_confirm(): void
    {
        $this->fakeFedEx($this->reply([], ['SuiteRequiredButMissing' => 'true']));
        $lead = $this->lead();
        $this->submitAddress($lead);

        $this->assertSame(AddressVerification::UNVERIFIED, $lead->refresh()->address_status);

        $this->get("/p/order/{$lead->public_ref}")
            ->assertOk()
            ->assertSee('needs an apartment, suite or unit number')
            ->assertDontSee('q3-pay-button', false);

        // A button press alone is not a confirmation.
        $this->post("/p/order/{$lead->public_ref}/address/confirm", ['choice' => 'entered'])
            ->assertSessionHasErrors('confirm_address');
        $this->assertFalse($lead->refresh()->addressReadyForPayment());

        $this->post("/p/order/{$lead->public_ref}/address/confirm", ['choice' => 'entered', 'confirm_address' => '1'])
            ->assertSessionHasNoErrors();

        $this->assertTrue($lead->refresh()->addressReadyForPayment());
        $this->assertTrue($lead->needsAddressReview());

        $this->get("/p/order/{$lead->public_ref}")
            ->assertSee('You confirmed this delivery address')
            ->assertSee('q3-pay-button', false);
    }

    public function test_payment_cannot_start_until_the_address_is_settled(): void
    {
        $this->fakeFedEx($this->reply([], ['DPV' => 'false']));
        $lead = $this->lead();
        $this->submitAddress($lead);

        $this->postJson("/p/order/{$lead->public_ref}/pay")
            ->assertStatus(422)
            ->assertJson(['error' => 'Please confirm the delivery address before paying.']);

        // And no charge can be built for it by any other route.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/delivery address/');

        app(VendorOrderService::class)->place($lead->refresh());
    }

    public function test_fedex_being_down_does_not_block_the_sale(): void
    {
        Http::fake([
            '*/oauth/token'                  => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            '*/address/v1/addresses/resolve' => Http::response(['errors' => [['code' => 'SERVICE.UNAVAILABLE.ERROR']]], 503),
        ]);
        $lead = $this->lead();
        $this->submitAddress($lead);

        $this->assertSame(AddressVerification::UNAVAILABLE, $lead->refresh()->address_status);

        $this->get("/p/order/{$lead->public_ref}")->assertSee("We couldn't check this address automatically.");

        $this->post("/p/order/{$lead->public_ref}/address/confirm", ['choice' => 'entered', 'confirm_address' => '1'])
            ->assertSessionHasNoErrors();

        $lead->refresh();
        $this->assertTrue($lead->addressReadyForPayment());
        $this->assertTrue($lead->needsAddressReview());
    }

    public function test_the_sandbox_canned_reply_is_not_trusted(): void
    {
        $this->fakeFedEx($this->reply(
            ['countryCode' => 'CL', 'stateOrProvinceCode' => 'Región Metropolitana'],
            [],
            [['code' => 'VIRTUAL.RESPONSE', 'message' => 'This is a Virtual Response.', 'alertType' => 'NOTE']],
        ));
        $lead = $this->lead();

        $this->submitAddress($lead);

        $this->assertSame(AddressVerification::UNAVAILABLE, $lead->refresh()->address_status);
    }

    public function test_without_fedex_credentials_nothing_is_sent_and_the_buyer_confirms(): void
    {
        config()->set('fedex.key', null);
        Http::fake();
        $lead = $this->lead();

        $this->submitAddress($lead);

        $this->assertSame(AddressVerification::UNAVAILABLE, $lead->refresh()->address_status);
        Http::assertNothingSent();
    }

    // ── PO Boxes ──────────────────────────────────────────────────────────────

    public function test_a_po_box_is_refused_on_the_customer_checkout(): void
    {
        Http::fake();
        $lead = $this->lead();

        $this->submitAddress($lead, ['address_line1' => 'P.O. Box 1234'])->assertSessionHasErrors('address_line1');

        $this->assertNull($lead->refresh()->address_line1);
        Http::assertNothingSent();
    }

    public function test_a_po_box_is_refused_on_the_back_office_order(): void
    {
        Http::fake();

        $this->actingAs($this->member)->post('/member/sales/buy/plasmaguard/pro-in-duct', [
            'first_name'    => 'Pat',
            'address_line1' => 'PO Box 88',
            'city'          => 'Green Bay',
            'state'         => 'WI',
            'postal_code'   => '54303',
            'quantity'      => 1,
        ])->assertSessionHasErrors('address_line1');

        $this->assertSame(0, VendorLead::count());
    }

    public function test_an_address_fedex_flags_as_a_po_box_cannot_be_confirmed_as_entered(): void
    {
        $this->fakeFedEx($this->reply([], ['POBox' => 'true']));
        $lead = $this->lead();
        $this->submitAddress($lead, ['address_line1' => '1 Postal Plaza Drawer 5']);

        $this->assertSame(AddressVerification::REJECTED, $lead->refresh()->address_status);

        $this->post("/p/order/{$lead->public_ref}/address/confirm", ['choice' => 'entered', 'confirm_address' => '1'])
            ->assertSessionHasErrors('address');

        $this->assertFalse($lead->refresh()->addressReadyForPayment());
    }

    // ── Re-checking ───────────────────────────────────────────────────────────

    public function test_changing_the_address_clears_an_earlier_confirmation(): void
    {
        $this->fakeFedEx($this->reply([], ['DPV' => 'false']));
        $lead = $this->lead();
        $this->submitAddress($lead);
        $this->post("/p/order/{$lead->public_ref}/address/confirm", ['choice' => 'entered', 'confirm_address' => '1']);
        $this->assertTrue($lead->refresh()->addressReadyForPayment());

        $this->submitAddress($lead, ['address_line1' => '305 Minor Ct']);

        $lead->refresh();
        $this->assertNull($lead->address_confirmed_at);
        $this->assertFalse($lead->addressReadyForPayment());
    }

    public function test_changing_only_the_quantity_does_not_check_again(): void
    {
        $this->fakeFedEx($this->reply());
        $lead = $this->lead();

        $this->submitAddress($lead);
        $this->submitAddress($lead, ['address_line1' => '201 MINOR CT', 'city' => 'GREEN BAY', 'quantity' => 2]);

        $this->assertSame(2, $lead->refresh()->quantity);
        // One token and one address check, from the first submission only.
        Http::assertSentCount(2);
    }

    // ── Both order paths ──────────────────────────────────────────────────────

    public function test_a_back_office_order_is_checked_straight_away(): void
    {
        $this->fakeFedEx($this->reply());

        $this->actingAs($this->member)->post('/member/sales/buy/plasmaguard/pro-in-duct', [
            'first_name'    => 'Pat',
            'address_line1' => '201 Minor Ct',
            'city'          => 'Green Bay',
            'state'         => 'WI',
            'postal_code'   => '54303',
            'quantity'      => 1,
        ])->assertRedirect();

        $this->assertSame(AddressVerification::VERIFIED, VendorLead::firstOrFail()->address_status);
    }

    public function test_an_address_saved_before_checking_existed_is_checked_when_the_order_opens(): void
    {
        $this->fakeFedEx($this->reply());
        $lead = $this->lead([
            'address_line1' => '201 Minor Ct', 'city' => 'Green Bay', 'state' => 'WI',
            'postal_code'   => '54303', 'country' => 'US',
        ]);

        $this->get("/p/order/{$lead->public_ref}")->assertOk()->assertSee('q3-pay-button', false);

        $this->assertSame(AddressVerification::VERIFIED, $lead->refresh()->address_status);
    }

    public function test_the_vendor_is_told_how_the_address_was_checked(): void
    {
        $lead = $this->lead([
            'address_line1' => '201 Minor Ct', 'city' => 'Green Bay', 'state' => 'WI',
            'postal_code'   => '54303', 'country' => 'US',
        ]);
        $lead->forceFill([
            'address_status'         => AddressVerification::UNVERIFIED,
            'address_confirmed_at'   => now(),
            'address_classification' => 'BUSINESS',
        ])->save();

        $service  = app(VendorOrderService::class);
        $metadata = (new \ReflectionMethod($service, 'metadata'))->invoke($service, $lead, $service->quote($lead));

        $this->assertSame('Buyer confirmed; not verified', $metadata['ship_address_check']);
        $this->assertSame('BUSINESS', $metadata['ship_address_type']);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** @param  array<string,mixed>  $attributes */
    private function lead(array $attributes = []): VendorLead
    {
        return VendorLead::create($attributes + [
            'public_ref'    => 'QLV-'.strtoupper(Str::random(10)),
            'vendor'        => 'plasmaguard',
            'product_key'   => 'pro-in-duct',
            'member_id'     => $this->member->id,
            'referral_code' => 'PARTNER1',
            'first_name'    => 'Dana',
            'email'         => 'dana@example.com',
            'quantity'      => 1,
            'status'        => VendorLead::STATUS_NEW,
        ]);
    }

    /** @param  array<string,mixed>  $overrides */
    private function submitAddress(VendorLead $lead, array $overrides = []): TestResponse
    {
        return $this->post("/p/order/{$lead->public_ref}/address", $overrides + [
            'address_line1' => '201 Minor Ct',
            'city'          => 'Green Bay',
            'state'         => 'WI',
            'postal_code'   => '54303',
        ]);
    }

    /** @param  array<string,mixed>  $reply */
    private function fakeFedEx(array $reply): void
    {
        Http::fake([
            '*/oauth/token'                  => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            '*/address/v1/addresses/resolve' => Http::response($reply),
        ]);
    }

    /**
     * A production-shaped FedEx reply for a deliverable residential address.
     *
     * @param  array<string,mixed>  $address
     * @param  array<string,string>  $attributes
     * @param  list<array<string,string>>  $alerts
     * @return array<string,mixed>
     */
    private function reply(array $address = [], array $attributes = [], array $alerts = []): array
    {
        return [
            'transactionId' => 'test',
            'output'        => [
                'alerts'            => $alerts,
                'resolvedAddresses' => [$address + [
                    'streetLinesToken'    => ['201 MINOR CT'],
                    'city'                => 'GREEN BAY',
                    'stateOrProvinceCode' => 'WI',
                    'postalCode'          => '54303',
                    'parsedPostalCode'    => ['base' => '54303'],
                    'countryCode'         => 'US',
                    'classification'      => 'RESIDENTIAL',
                    'attributes'          => $attributes + [
                        'POBox'                     => 'false',
                        'SuiteRequiredButMissing'   => 'false',
                        'InvalidSuiteNumber'        => 'false',
                        'MultipleMatches'           => 'false',
                        'Resolved'                  => 'true',
                        'DPV'                       => 'true',
                        'InterpolatedStreetAddress' => 'false',
                        'Matched'                   => 'true',
                    ],
                ]],
            ],
        ];
    }

    private function admin(): User
    {
        $role = Role::firstOrCreate(
            ['name' => Role::SUPER_ADMIN],
            ['display_name' => 'Super Admin', 'is_admin' => true, 'level' => 100],
        );

        return User::factory()->create(['role_id' => $role->id]);
    }
}
