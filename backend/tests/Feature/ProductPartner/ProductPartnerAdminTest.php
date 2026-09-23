<?php

namespace Tests\Feature\ProductPartner;

use App\Models\ProductPartnerAssignment;
use App\Models\ProductPartnerPayment;
use App\Models\Role;
use App\Models\User;
use App\Models\VendorLead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Our side: granting a vendor sight of our pipeline, and taking it away.
 *
 * Granting one of these lets an outside company see how our partners are
 * selling, so it is a super-admin action rather than a support one, and the
 * screens have to make the state of an account obvious — particularly the two
 * half-configured states, which is where a support call comes from.
 */
class ProductPartnerAdminTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $support;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => Role::SUPER_ADMIN],
            ['display_name' => 'Super Admin', 'is_admin' => true, 'level' => 99]);
        Role::firstOrCreate(['name' => Role::SUPPORT_ADMIN],
            ['display_name' => 'Support Admin', 'is_admin' => true, 'level' => 10]);
        Role::firstOrCreate(['name' => Role::FREE_MEMBER],
            ['display_name' => 'Free Member', 'is_admin' => false, 'level' => 0]);

        $this->admin = User::factory()->create([
            'role_id' => Role::findByName(Role::SUPER_ADMIN)->id, 'is_active' => true,
        ]);
        $this->support = User::factory()->create([
            'role_id' => Role::findByName(Role::SUPPORT_ADMIN)->id, 'is_active' => true,
        ]);
    }

    public function test_the_partner_list_flags_an_account_with_no_products_linked(): void
    {
        // Role set, nothing linked. The account can sign in and sees a holding
        // page, which looks like a bug unless the admin screen says so.
        $this->partner();

        $this->actingAs($this->admin)
            ->get(route('admin.product-partners.index'))
            ->assertOk()
            ->assertSee('No products linked');
    }

    public function test_a_super_admin_can_link_a_vendors_products_to_an_account(): void
    {
        $partner = $this->partner();

        $this->actingAs($this->admin)
            ->post(route('admin.users.product-partner.add', $partner), [
                'vendor'      => 'plasmaguard',
                'product_key' => ProductPartnerAssignment::ALL_PRODUCTS,
            ])->assertRedirect();

        $this->assertDatabaseHas('product_partner_assignments', [
            'user_id'            => $partner->id,
            'vendor'             => 'plasmaguard',
            'product_key'        => '*',
            'granted_by_user_id' => $this->admin->id,
        ]);
    }

    public function test_linking_the_same_products_twice_does_not_duplicate_the_grant(): void
    {
        $partner = $this->partner();

        foreach ([1, 2] as $ignored) {
            $this->actingAs($this->admin)->post(route('admin.users.product-partner.add', $partner), [
                'vendor' => 'plasmaguard', 'product_key' => '*',
            ]);
        }

        $this->assertSame(1, ProductPartnerAssignment::where('user_id', $partner->id)->count());
    }

    public function test_a_product_outside_the_vendors_registry_is_refused(): void
    {
        // A typo would otherwise create a grant that silently matches nothing.
        $partner = $this->partner();

        $this->actingAs($this->admin)
            ->post(route('admin.users.product-partner.add', $partner), [
                'vendor' => 'plasmaguard', 'product_key' => 'not-a-real-sku',
            ])->assertSessionHasErrors('product_key');

        $this->assertSame(0, ProductPartnerAssignment::count());
    }

    public function test_support_admins_cannot_grant_access(): void
    {
        $partner = $this->partner();

        $this->actingAs($this->support)
            ->post(route('admin.users.product-partner.add', $partner), [
                'vendor' => 'plasmaguard', 'product_key' => '*',
            ])->assertForbidden();

        $this->assertSame(0, ProductPartnerAssignment::count());
    }

    public function test_support_admins_can_still_see_the_list(): void
    {
        $this->actingAs($this->support)
            ->get(route('admin.product-partners.index'))
            ->assertOk();
    }

    public function test_a_grant_cannot_be_revoked_through_another_users_url(): void
    {
        $partner = $this->partner();
        $other   = $this->partner();

        $grant = ProductPartnerAssignment::create([
            'user_id' => $partner->id, 'vendor' => 'plasmaguard', 'product_key' => '*',
        ]);

        $this->actingAs($this->admin)
            ->delete(route('admin.users.product-partner.remove', [$other, $grant]))
            ->assertNotFound();

        $this->assertModelExists($grant);
    }

    public function test_revoking_a_grant_closes_the_portal_to_that_account(): void
    {
        $partner = $this->partner();
        $grant = ProductPartnerAssignment::create([
            'user_id' => $partner->id, 'vendor' => 'plasmaguard', 'product_key' => '*',
        ]);

        $this->actingAs($this->admin)
            ->delete(route('admin.users.product-partner.remove', [$partner, $grant]))
            ->assertRedirect();

        $this->actingAs($partner)
            ->get(route('product-partner.dashboard'))
            ->assertOk()
            ->assertSee('no products are linked to it yet', false);
    }

    public function test_the_user_page_offers_the_grant_form_only_for_a_product_partner(): void
    {
        $partner = $this->partner();
        $member  = User::factory()->create([
            'role_id' => Role::findByName(Role::FREE_MEMBER)->id, 'is_active' => true,
        ]);

        $this->actingAs($this->admin)->get(route('admin.users.show', $partner))
            ->assertOk()
            ->assertSee('Product Partner access')
            ->assertSee('All products');

        // A form that silently achieves nothing is worse than no form: linking
        // products to a member does not make them a product partner.
        $this->actingAs($this->admin)->get(route('admin.users.show', $member))
            ->assertOk()
            ->assertSee('Product Partner access')
            ->assertSee('change their role to', false)
            ->assertDontSee('All products');
    }

    public function test_the_admin_panel_links_straight_into_the_partner_portal(): void
    {
        /*
         * Top level, beside Member Area. It was first shipped inside the
         * Vendor Orders submenu, where it may as well not have existed: a
         * section reachable only by expanding an unrelated menu is a section
         * nobody remembers is there.
         */
        $this->actingAs($this->admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Partner Portal')
            ->assertSee(route('product-partner.dashboard'));
    }

    public function test_the_payments_screen_lists_what_a_vendor_has_claimed(): void
    {
        ProductPartnerPayment::create([
            'vendor'    => 'plasmaguard',
            'amount'    => 600000,
            'paid_on'   => now()->toDateString(),
            'reference' => 'WIRE-4471',
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.product-partners.payments'))
            ->assertOk()
            ->assertSee('WIRE-4471')
            ->assertSee('Pending');
    }

    public function test_the_dashboard_renders_with_real_trading(): void
    {
        $partner = $this->partner();
        ProductPartnerAssignment::create([
            'user_id' => $partner->id, 'vendor' => 'plasmaguard', 'product_key' => '*',
        ]);

        $member = User::factory()->create([
            'role_id' => Role::findByName(Role::FREE_MEMBER)->id, 'is_active' => true,
        ]);

        $sold = $this->order(['member_id' => $member->id, 'quantity' => 3]);
        $this->order(['status' => VendorLead::STATUS_HANDED_OFF, 'converted_at' => null]);

        $response = $this->actingAs($partner)->get(route('product-partner.dashboard'));

        $response->assertOk()->assertSee($sold->public_ref);

        $stats = $response->viewData('stats');
        $this->assertSame(3, $stats['sales']['units']);
        $this->assertSame(2, $stats['prospects']['total']);
        $this->assertSame(1, $stats['prospects']['open']);
        $this->assertSame(1, $stats['sales_force']['selling']);
        $this->assertCount(30, $response->viewData('activity'));
    }

    public function test_the_csv_export_covers_only_the_granted_vendor(): void
    {
        $partner = $this->partner();
        ProductPartnerAssignment::create([
            'user_id' => $partner->id, 'vendor' => 'plasmaguard', 'product_key' => '*',
        ]);

        config(['vendors.vendors.otherco' => ['name' => 'OtherCo', 'enabled' => true, 'products' => []]]);

        $mine   = $this->order();
        $theirs = $this->order(['vendor' => 'otherco', 'product_key' => 'widget']);

        $csv = $this->actingAs($partner)
            ->get(route('product-partner.sales.export'))
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString($mine->public_ref, $csv);
        $this->assertStringNotContainsString($theirs->public_ref, $csv);
    }

    private function partner(): User
    {
        return User::factory()->create([
            'role_id'   => Role::findByName(Role::PRODUCT_PARTNER)->id,
            'is_active' => true,
        ]);
    }

    private function order(array $attributes = []): VendorLead
    {
        return VendorLead::create(array_merge([
            'public_ref'       => 'QLV-'.strtoupper(Str::random(10)),
            'vendor'           => 'plasmaguard',
            'product_key'      => 'pro-in-duct',
            'first_name'       => 'Dana',
            'email'            => 'dana@example.com',
            'quantity'         => 1,
            'status'           => VendorLead::STATUS_CONVERTED,
            'converted_at'     => now(),
            'our_share_amount' => 300000,
            'amount_total'     => 641400,
            'currency'         => 'USD',
        ], $attributes));
    }
}
