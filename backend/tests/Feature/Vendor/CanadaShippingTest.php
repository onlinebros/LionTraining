<?php

namespace Tests\Feature\Vendor;

use App\Models\User;
use App\Models\VendorLead;
use App\Services\Vendor\Shipping\AddressVerification;
use App\Services\Vendor\Shipping\FedExShippingRater;
use App\Services\Vendor\Shipping\FlatShippingRater;
use App\Services\Vendor\Shipping\ShippingQuote;
use App\Services\Vendor\Shipping\ShippingRater;
use App\Support\ShippingCountries;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Delivering to Canada.
 *
 * Before 2026-10-28 the order form had no country, so a Canadian address went
 * to FedEx as a US one and the buyer could not get past the shipping quote.
 */
class CanadaShippingTest extends TestCase
{
    use RefreshDatabase;

    private const ORIGIN = ['city' => 'Livonia', 'state' => 'MI', 'postal_code' => '48150', 'country' => 'US'];
    private const TORONTO = ['city' => 'Toronto', 'state' => 'ON', 'postal_code' => 'M5V 2T6', 'country' => 'CA'];
    private const PARCEL = [
        'length_in' => 12, 'width_in' => 12, 'height_in' => 15, 'weight_lb' => 9,
        'units_per_carton' => 1, 'contents' => 'Generator, Hub and Sensor', 'customs_unit_value' => 6000.0,
    ];

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
        config()->set('vendors.vendors.plasmaguard.pricing.shipping.carrier.account_number', '769848738');
        config()->set('vendors.vendors.plasmaguard.pricing.shipping.carrier.require_negotiated', true);
        config()->set('vendors.vendors.plasmaguard.pricing.shipping.freight_threshold_units', 8);

        config()->set('fedex.key', 'test_key');
        config()->set('fedex.secret', 'test_secret');
        config()->set('fedex.host', 'https://apis-sandbox.fedex.com');
        config()->set('fedex.services', ['FEDEX_GROUND', 'GROUND_HOME_DELIVERY']);
        config()->set('fedex.international_services', ['FEDEX_GROUND', 'INTERNATIONAL_ECONOMY']);

