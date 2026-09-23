<?php

namespace Tests\Feature\ProductPartner;

use App\Models\ProductPartnerAssignment;
use App\Models\ProductPartnerPayment;
use App\Models\Role;
use App\Models\User;
use App\Models\VendorLead;
use App\Support\ProductPartner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * An admin looking through a product partner's eyes.
 *
 * The distinction this file exists to hold: an admin can always open the
 * portal, but their own view holds every vendor, so it answers "does the portal
 * work" and not "what does PlasmaGuard see". "View as" answers the second, and
 * is only honest if the scoping really follows the partner rather than the
 * admin — including into the awkward states, and including the refusal to write
 * anything while it is on.
 */
class ProductPartnerViewAsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

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

        config(['vendors.vendors.otherco' => [
            'name'     => 'OtherCo',
            'enabled'  => true,
            'products' => ['widget' => ['name' => 'Widget']],
        ]]);
    }

    public function test_an_admin_opening_the_portal_as_themselves_sees_every_vendor(): void
    {
        $pg = $this->order();
        $oc = $this->order(['vendor' => 'otherco', 'product_key' => 'widget']);

        $response = $this->actingAs($this->admin)->get(route('product-partner.sales'));

        $response->assertOk()
            // Said on the page, so a screenshot from this session is not
            // mistaken for the vendor's own screen.
            ->assertSee('seeing <strong>every</strong> vendor', false);

        // Two vendors in the registry means a switcher rather than one view.
        $this->assertNotEmpty($response->viewData('vendorSwitch'));
    }

    public function test_viewing_as_a_partner_narrows_the_portal_to_their_vendor(): void
    {
        $partner = $this->partner('plasmaguard');

        $mine   = $this->order();
        $theirs = $this->order(['vendor' => 'otherco', 'product_key' => 'widget']);

        $this->actingAs($this->admin)
            ->post(route('admin.product-partners.view-as', $partner))
            ->assertRedirect(route('product-partner.dashboard'));

        $this->actingAs($this->admin)->get(route('product-partner.sales'))
            ->assertOk()
            ->assertSee($mine->public_ref)
            // The admin holds OtherCo; the partner does not. The partner wins.
            ->assertDontSee($theirs->public_ref)
            ->assertSee('Viewing as');
    }

    public function test_viewing_as_narrows_to_a_single_product_too(): void
    {
        $partner = $this->partner();

        ProductPartnerAssignment::create([
            'user_id' => $partner->id, 'vendor' => 'plasmaguard', 'product_key' => 'pro-in-duct',
        ]);

        $covered   = $this->order(['product_key' => 'pro-in-duct']);
        $uncovered = $this->order(['product_key' => 'some-other-sku']);

        $this->actingAs($this->admin)->post(route('admin.product-partners.view-as', $partner));

        $this->actingAs($this->admin)->get(route('product-partner.sales'))
            ->assertOk()
            ->assertSee($covered->public_ref)
            ->assertDontSee($uncovered->public_ref);
    }

    public function test_the_statement_totals_follow_the_partner_not_the_admin(): void
    {
        $partner = $this->partner('plasmaguard');

        $this->order(['our_share_amount' => 300000]);
        $this->order(['vendor' => 'otherco', 'product_key' => 'widget', 'our_share_amount' => 999900]);

        $this->actingAs($this->admin)->post(route('admin.product-partners.view-as', $partner));

        $totals = $this->actingAs($this->admin)
            ->get(route('product-partner.statement'))->viewData('totals');

        $this->assertSame(300000, $totals['earned'], 'OtherCo money must not appear in PlasmaGuard\'s statement.');
    }

    public function test_viewing_as_a_partner_with_nothing_linked_shows_their_holding_page(): void
    {
        // The state most worth being able to reproduce, and the one an admin's
        // own view can never show them.
        $partner = $this->partner();

        $this->actingAs($this->admin)->post(route('admin.product-partners.view-as', $partner));

        $this->actingAs($this->admin)->get(route('product-partner.dashboard'))
            ->assertOk()
            ->assertSee('no products are linked to it yet', false)
            // ...with a way back, since that page has no navigation of its own.
            ->assertSee('Stop viewing as them');
    }

    public function test_stopping_returns_the_admin_to_their_own_view(): void
    {
        $partner = $this->partner('plasmaguard');
        $theirs  = $this->order(['vendor' => 'otherco', 'product_key' => 'widget']);

        $this->actingAs($this->admin)->post(route('admin.product-partners.view-as', $partner));
        $this->actingAs($this->admin)->post(route('admin.product-partners.stop-viewing'))
            ->assertRedirect(route('admin.product-partners.index'));

        $this->actingAs($this->admin)
            ->get(route('product-partner.sales', ['vendor' => 'otherco']))
            ->assertOk()
            ->assertSee($theirs->public_ref);
    }

    public function test_nothing_can_be_written_while_viewing_as_someone(): void
    {
        /*
         * A payment filed here would read as the vendor's claim and would not
         * be one, on the one screen whose whole purpose is that both companies
         * trust the same numbers.
         */
        $partner = $this->partner('plasmaguard');
        $this->order(['our_share_amount' => 600000]);

        $this->actingAs($this->admin)->post(route('admin.product-partners.view-as', $partner));

        $this->actingAs($this->admin)
            ->post(route('product-partner.statement.payments'), [
                'amount'  => '6000.00',
                'paid_on' => now()->toDateString(),
            ])
            ->assertSessionHasErrors('error');

        $this->assertSame(0, ProductPartnerPayment::count());
    }

    public function test_a_member_cannot_be_viewed_as(): void
    {
        $member = User::factory()->create([
            'role_id' => Role::findByName(Role::FREE_MEMBER)->id, 'is_active' => true,
        ]);

        $this->actingAs($this->admin)
            ->post(route('admin.product-partners.view-as', $member))
            ->assertSessionHasErrors('error');

        $this->assertNull(session(ProductPartner::VIEW_AS));
    }

    public function test_a_product_partner_cannot_view_as_anyone(): void
    {
        $one = $this->partner('plasmaguard');
        $two = $this->partner('otherco');

        // The admin route is closed to them, and putting the key in their own
        // session by any other means would still not be honoured — viewedBy()
        // is the identity function for a non-admin.
        $this->actingAs($one)
            ->post(route('admin.product-partners.view-as', $two))
            ->assertRedirect(route('product-partner.dashboard'));

        $this->assertNull(session(ProductPartner::VIEW_AS));

        $this->actingAs($one)
            ->withSession([ProductPartner::VIEW_AS => $two->id])
            ->get(route('product-partner.dashboard'))
            ->assertOk()
            ->assertDontSee('Viewing as');
    }

    public function test_the_view_is_dropped_if_the_partner_stops_being_one(): void
    {
        $partner = $this->partner('plasmaguard');

        $this->actingAs($this->admin)->post(route('admin.product-partners.view-as', $partner));

        // Role changed underneath the session. A view that corresponds to
        // nobody is worse than no view.
        $partner->forceFill(['role_id' => Role::findByName(Role::FREE_MEMBER)->id])->save();

        $this->actingAs($this->admin)->get(route('product-partner.dashboard'))
            ->assertOk()
            ->assertDontSee('Viewing as');
    }

    public function test_a_support_admin_can_view_as_a_partner(): void
    {
        // Read-only, and shows them nothing the admin section does not.
        $support = User::factory()->create([
            'role_id' => Role::findByName(Role::SUPPORT_ADMIN)->id, 'is_active' => true,
        ]);
        $partner = $this->partner('plasmaguard');

        $this->actingAs($support)
            ->post(route('admin.product-partners.view-as', $partner))
            ->assertRedirect(route('product-partner.dashboard'));

        $this->actingAs($support)->get(route('product-partner.dashboard'))
            ->assertOk()
            ->assertSee('Viewing as');
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

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
