<?php

namespace Tests\Feature\ProductPartner;

use App\Models\ProductPartnerAssignment;
use App\Models\Role;
use App\Models\User;
use App\Models\UserOpportunity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Choosing where you land after signing in.
 *
 * Only meaningful for accounts holding more than one section — an admin, or a
 * product partner who also sells. Everyone else has one place to be and is
 * never shown the setting, because a choice of one is worse than no choice.
 */
class LandingPreferenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => Role::SUPER_ADMIN],
            ['display_name' => 'Super Admin', 'is_admin' => true, 'level' => 99]);
        Role::firstOrCreate(['name' => Role::FREE_MEMBER],
            ['display_name' => 'Free Member', 'is_admin' => false, 'level' => 0]);
    }

    public function test_an_ordinary_member_is_offered_no_choice(): void
    {
        // On the card-free business line, so the dashboard is actually
        // reachable — a member on the training line without a subscription is
        // sent to billing, which is a different rule and not what is under
        // test here.
        $member = User::factory()->create([
            'role_id' => Role::findByName(Role::FREE_MEMBER)->id, 'is_active' => true,
        ]);
        $member->associateOpportunity('plasmaguard', UserOpportunity::SOURCE_ADMIN, primary: true);

        $this->assertSame(['member' => 'Member area'], $member->landingOptions());

        $this->actingAs($member)->get(route('member.dashboard'))
            ->assertOk()
            ->assertDontSee('Land here when I sign in');
    }

    public function test_an_admin_is_offered_all_three(): void
    {
        $admin = $this->admin();

        $this->assertSame(
            ['admin' => 'Admin panel', 'member' => 'Member area', 'portal' => 'Partner portal'],
            $admin->landingOptions(),
        );

        $this->actingAs($admin)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Land here when I sign in');
    }

    public function test_defaults_hold_when_nothing_has_been_chosen(): void
    {
        $this->assertSame(route('admin.dashboard'), $this->admin()->landingRoute());
        $this->assertSame(route('product-partner.dashboard'), $this->dualPartner(sell: false)->landingRoute());

        $member = User::factory()->create([
            'role_id' => Role::findByName(Role::FREE_MEMBER)->id, 'is_active' => true,
        ]);
        $this->assertSame(route('member.dashboard'), $member->landingRoute());
    }

    public function test_an_admin_can_choose_to_land_in_the_member_area(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post(route('preferences.landing'), ['landing' => 'member'])
            ->assertRedirect();

        $this->assertSame('member', $admin->refresh()->landing_preference);
        $this->assertSame(route('member.dashboard'), $admin->landingRoute());
    }

    public function test_a_dual_partner_can_choose_the_member_area(): void
    {
        $partner = $this->dualPartner();

        $this->actingAs($partner)
            ->post(route('preferences.landing'), ['landing' => 'member'])
            ->assertRedirect();

        $this->assertSame(route('member.dashboard'), $partner->refresh()->landingRoute());
    }

    public function test_logging_in_honours_the_choice(): void
    {
        $partner = $this->dualPartner();
        $partner->forceFill(['landing_preference' => 'member', 'password' => 'secret-password'])->save();

        $this->post(route('login.post'), [
            'email' => $partner->email, 'password' => 'secret-password',
        ])->assertRedirect(route('member.dashboard'));
    }

    public function test_a_section_they_cannot_reach_is_refused(): void
    {
        $member = User::factory()->create([
            'role_id' => Role::findByName(Role::FREE_MEMBER)->id, 'is_active' => true,
        ]);

        $this->actingAs($member)
            ->post(route('preferences.landing'), ['landing' => 'admin'])
            ->assertSessionHasErrors('landing');

        $this->assertNull($member->refresh()->landing_preference);
    }

    public function test_a_preference_for_a_section_since_lost_is_ignored(): void
    {
        /*
         * Access is taken away after the choice was made. Obeying it would
         * strand them on a redirect to a page that bounces them straight back.
         */
        $partner = $this->dualPartner();
        $partner->forceFill(['landing_preference' => 'member'])->save();

        $partner->opportunityAssociations()->delete();
        $partner->forceFill(['primary_opportunity' => null])->save();
        $partner->unsetRelation('opportunityAssociations');

        $this->assertSame(route('product-partner.dashboard'), $partner->landingRoute());
    }

    public function test_the_legacy_dashboard_redirect_follows_the_choice(): void
    {
        $admin = $this->admin();
        $admin->forceFill(['landing_preference' => 'portal'])->save();

        $this->actingAs($admin)->get('/dashboard')
            ->assertRedirect(route('product-partner.dashboard'));
    }

    private function admin(): User
    {
        return User::factory()->create([
            'role_id' => Role::findByName(Role::SUPER_ADMIN)->id, 'is_active' => true,
        ]);
    }

    /** A product partner, optionally also on the business line that sells it. */
    private function dualPartner(bool $sell = true): User
    {
        $user = User::factory()->create([
            'role_id' => Role::findByName(Role::PRODUCT_PARTNER)->id, 'is_active' => true,
        ]);

        ProductPartnerAssignment::create([
            'user_id'     => $user->id,
            'vendor'      => 'plasmaguard',
            'product_key' => ProductPartnerAssignment::ALL_PRODUCTS,
        ]);

        if ($sell) {
            $user->associateOpportunity('plasmaguard', UserOpportunity::SOURCE_ADMIN, primary: true);
        }

        return $user;
    }
}
