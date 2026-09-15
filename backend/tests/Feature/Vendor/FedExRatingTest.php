<?php

namespace Tests\Feature\Vendor;

use App\Services\Vendor\Shipping\FedExShippingRater;
use App\Services\Vendor\Shipping\ShippingQuote;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * FedEx rating, against recorded responses.
 *
 * Nothing here touches the network. FedEx's sandbox returned 503 for the whole
 * period this was written, and a test suite that goes red because someone
 * else's sandbox is down is a test suite people learn to ignore.
 */
class FedExRatingTest extends TestCase
{
    private const ORIGIN = ['city' => 'Livonia', 'state' => 'MI', 'postal_code' => '48150', 'country' => 'US'];
    private const DEST   = ['city' => 'Ann Arbor', 'state' => 'MI', 'postal_code' => '48104', 'country' => 'US'];
    private const PARCEL = ['length_in' => 12, 'width_in' => 12, 'height_in' => 15, 'weight_lb' => 9];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        config()->set('fedex.key', 'test_key');
        config()->set('fedex.secret', 'test_secret');
        config()->set('fedex.host', 'https://apis-sandbox.fedex.com');
        config()->set('fedex.services', ['FEDEX_GROUND', 'GROUND_HOME_DELIVERY']);
        config()->set('vendors.vendors.plasmaguard.pricing.shipping.carrier.account_number', '769848738');
        config()->set('vendors.vendors.plasmaguard.pricing.shipping.carrier.require_negotiated', true);
        config()->set('vendors.vendors.plasmaguard.pricing.shipping.freight_threshold_units', 8);
    }

    public function test_it_returns_the_negotiated_rate(): void
    {
        $this->fakeFedEx($this->rateReply(account: 24.31, list: 41.80));

        $quote = $this->rater()->quote(self::ORIGIN, self::DEST, self::PARCEL, 1);

        $this->assertTrue($quote->isQuotable());
        $this->assertSame(2431, $quote->amount);           // the negotiated figure, not the $41.80 list
        $this->assertSame(ShippingQuote::SOURCE_CARRIER, $quote->source);
        $this->assertSame('FedEx', $quote->carrier);
    }

    public function test_a_home_delivery_is_rated_as_residential(): void
    {
        // Without the flag FedEx quotes a house as a business, and the
        // residential surcharge is missing from every home delivery quote.
        $this->fakeFedEx($this->rateReply(account: 24.31, list: 41.80));

        $this->rater()->quote(self::ORIGIN, self::DEST + ['residential' => true], self::PARCEL, 1);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/rate/')
            && ($request->data()['requestedShipment']['recipient']['address']['residential'] ?? null) === true);
    }

    public function test_it_sends_dimensions_so_the_box_is_dim_weighted(): void
    {
        // 12 x 12 x 15 = 2160 cu in, which bills at ~16 lb against 9 lb actual.
        // Weight alone would under-quote every shipment by roughly 40%.
        $this->fakeFedEx($this->rateReply(account: 24.31, list: 41.80));

        $this->rater()->quote(self::ORIGIN, self::DEST, self::PARCEL, 1);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/rate/')) {
                return true;
            }
            $dims = $request->data()['requestedShipment']['requestedPackageLineItems'][0]['dimensions'] ?? [];

            return $dims === ['length' => 12, 'width' => 12, 'height' => 15, 'units' => 'IN'];
        });
    }

    public function test_each_unit_is_its_own_parcel(): void
    {
        $this->fakeFedEx($this->rateReply(account: 24.31, list: 41.80));

        $this->rater()->quote(self::ORIGIN, self::DEST, self::PARCEL, 3);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/rate/')) {
                return true;
            }

            // One unit per carton, so three systems is three parcels.
            return count($request->data()['requestedShipment']['requestedPackageLineItems']) === 3;
        });
    }

    public function test_a_retail_rate_is_refused_rather_than_quoted(): void
    {
        /*
         * The dangerous failure: the vendor's account is not really linked, so
         * FedEx answers with published rates and the checkout keeps working
         * while overcharging. Identical ACCOUNT and LIST figures are the tell.
         */
        $this->fakeFedEx($this->rateReply(account: 41.80, list: 41.80));

        $quote = $this->rater()->quote(self::ORIGIN, self::DEST, self::PARCEL, 1);

        $this->assertFalse($quote->isQuotable());
        $this->assertStringContainsString('not be linked', (string) $quote->reason);
    }

    public function test_an_outage_falls_back_to_the_agreed_flat_rate(): void
    {
        /*
         * FedEx sandbox returned 503 for days while this was written. Refusing
         * every sale for the duration of someone else's outage punishes the
         * customer for it — so a flat figure the vendor agreed to is used, and
         * labelled `flat` rather than passed off as a carrier rate.
         */
        config()->set('vendors.vendors.plasmaguard.pricing.shipping.flat_amount', 4200);
        config()->set('vendors.vendors.plasmaguard.pricing.shipping.fallback_on_outage', true);
        $this->fakeOutage();

        $quote = $this->rater()->quote(self::ORIGIN, self::DEST, self::PARCEL, 2);

        $this->assertTrue($quote->isQuotable());
        $this->assertSame(8400, $quote->amount);                       // 2 cartons
        $this->assertSame(ShippingQuote::SOURCE_FLAT, $quote->source); // honest about provenance
    }

    public function test_an_outage_with_no_agreed_fallback_goes_to_a_human(): void
    {
        // Nothing honest to fall back to, so nobody gets an invented number.
        config()->set('vendors.vendors.plasmaguard.pricing.shipping.flat_amount', 0);
        $this->fakeOutage();

        $quote = $this->rater()->quote(self::ORIGIN, self::DEST, self::PARCEL, 1);

        $this->assertFalse($quote->isQuotable());
        $this->assertTrue($quote->requiresHuman);
    }

    public function test_the_outage_fallback_can_be_switched_off(): void
    {
        config()->set('vendors.vendors.plasmaguard.pricing.shipping.flat_amount', 4200);
        config()->set('vendors.vendors.plasmaguard.pricing.shipping.fallback_on_outage', false);
        $this->fakeOutage();

        $this->assertFalse($this->rater()->quote(self::ORIGIN, self::DEST, self::PARCEL, 1)->isQuotable());
    }

    public function test_failed_authentication_does_not_produce_a_rate(): void
    {
        Http::fake(['*/oauth/token' => Http::response(['errors' => [['message' => 'bad creds']]], 401)]);

        $this->assertFalse($this->rater()->quote(self::ORIGIN, self::DEST, self::PARCEL, 1)->isQuotable());
    }

    public function test_a_freight_sized_order_never_calls_fedex(): void
    {
        Http::fake();

        $quote = $this->rater()->quote(self::ORIGIN, self::DEST, self::PARCEL, 12);

        $this->assertSame(ShippingQuote::SOURCE_FREIGHT, $quote->source);
        Http::assertNothingSent();
    }

    public function test_the_cheapest_eligible_service_wins(): void
    {
        Http::fake([
            '*/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            '*/rate/*'      => Http::response(['output' => ['rateReplyDetails' => [
                $this->service('FEDEX_GROUND', 'FedEx Ground', 31.10, 48.00),
                $this->service('GROUND_HOME_DELIVERY', 'FedEx Home Delivery', 24.31, 41.80),
            ]]]),
        ]);

        $this->assertSame(2431, $this->rater()->quote(self::ORIGIN, self::DEST, self::PARCEL, 1)->amount);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function rater(): FedExShippingRater
    {
        return new FedExShippingRater('plasmaguard');
    }

    private function fakeOutage(): void
    {
        Http::fake([
            '*/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            '*/rate/*'      => Http::response(['errors' => [['code' => 'SERVICE.UNAVAILABLE.ERROR']]], 503),
        ]);
    }

    private function fakeFedEx(array $rateBody): void
    {
        Http::fake([
            '*/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            '*/rate/*'      => Http::response($rateBody),
        ]);
    }

    /** @return array<string,mixed> */
    private function rateReply(float $account, float $list): array
    {
        return ['output' => ['rateReplyDetails' => [
            $this->service('FEDEX_GROUND', 'FedEx Ground', $account, $list),
        ]]];
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
