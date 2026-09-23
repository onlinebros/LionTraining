<?php

namespace Tests\Feature\ProductPartner;

use App\Models\ProductPartnerAssignment;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A vendor's sales people selling for us as well as watching their numbers.
 *
 * The rule: a product partner reaches the member area only once somebody has
 * put them on a business line. That is what separates a vendor's sales people
 * from their accountant, and it is deliberately a second, explicit act.
 *
 * The failure this file mostly exists to prevent is subtler than a 403. Every
 * account with no business line recorded reads as the TRAINING line, which
 * requires a card — so getting this wrong does not lock a vendor out, it walks
 * them into a card capture screen for a $49.99 membership nobody sold them.
 */
class ProductPartnerDualAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => Role::SUPER_ADMIN],
            ['display_name' => 'Super Admin', 'is_admin' => true, 'level' => 99]);
        Role::firstOrCreate(['name' => Role::FREE_MEMBER],
            ['display_name' => 'Free Member', 'is_admin' => false, 'level' => 0]);

        $this->admin = User::factory()->create([
            'role_id' => Role::findByName(Role::SUPER_ADMIN)->id, 'is_active' => true,
        ]);
    }

    public function test_a_portal_only_partner_is_still_kept_out_of_the_member_area(): void
    {
        $this->actingAs($this->partner())
            ->get(route('member.dashboard'))
            ->assertRedirect(route('product-partner.dashboard'));
    }

    public function test_granting_member_access_opens_the_back_office(): void
    {
        $partner = $this->partner();

        $this->actingAs($this->admin)
            ->post(route('admin.users.product-partner.member-access.add', $partner))
            ->assertRedirect();

        $this->actingAs($partner->refresh())
            ->get(route('member.dashboard'))
            ->assertOk();
    }

    public function test_granting_member_access_never_asks_them_for_a_card(): void
    {
        /*
         * The whole point. The business line has to land as PRIMARY, because a
         * non-primary line leaves the default training line in place and the
         * card gate armed.
         */
        $partner = $this->partner();

        $this->actingAs($this->admin)
            ->post(route('admin.users.product-partner.member-access.add', $partner));

        $partner->refresh();

        $this->assertSame('plasmaguard', $partner->primary_opportunity);
        $this->assertFalse($partner->requiresMembership(), 'A vendor must never be asked for a card.');

        // The redirect a card gate would produce, asserted by its absence.
        $this->actingAs($partner)
            ->get(route('member.dashboard'))
            ->assertOk()
            ->assertDontSee(route('member.billing.start'));
    }

    public function test_they_can_sell_and_carry_a_referral_code(): void
    {
        $partner = $this->partner();

        $this->actingAs($this->admin)
            ->post(route('admin.users.product-partner.member-access.add', $partner));

        $partner->refresh();

        $this->assertNotEmpty($partner->referral_code);
        $this->assertTrue($partner->canSee('product-sales'));
        $this->assertTrue($partner->canSee('commissions'));

        // ...and not the training library, which is not what they were sold.
        $this->assertFalse($partner->canSee('training'));

        $this->actingAs($partner)->get(route('member.sales.index'))->assertOk();
    }

    public function test_a_dual_partner_is_still_never_an_admin(): void
    {
        $partner = $this->partner();
        $this->actingAs($this->admin)
            ->post(route('admin.users.product-partner.member-access.add', $partner));

        $this->actingAs($partner->refresh())
            ->get(route('admin.dashboard'))
            ->assertRedirect(route('product-partner.dashboard'));
    }

    public function test_each_side_links_to_the_other(): void
    {
        $partner = $this->partner();
        $this->actingAs($this->admin)
            ->post(route('admin.users.product-partner.member-access.add', $partner));

        $partner->refresh();

        // Holding two back offices with no way between them is worse than
        // holding one.
        $this->actingAs($partner)->get(route('product-partner.dashboard'))
            ->assertOk()->assertSee('Member Area');

        $this->actingAs($partner)->get(route('member.dashboard'))
            ->assertOk()->assertSee('Partner Portal');
    }

    public function test_revoking_member_access_leaves_the_portal_intact(): void
    {
        $partner = $this->partner();
        $this->actingAs($this->admin)
            ->post(route('admin.users.product-partner.member-access.add', $partner));

        $this->actingAs($this->admin)
            ->delete(route('admin.users.product-partner.member-access.remove', $partner))
            ->assertRedirect();

        $partner->refresh();

        $this->assertFalse($partner->canUseMemberArea());
        $this->assertNull($partner->primary_opportunity);

        $this->actingAs($partner)->get(route('member.dashboard'))
            ->assertRedirect(route('product-partner.dashboard'));
        $this->actingAs($partner)->get(route('product-partner.dashboard'))->assertOk();
    }

    public function test_member_access_is_refused_when_no_line_sells_that_vendor(): void
    {
        // A configuration gap, not a user error: say so rather than silently
        // putting them on the training line and asking for a card.
        config(['vendors.vendors.otherco' => ['name' => 'OtherCo', 'enabled' => true, 'products' => []]]);

        $partner = $this->partner('otherco');

        $this->actingAs($this->admin)
            ->post(route('admin.users.product-partner.member-access.add', $partner))
            ->assertSessionHasErrors('error');

        $this->assertFalse($partner->refresh()->canUseMemberArea());
    }

    public function test_support_admins_cannot_let_a_vendor_sell(): void
    {
        Role::firstOrCreate(['name' => Role::SUPPORT_ADMIN],
            ['display_name' => 'Support Admin', 'is_admin' => true, 'level' => 10]);

        $support = User::factory()->create([
            'role_id' => Role::findByName(Role::SUPPORT_ADMIN)->id, 'is_active' => true,
        ]);
        $partner = $this->partner();

        $this->actingAs($support)
            ->post(route('admin.users.product-partner.member-access.add', $partner))
            ->assertForbidden();

        $this->assertFalse($partner->refresh()->canUseMemberArea());
    }

    private function partner(string $vendor = 'plasmaguard'): User
    {
        $user = User::factory()->create([
            'role_id'   => Role::findByName(Role::PRODUCT_PARTNER)->id,
            'is_active' => true,
        ]);

        ProductPartnerAssignment::create([
            'user_id'     => $user->id,
            'vendor'      => $vendor,
            'product_key' => ProductPartnerAssignment::ALL_PRODUCTS,
        ]);

        return $user;
    }
}
