<?php

namespace Tests\Feature\ProductPartner;

use App\Models\ProductPartnerAssignment;
use App\Models\Role;
use App\Models\User;
use App\Models\VendorLead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Who gets into the vendor portal, and what they can see once they are in.
 *
 * This is the file that matters most in the feature. A product partner is an
 * outside company with a login to our back office, and every mistake here is
 * the same mistake: one vendor seeing another vendor's customers, or a vendor
 * seeing a prospect our partner has not closed yet.
 */
class ProductPartnerAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // free_member and super_admin are created per test; product_partner
        // comes from the migration, because production is migrated, not seeded.
        Role::firstOrCreate(['name' => Role::FREE_MEMBER],
            ['display_name' => 'Free Member', 'is_admin' => false, 'level' => 0]);
        Role::firstOrCreate(['name' => Role::SUPER_ADMIN],
            ['display_name' => 'Super Admin', 'is_admin' => true, 'level' => 99]);

        /*
         * A second vendor, registered only for the duration of a test. The
         * whole scoping question is meaningless with one vendor in the
         * registry, and the real config has exactly one.
         */
        config(['vendors.vendors.otherco' => [
            'name'     => 'OtherCo',
            'enabled'  => true,
            'products' => ['widget' => ['name' => 'Widget']],
        ]]);
    }

    public function test_the_role_exists_from_the_migration(): void
    {
        $role = Role::findByName(Role::PRODUCT_PARTNER);

        $this->assertNotNull($role, 'The product_partner role should be created by migration, not only by the seeder.');

        // is_admin must stay false: every existing admin check depends on it.
        $this->assertFalse($role->is_admin);
    }

    public function test_a_member_cannot_open_the_portal(): void
    {
        $this->actingAs($this->member())
            ->get(route('product-partner.dashboard'))
            ->assertForbidden();
    }

    public function test_a_signed_out_visitor_is_sent_to_log_in(): void
    {
        $this->get(route('product-partner.dashboard'))->assertRedirect(route('login'));
    }

    public function test_a_partner_with_no_products_linked_sees_the_holding_page(): void
    {
        // The account is fine; it is waiting on us. That has to read as
        // "pending", not as a permissions error.
        $this->actingAs($this->partner())
            ->get(route('product-partner.dashboard'))
            ->assertOk()
            ->assertSee('no products are linked to it yet', false);
    }

    public function test_a_partner_sees_only_their_own_vendors_orders(): void
    {
        $partner = $this->partner('plasmaguard');

        $mine    = $this->order(['email' => 'mine@example.com']);
        $theirs  = $this->order(['vendor' => 'otherco', 'product_key' => 'widget', 'email' => 'theirs@example.com']);

        $response = $this->actingAs($partner)->get(route('product-partner.sales'));

        $response->assertOk()
            ->assertSee($mine->public_ref)
            ->assertDontSee($theirs->public_ref)
            ->assertDontSee('theirs@example.com');
    }

    public function test_another_vendors_order_cannot_be_opened_by_id(): void
    {
        $partner = $this->partner('plasmaguard');
        $theirs  = $this->order(['vendor' => 'otherco', 'product_key' => 'widget']);

        // The list filters; a bare route model binding would not.
        $this->actingAs($partner)
            ->get(route('product-partner.sales.show', $theirs))
            ->assertNotFound();
    }

    public function test_a_grant_for_one_product_does_not_open_another(): void
    {
        $partner = $this->partner();

        ProductPartnerAssignment::create([
            'user_id' => $partner->id, 'vendor' => 'plasmaguard', 'product_key' => 'pro-in-duct',
        ]);

        $covered   = $this->order(['product_key' => 'pro-in-duct']);
        $uncovered = $this->order(['product_key' => 'some-other-sku']);

        $this->actingAs($partner)->get(route('product-partner.sales'))
            ->assertOk()
            ->assertSee($covered->public_ref)
            ->assertDontSee($uncovered->public_ref);
    }

    public function test_an_all_products_grant_is_not_narrowed_by_a_specific_one(): void
    {
        // Both rows exist, in either order. "All products" has to win — the
        // alternative silently revokes access to everything but one SKU.
        $partner = $this->partner();

        ProductPartnerAssignment::create([
            'user_id' => $partner->id, 'vendor' => 'plasmaguard', 'product_key' => 'pro-in-duct',
        ]);
        ProductPartnerAssignment::create([
            'user_id' => $partner->id, 'vendor' => 'plasmaguard',
            'product_key' => ProductPartnerAssignment::ALL_PRODUCTS,
        ]);

        $other = $this->order(['product_key' => 'some-other-sku']);

        $this->actingAs($partner)->get(route('product-partner.sales'))
            ->assertOk()
            ->assertSee($other->public_ref);
    }

    public function test_a_partner_holding_two_vendors_can_switch_between_them(): void
    {
        $partner = $this->partner('plasmaguard');

        ProductPartnerAssignment::create([
            'user_id' => $partner->id, 'vendor' => 'otherco',
            'product_key' => ProductPartnerAssignment::ALL_PRODUCTS,
        ]);

        $pg = $this->order();
        $oc = $this->order(['vendor' => 'otherco', 'product_key' => 'widget']);

        // Each view shows one vendor at a time, never the union.
        $this->actingAs($partner)->get(route('product-partner.sales', ['vendor' => 'otherco']))
            ->assertOk()
            ->assertSee($oc->public_ref)
            ->assertDontSee($pg->public_ref);

        $this->actingAs($partner)->get(route('product-partner.sales', ['vendor' => 'plasmaguard']))
            ->assertOk()
            ->assertSee($pg->public_ref)
            ->assertDontSee($oc->public_ref);
    }

    public function test_asking_for_a_vendor_they_do_not_hold_falls_back_to_one_they_do(): void
    {
        // A bookmark from a grant that has since been narrowed must not 403,
        // and must never quietly show the vendor they asked for.
        $partner = $this->partner('plasmaguard');
        $theirs  = $this->order(['vendor' => 'otherco', 'product_key' => 'widget']);
        $mine    = $this->order();

        $this->actingAs($partner)->get(route('product-partner.sales', ['vendor' => 'otherco']))
            ->assertOk()
            ->assertSee($mine->public_ref)
            ->assertDontSee($theirs->public_ref);
    }

    public function test_a_grant_for_a_vendor_no_longer_in_the_registry_grants_nothing(): void
    {
        $partner = $this->partner('otherco');

        // The commercial arrangement ended and the vendor was removed from
        // config. The row is still there; it must stop granting.
        config(['vendors.vendors.otherco' => null]);

        $this->actingAs($partner)
            ->get(route('product-partner.dashboard'))
            ->assertOk()
            ->assertSee('no products are linked to it yet', false);
    }

    // ── The privacy line: open prospects are counts, sales are records ────────

    public function test_an_open_prospect_is_not_listed_on_sales(): void
    {
        $partner = $this->partner('plasmaguard');

        $open = $this->order([
            'status'       => VendorLead::STATUS_HANDED_OFF,
            'converted_at' => null,
            'first_name'   => 'Unclosed',
            'email'        => 'unclosed@example.com',
        ]);

        $this->actingAs($partner)->get(route('product-partner.sales'))
            ->assertOk()
            ->assertDontSee('unclosed@example.com')
            ->assertDontSee($open->public_ref);
    }

    public function test_an_open_prospect_cannot_be_opened_by_id_either(): void
    {
        $partner = $this->partner('plasmaguard');

        $open = $this->order(['status' => VendorLead::STATUS_HANDED_OFF, 'converted_at' => null]);

        $this->actingAs($partner)
            ->get(route('product-partner.sales.show', $open))
            ->assertNotFound();
    }

    public function test_the_pipeline_page_counts_prospects_without_naming_them(): void
    {
        $partner = $this->partner('plasmaguard');

        $this->order(['status' => VendorLead::STATUS_HANDED_OFF, 'converted_at' => null,
            'first_name' => 'Priya', 'email' => 'priya@example.com']);
        $this->order(['status' => VendorLead::STATUS_NEW, 'converted_at' => null,
            'first_name' => 'Marcus', 'email' => 'marcus@example.com']);

        $response = $this->actingAs($partner)->get(route('product-partner.prospects'));

        $response->assertOk()
            ->assertDontSee('priya@example.com')
            ->assertDontSee('marcus@example.com')
            ->assertDontSee('Priya')
            ->assertDontSee('Marcus');

        $stats = $response->viewData('stats');
        $this->assertSame(2, $stats['prospects']['total']);
        $this->assertSame(2, $stats['prospects']['open']);
    }

    public function test_a_confirmed_order_shows_the_customer_in_full(): void
    {
        // Past the payment they are the merchant of record, shipping to this
        // address. Withholding it here would be theatre.
        $partner = $this->partner('plasmaguard');

        $order = $this->order([
            'first_name'    => 'Dana',
            'last_name'     => 'Okafor',
            'email'         => 'dana@example.com',
            'address_line1' => '400 Industrial Way',
        ]);

        $this->actingAs($partner)->get(route('product-partner.sales.show', $order))
            ->assertOk()
            ->assertSee('Dana Okafor')
            ->assertSee('dana@example.com')
            ->assertSee('400 Industrial Way');
    }

    // ── Isolation from the rest of the application ───────────────────────────

    public function test_a_product_partner_is_kept_out_of_the_member_area(): void
    {
        /*
         * The failure this prevents is not a 403 — it is the subscription gate
         * sending a vendor to a card capture screen for a membership nobody
         * sold them.
         */
        $this->actingAs($this->partner('plasmaguard'))
            ->get(route('member.dashboard'))
            ->assertRedirect(route('product-partner.dashboard'));
    }

    public function test_a_product_partner_is_kept_out_of_the_admin_area(): void
    {
        $this->actingAs($this->partner('plasmaguard'))
            ->get(route('admin.dashboard'))
            ->assertRedirect(route('product-partner.dashboard'));
    }

    public function test_logging_in_lands_a_product_partner_in_their_own_section(): void
    {
        $partner = $this->partner('plasmaguard');
        $partner->forceFill(['password' => 'secret-password'])->save();

        $this->post(route('login.post'), [
            'email'    => $partner->email,
            'password' => 'secret-password',
        ])->assertRedirect(route('product-partner.dashboard'));
    }

    public function test_an_admin_can_look_at_the_portal(): void
    {
        // Answering a question about what the vendor sees should not require
        // borrowing their login.
        $this->actingAs($this->admin())
            ->get(route('product-partner.dashboard'))
            ->assertOk()
            ->assertSee('signed in as an administrator', false);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function member(): User
    {
        return User::factory()->create([
            'role_id'   => Role::findByName(Role::FREE_MEMBER)->id,
            'is_active' => true,
        ]);
    }

    private function admin(): User
    {
        return User::factory()->create([
            'role_id'   => Role::findByName(Role::SUPER_ADMIN)->id,
            'is_active' => true,
        ]);
    }

    /** A product partner, optionally granted every product of one vendor. */
    private function partner(?string $vendor = null): User
    {
        $user = User::factory()->create([
            'role_id'   => Role::findByName(Role::PRODUCT_PARTNER)->id,
            'is_active' => true,
        ]);

        if ($vendor !== null) {
            ProductPartnerAssignment::create([
                'user_id'     => $user->id,
                'vendor'      => $vendor,
                'product_key' => ProductPartnerAssignment::ALL_PRODUCTS,
            ]);
        }

        return $user;
    }

    private function order(array $attributes = []): VendorLead
    {
        return VendorLead::create(array_merge([
            'public_ref'       => 'QLV-'.strtoupper(Str::random(10)),
            'vendor'           => 'plasmaguard',
            'product_key'      => 'pro-in-duct',
            'first_name'       => 'Dana',
            'last_name'        => 'Okafor',
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
