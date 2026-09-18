<?php

namespace Tests\Feature\Api;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The lookup behind a partner's personal website (q3.life/{code}). It must
 * agree with /join/{code} on which codes are live, and hand out nothing the
 * join page doesn't already show.
 */
class PublicSponsorLookupTest extends TestCase
{
    use RefreshDatabase;

    private function partner(array $attributes = []): User
    {
        $role = Role::firstOrCreate(['name' => Role::FREE_MEMBER], [
            'display_name' => 'Free Member',
            'is_admin' => false,
            'level' => 1,
        ]);

        return User::factory()->create($attributes + ['role_id' => $role->id, 'name' => 'Jane Partner']);
    }

    public function test_a_live_code_returns_the_sponsor_name_and_nothing_else(): void
    {
        $partner = $this->partner();

        $this->getJson("/api/sponsors/{$partner->referral_code}")
            ->assertOk()
            ->assertExactJson(['code' => $partner->referral_code, 'name' => 'Jane Partner']);
    }

    public function test_a_code_typed_in_lower_case_still_finds_the_partner(): void
    {
        $partner = $this->partner();

        $this->getJson('/api/sponsors/'.strtolower($partner->referral_code))
            ->assertOk()
            ->assertJsonPath('code', $partner->referral_code);
    }

    public function test_an_unknown_code_is_a_404(): void
    {
        $this->getJson('/api/sponsors/ZZZZ9999')->assertNotFound();
    }

    public function test_a_merged_position_is_not_offered_as_a_sponsor(): void
    {
        // Same rule as the join page: a Join button for this code would 404.
        $partner = $this->partner(['account_status' => User::ACCOUNT_MERGED]);

        $this->getJson("/api/sponsors/{$partner->referral_code}")->assertNotFound();
        $this->get(route('join', $partner->referral_code))->assertNotFound();
    }

    public function test_members_are_shown_their_website_address(): void
    {
        config(['registration.site_url' => 'https://q3.life/']);
        $partner = $this->partner(['billing_exempt' => true]);
        $site = "https://q3.life/{$partner->referral_code}";

        $this->actingAs($partner)->get(route('member.referrals'))->assertOk()->assertSee($site);
        $this->actingAs($partner)->get(route('member.dashboard'))->assertOk()->assertSee($site);
    }

    public function test_the_company_website_may_call_it_cross_origin(): void
    {
        $partner = $this->partner();

        $this->getJson("/api/sponsors/{$partner->referral_code}", ['Origin' => 'https://q3.life'])
            ->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', 'https://q3.life');
    }
}