        $this->member = User::factory()->create([
            'name' => 'Pat Partner', 'referral_code' => 'PARTNER1', 'billing_exempt' => true,
        ]);
    }

    // ── The address form ──────────────────────────────────────────────────────

    public function test_a_canadian_address_is_saved_in_canadian_form_and_checked_as_canadian(): void
    {
        $this->fakeFedEx();
        $lead = $this->lead();

        $this->post("/p/order/{$lead->public_ref}/address", [
            'country'       => 'CA',
            'address_line1' => '290 Bremner Blvd',
            'city'          => 'Toronto',
            'state'         => 'Ontario',
            'postal_code'   => 'm5v3l9',
        ])->assertRedirect(route('vendor.order', $lead->public_ref))->assertSessionHasNoErrors();

        $lead->refresh();
        $this->assertSame('CA', $lead->country);
        $this->assertSame('ON', $lead->state);
        $this->assertSame('M5V 3L9', $lead->postal_code);

        // FedEx agreed with it as typed, so no "did you mean" for the buyer.
        $this->assertSame(AddressVerification::VERIFIED, $lead->address_status);

        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), '/addresses/resolve')
            && ($r->data()['addressesToValidate'][0]['address']['countryCode'] ?? null) === 'CA');
    }

    public function test_the_order_page_tells_a_canadian_buyer_about_duties(): void
    {
        $this->fakeFedEx();
        $lead = $this->lead();

        $this->post("/p/order/{$lead->public_ref}/address", [
            'country' => 'CA', 'address_line1' => '290 Bremner Blvd', 'city' => 'Toronto',
            'state' => 'ON', 'postal_code' => 'M5V 3L9',
        ]);

        $this->get("/p/order/{$lead->public_ref}")
            ->assertOk()
            ->assertSee('Ships from the United States to Canada')
            ->assertSee('may be collected by FedEx on delivery')
            ->assertSee('<option value="CA" selected', false)
            ->assertSee('Postal code');
    }

    public function test_a_us_order_page_has_no_duties_note(): void
    {
        $this->fakeFedEx(country: 'US');
        $lead = $this->lead();

        $this->post("/p/order/{$lead->public_ref}/address", [
            'address_line1' => '290 Bremner Blvd', 'city' => 'Green Bay', 'state' => 'WI', 'postal_code' => '54303',
        ]);

        $this->assertSame('US', $lead->fresh()->country);
        $this->get("/p/order/{$lead->public_ref}")->assertOk()->assertDontSee('may be collected by FedEx on delivery');
    }

    public function test_a_us_zip_is_refused_for_a_canadian_address(): void
    {
        Http::fake();
        $lead = $this->lead();

        $this->post("/p/order/{$lead->public_ref}/address", [
            'country' => 'CA', 'address_line1' => '290 Bremner Blvd', 'city' => 'Toronto',
            'state' => 'Wisconsin', 'postal_code' => '54303',
        ])->assertSessionHasErrors(['state', 'postal_code']);

        $this->assertNull($lead->fresh()->postal_code);
        Http::assertNothingSent();
    }

    public function test_only_supported_countries_are_accepted(): void
    {
        Http::fake();
        $lead = $this->lead();

        $this->post("/p/order/{$lead->public_ref}/address", [
            'country' => 'MX', 'address_line1' => 'Av. Reforma 1', 'city' => 'CDMX',
            'state' => 'CDMX', 'postal_code' => '06600',
        ])->assertSessionHasErrors('country');
    }

    public function test_a_partner_can_buy_for_themselves_in_canada(): void
    {
        $this->fakeFedEx();

        $this->actingAs($this->member)->post('/member/sales/buy/plasmaguard/pro-in-duct', [
            'first_name'    => 'Pat',
            'country'       => 'CA',
            'address_line1' => '290 Bremner Blvd',
            'city'          => 'Toronto',
            'state'         => 'on',
            'postal_code'   => 'M5V-3L9',
            'quantity'      => 1,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $lead = VendorLead::firstOrFail();
        $this->assertSame(['CA', 'ON', 'M5V 3L9'], [$lead->country, $lead->state, $lead->postal_code]);
    }

    // ── Rating ────────────────────────────────────────────────────────────────

    public function test_a_canadian_parcel_is_rated_with_customs(): void
    {
        $this->fakeRate([$this->service('FEDEX_GROUND', 'FedEx International Ground', 38.10, 61.25)]);

        $quote = $this->rater()->quote(self::ORIGIN, self::TORONTO, self::PARCEL, 2);

        $this->assertTrue($quote->isQuotable());
        $this->assertSame(3810, $quote->amount);

        Http::assertSent(function (HttpRequest $r) {
            if (! str_contains($r->url(), '/rate/')) {
                return false;
            }
            $shipment  = $r->data()['requestedShipment'];
            $customs   = $shipment['customsClearanceDetail'] ?? [];
            $commodity = $customs['commodities'][0] ?? [];

            return $shipment['recipient']['address']['countryCode'] === 'CA'
                && $shipment['recipient']['address']['stateOrProvinceCode'] === 'ON'
                // FedEx refuses RECIPIENT on a rate request; the label sets who pays duties.
                && ! array_key_exists('dutiesPayment', $customs)
                && $commodity['quantity'] === 2
                && $commodity['customsValue'] === ['amount' => 12000.0, 'currency' => 'USD']   // the line, not one unit
                && $commodity['weight'] === ['units' => 'LB', 'value' => 18.0];
        });
    }

    public function test_a_domestic_parcel_carries_no_customs(): void
    {
        $this->fakeRate([$this->service('FEDEX_GROUND', 'FedEx Ground', 24.31, 41.80)]);

        $this->rater()->quote(self::ORIGIN, ['city' => 'Ann Arbor', 'state' => 'MI', 'postal_code' => '48104', 'country' => 'US'], self::PARCEL, 1);

        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), '/rate/')
            && ! array_key_exists('customsClearanceDetail', $r->data()['requestedShipment']));
    }

    public function test_international_economy_is_used_where_ground_does_not_reach(): void
    {
        $this->fakeRate([$this->service('INTERNATIONAL_ECONOMY', 'FedEx International Economy', 92.40, 140.00)]);

        $quote = $this->rater()->quote(self::ORIGIN, self::TORONTO, self::PARCEL, 1);

        $this->assertTrue($quote->isQuotable());
        $this->assertSame(9240, $quote->amount);
    }

    public function test_an_express_service_is_not_quoted_for_a_domestic_parcel(): void
    {
        // The international list must not widen what a US parcel can be sold.
        $this->fakeRate([$this->service('INTERNATIONAL_ECONOMY', 'FedEx International Economy', 92.40, 140.00)]);

        $quote = $this->rater()->quote(self::ORIGIN, ['city' => 'Ann Arbor', 'state' => 'MI', 'postal_code' => '48104', 'country' => 'US'], self::PARCEL, 1);

        $this->assertFalse($quote->isQuotable());
    }

    public function test_the_domestic_flat_rate_is_never_charged_on_a_canadian_parcel(): void
    {
        config()->set('vendors.vendors.plasmaguard.pricing.shipping.mode', 'flat');
        config()->set('vendors.vendors.plasmaguard.pricing.shipping.flat_amount', 4200);

        $quote = (new FlatShippingRater('plasmaguard'))->quote(self::ORIGIN, self::TORONTO, self::PARCEL, 1);

        $this->assertFalse($quote->isQuotable());
        $this->assertTrue($quote->requiresHuman);
    }

    public function test_a_fedex_outage_does_not_fall_back_to_the_flat_rate_for_canada(): void
    {
        config()->set('vendors.vendors.plasmaguard.pricing.shipping.flat_amount', 4200);
        config()->set('vendors.vendors.plasmaguard.pricing.shipping.fallback_on_outage', true);
        Http::fake([
            '*/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            '*/rate/*'      => Http::response(['errors' => [['code' => 'SERVICE.UNAVAILABLE.ERROR']]], 503),
        ]);

        $quote = $this->rater()->quote(self::ORIGIN, self::TORONTO, self::PARCEL, 1);

        $this->assertFalse($quote->isQuotable());
        $this->assertNotSame(ShippingQuote::SOURCE_FLAT, $quote->source);
    }

    // ── Normalising ───────────────────────────────────────────────────────────

    public function test_provinces_and_postal_codes_are_normalised(): void
    {
        $this->assertSame('QC', ShippingCountries::provinceCode('Québec'));
        $this->assertSame('BC', ShippingCountries::provinceCode('british columbia'));
        $this->assertSame('NL', ShippingCountries::provinceCode('Newfoundland & Labrador'));
        $this->assertNull(ShippingCountries::provinceCode('WI'));

        $this->assertSame('K1A 0B1', ShippingCountries::canadianPostal(' k1a-0b1 '));
        $this->assertNull(ShippingCountries::canadianPostal('K1A OB1'));   // letter O for zero
        $this->assertNull(ShippingCountries::canadianPostal('54303'));
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function rater(): FedExShippingRater
    {
        return new FedExShippingRater('plasmaguard');
    }

    private function lead(): VendorLead
    {
        // The address check is under test; the quote on the order page is not.
        $this->app->bind(ShippingRater::class, fn () => new class implements ShippingRater {
            public function quote(array $origin, array $destination, array $parcel, int $cartons): ShippingQuote
            {
                return new ShippingQuote(amount: 3810, source: ShippingQuote::SOURCE_CARRIER, service: 'FedEx International Ground', carrier: 'FedEx');
            }
        });

        return VendorLead::create([
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

    /** A production-shaped FedEx address reply for 290 Bremner Blvd, Toronto. */
    private function fakeFedEx(string $country = 'CA'): void
    {
        $resolved = $country === 'CA'
            ? ['city' => 'TORONTO', 'stateOrProvinceCode' => 'ON', 'postalCode' => 'M5V3L9', 'parsedPostalCode' => ['base' => 'M5V3L9']]
            : ['city' => 'GREEN BAY', 'stateOrProvinceCode' => 'WI', 'postalCode' => '54303', 'parsedPostalCode' => ['base' => '54303']];

        Http::fake([
            '*/oauth/token'                  => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            '*/address/v1/addresses/resolve' => Http::response(['output' => ['alerts' => [], 'resolvedAddresses' => [$resolved + [
                'streetLinesToken' => ['290 BREMNER BLVD'],
                'countryCode'      => $country,
                'classification'   => 'BUSINESS',
                'attributes'       => [
                    'POBox' => 'false', 'SuiteRequiredButMissing' => 'false', 'InvalidSuiteNumber' => 'false',
                    'MultipleMatches' => 'false', 'Resolved' => 'true', 'InterpolatedStreetAddress' => 'false',
                    'Matched' => 'true',
                ],
            ]]]]),
        ]);
    }

    /** @param  list<array<string,mixed>>  $services */
    private function fakeRate(array $services): void
    {
        Http::fake([
            '*/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            '*/rate/*'      => Http::response(['output' => ['rateReplyDetails' => $services]]),
        ]);
    }

    /** @return array<string,mixed> */
    private function service(string $type, string $name, float $account, float $list): array
    {
        return [
            'serviceType' => $type,
            'serviceName' => $name,
            'ratedShipmentDetails' => [
                ['rateType' => 'ACCOUNT', 'totalNetCharge' => $account],
                ['rateType' => 'LIST',    'totalNetCharge' => $list],
            ],
        ];
    }
}
