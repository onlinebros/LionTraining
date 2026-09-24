<?php

namespace Tests\Feature\Vendor;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The "Can you keep up?" game beside the product page. What matters here is
 * not the game but the round trip: it keeps the partner's code, it leads back
 * to ordering, and it only exists for products that opt in.
 */
class ChallengeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('vendors.vendors.plasmaguard.enabled', true);

        User::factory()->create(['referral_code' => 'PARTNER1', 'is_active' => true, 'name' => 'Pat Partner']);
    }

    public function test_the_challenge_renders_under_the_partners_code(): void
    {
        $this->get('/p/PARTNER1/plasmaguard/pro-in-duct/challenge')
            ->assertOk()
            ->assertSee('Can you keep up with the', false)
            ->assertSee('Pat Partner')
            ->assertSee('q3-challenge.js', false);
    }

    public function test_the_result_leads_back_to_the_same_partners_order_form(): void
    {
        $this->get('/p/PARTNER1/plasmaguard/pro-in-duct/challenge')
            ->assertOk()
            ->assertSee(route('vendor.product', ['PARTNER1', 'plasmaguard', 'pro-in-duct']).'#enquire', false);
    }

    public function test_the_product_page_invites_the_challenge(): void
    {
        $this->get('/p/PARTNER1/plasmaguard/pro-in-duct')
            ->assertOk()
            ->assertSee(route('vendor.challenge', ['PARTNER1', 'plasmaguard', 'pro-in-duct']), false);
    }

    public function test_it_says_it_is_a_game_not_a_result(): void
    {
        $this->get('/p/PARTNER1/plasmaguard/pro-in-duct/challenge')
            ->assertSee('A game, not a lab result');
    }

    public function test_a_product_that_has_not_opted_in_has_no_challenge(): void
    {
        config()->set('vendors.vendors.plasmaguard.products.pro-in-duct.challenge', false);

        $this->get('/p/PARTNER1/plasmaguard/pro-in-duct/challenge')->assertNotFound();
        $this->get('/p/PARTNER1/plasmaguard/pro-in-duct')
            ->assertOk()
            ->assertDontSee('/challenge', false);
    }

    public function test_an_unknown_or_inactive_partner_gets_no_challenge(): void
    {
        $this->get('/p/NOSUCHCODE/plasmaguard/pro-in-duct/challenge')->assertNotFound();

        User::where('referral_code', 'PARTNER1')->update(['is_active' => false]);
        $this->get('/p/PARTNER1/plasmaguard/pro-in-duct/challenge')->assertNotFound();
    }
}
