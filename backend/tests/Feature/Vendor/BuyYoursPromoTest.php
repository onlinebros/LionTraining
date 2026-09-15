<?php

namespace Tests\Feature\Vendor;

use App\Models\User;
use App\Models\VendorLead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The launch special is marketed while qualifying places remain, and the offer
 * disappears everywhere once they are gone.
 */
class BuyYoursPromoTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'promotions.promotions.plasmaguard-pro-first-100';

    private User $sponsor;

    private User $partner;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('vendors.vendors.plasmaguard.enabled', true);
        config()->set(self::KEY.'.enabled', true);
        config()->set(self::KEY.'.cap', 5);
        config()->set(self::KEY.'.starts_at', '2026-09-01 00:00:00');
        config()->set(self::KEY.'.ends_at', null);

        $this->sponsor = User::factory()->create(['name' => 'Sam Sponsor', 'billing_exempt' => true]);
        $this->partner = User::factory()->create([
            'name' => 'Pat Partner', 'sponsor_id' => $this->sponsor->id, 'billing_exempt' => true,
        ]);
    }

    public function test_the_special_is_marketed_across_the_back_office_while_places_remain(): void
    {
        $this->sale(2);

        $buyUrl = route('member.sales.buy', ['plasmaguard', 'pro-in-duct']);

        $this->actingAs($this->partner)->get('/member/dashboard')
            ->assertOk()
            ->assertSee('Buy one of the first 5 and get the launch special')
            ->assertSee('2 of 5 claimed')
            ->assertSee($buyUrl, false)
            // The sidebar, on the same page.
            ->assertSee('Buy Yours')
            ->assertSee('3 left for the special');

        $this->actingAs($this->partner)->get('/member/sales')
            ->assertOk()
            ->assertSee('Buy one of the first 5 and get the launch special');

        $this->actingAs($this->partner)->get('/member/sales/promotion')
            ->assertOk()
            ->assertSee('3 left for the launch special.')
            ->assertSee($buyUrl, false);
    }

    public function test_the_copy_says_more_systems_follow_but_only_the_first_qualify(): void
    {
        // More than the capped number will be sold; the special is what is
        // limited, not the product.
        $this->actingAs($this->partner)->get('/member/dashboard')
            ->assertOk()
            ->assertSee('The launch special is only for the first 5')
            ->assertSee('More will be available after that');
    }

    public function test_the_offer_names_no_reward_and_says_who_is_paid(): void
    {
        $this->actingAs($this->partner)->get('/member/dashboard')
            ->assertOk()
            ->assertSee('Ask your sponsor for the details of the special.')
            ->assertSee('The commission on your own purchase goes to your sponsor.');

        // A partner with no sponsor is not told to ask one.
        $this->actingAs($this->sponsor)->get('/member/dashboard')
            ->assertOk()
            ->assertDontSee('Ask your sponsor')
            ->assertSee('No commission is paid on your own purchase.');
    }

    public function test_the_offer_disappears_once_every_place_is_taken(): void
    {
        $this->sale(5);

        $this->actingAs($this->partner)->get('/member/dashboard')
            ->assertOk()
            ->assertDontSee('get the launch special')
            ->assertDontSee('Buy Yours');

        // Buying for yourself is still possible after the special is over.
        $this->actingAs($this->partner)->get('/member/sales')
            ->assertOk()
            ->assertDontSee('get the launch special')
            ->assertSee('All 5 places are filled.')
            ->assertSee('Buy for yourself');
    }

    public function test_the_offer_is_not_shown_when_the_vendor_is_switched_off(): void
    {
        config()->set('vendors.vendors.plasmaguard.enabled', false);

        $this->actingAs($this->partner)->get('/member/dashboard')
            ->assertOk()
            ->assertDontSee('get the launch special')
            ->assertDontSee('Buy Yours');
    }

    private function sale(int $units): void
    {
        $lead = VendorLead::create([
            'public_ref'   => 'QLV-'.strtoupper(Str::random(10)),
            'vendor'       => 'plasmaguard',
            'product_key'  => 'pro-in-duct',
            'member_id'    => $this->sponsor->id,
            'first_name'   => 'Dana',
            'email'        => 'dana@example.com',
            'quantity'     => $units,
            'status'       => VendorLead::STATUS_CONVERTED,
            'converted_at' => now(),
        ]);

        $lead->forceFill(['credited_member_id' => $this->sponsor->id, 'earner_id' => $this->sponsor->id])->save();
    }
}
