<?php

namespace Tests\Feature\Vendor;

use App\Models\CommissionLedger;
use App\Models\Role;
use App\Models\User;
use App\Models\VendorLead;
use App\Services\Vendor\VendorReferralService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A partner is never paid on their own purchase.
 *
 * The sale counts for them, and the commission goes to the partner who
 * sponsored them, whichever share link was used.
 */
class SelfPurchaseTest extends TestCase
{
    use RefreshDatabase;

    private User $sponsor;

    private User $partner;

    private User $other;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('vendors.vendors.plasmaguard.enabled', true);
        config()->set('vendors.vendors.plasmaguard.checkout.mode', 'direct');
        config()->set('vendors.vendors.plasmaguard.commission.rate', 0.10);
        config()->set('vendors.vendors.plasmaguard.commission.basis', 'revenue_share');
        config()->set('vendors.vendors.plasmaguard.revenue_share.model', 'per_unit');
        config()->set('vendors.vendors.plasmaguard.products.pro-in-duct.price', 6000);
        config()->set('vendors.vendors.plasmaguard.products.pro-in-duct.revenue_share_per_unit', 300000);

        $this->sponsor = User::factory()->create([
            'name' => 'Sam Sponsor', 'referral_code' => 'SPONSOR1', 'billing_exempt' => true,
        ]);

        $this->partner = User::factory()->create([
            'name'           => 'Pat Partner',
            'email'          => 'pat@example.com',
            'referral_code'  => 'PARTNER1',
            'sponsor_id'     => $this->sponsor->id,
            'billing_exempt' => true,
            'phone'          => '(734) 555-0199',
            'address_line1'  => '12 Elm Street',
            'city'           => 'Ann Arbor',
            'state'          => 'MI',
            'postal_code'    => '48104',
            'country'        => 'US',
        ]);

        $this->other = User::factory()->create([
            'name' => 'Olive Other', 'referral_code' => 'OTHER001', 'billing_exempt' => true,
        ]);
    }

    // ── The back office ───────────────────────────────────────────────────────

    public function test_the_back_office_form_says_who_the_commission_goes_to(): void
    {
        $this->actingAs($this->partner)
            ->get('/member/sales/buy/plasmaguard/pro-in-duct')
            ->assertOk()
            ->assertSee('PlasmaGuard PRO In-Duct System')
            ->assertSee('Sam Sponsor')
            ->assertSee('12 Elm Street');
    }

    public function test_a_back_office_order_counts_for_the_partner_and_pays_their_sponsor(): void
    {
        $response = $this->actingAs($this->partner)->post('/member/sales/buy/plasmaguard/pro-in-duct', [
            'first_name'    => 'Pat',
            'last_name'     => 'Partner',
            'address_line1' => '12 Elm Street',
            'city'          => 'Ann Arbor',
            'state'         => 'MI',
            'postal_code'   => '48104',
            'quantity'      => 1,
            // Ignored: a back-office order is always under the account email.
            'email'         => 'someone-else@example.com',
        ]);

        $lead = VendorLead::firstOrFail();
        $response->assertRedirect(route('vendor.order', $lead->public_ref));

        $this->assertSame(VendorLead::SOURCE_BACK_OFFICE, $lead->source);
        $this->assertSame('pat@example.com', $lead->email);
        $this->assertSame(VendorLead::ATTRIBUTION_SELF, $lead->attribution);
        $this->assertSame($this->partner->id, $lead->buyer_user_id);
        $this->assertSame($this->partner->id, $lead->credited_member_id);
        $this->assertSame($this->sponsor->id, $lead->earner_id);
        // Filed with the sponsor, who earns on it, and linked to the buyer.
        $this->assertDatabaseHas('crm_contacts', [
            'owner_id'       => $this->sponsor->id,
            'email'          => 'pat@example.com',
            'linked_user_id' => $this->partner->id,
        ]);
        $this->assertDatabaseMissing('crm_contacts', ['owner_id' => $this->partner->id]);

        $this->confirm($lead);

        $ledger = CommissionLedger::firstOrFail();
        $this->assertSame($this->sponsor->id, $ledger->earner_id);
        // 10% of our $3,000 share, not of the customer's whole charge.
        $this->assertSame('300.0000', $ledger->amount);
    }

    public function test_a_disabled_vendor_cannot_be_ordered_from_the_back_office(): void
    {
        config()->set('vendors.vendors.plasmaguard.enabled', false);

        $this->actingAs($this->partner)
            ->get('/member/sales/buy/plasmaguard/pro-in-duct')
            ->assertNotFound();
    }

    // ── Share links ───────────────────────────────────────────────────────────

    public function test_buying_through_your_own_share_link_pays_your_sponsor_not_you(): void
    {
        $lead = $this->enquire('PARTNER1', ['email' => 'PAT@example.com']);

        $this->assertSame(VendorLead::ATTRIBUTION_SELF, $lead->attribution);

        $this->confirm($lead);

        $this->assertSame([$this->sponsor->id], CommissionLedger::pluck('earner_id')->all());
    }

    public function test_buying_through_another_partners_link_still_pays_your_sponsor(): void
    {
        // Swapping links with a friend must not cut the sponsor out either.
        $lead = $this->enquire('OTHER001', ['email' => 'pat@example.com']);

        $this->assertSame($this->other->id, $lead->member_id);
        $this->assertSame($this->partner->id, $lead->credited_member_id);

        $this->confirm($lead);

        $this->assertSame([$this->sponsor->id], CommissionLedger::pluck('earner_id')->all());
    }

    public function test_a_partners_phone_number_is_a_sure_match(): void
    {
        $lead = $this->enquire('OTHER001', [
            'email' => 'pat.home@example.net',
            'phone' => '+1 734.555.0199',
        ]);

        $this->assertSame(VendorLead::ATTRIBUTION_SELF, $lead->attribution);
        $this->assertSame($this->partner->id, $lead->buyer_user_id);
    }

    public function test_a_customer_sale_pays_the_link_owner_on_our_share(): void
    {
        $lead = $this->enquire('PARTNER1', ['email' => 'dana@example.com', 'quantity' => 2]);

        $this->assertSame(VendorLead::ATTRIBUTION_CUSTOMER, $lead->attribution);

        $this->confirm($lead);

        $ledger = CommissionLedger::firstOrFail();
        $this->assertSame($this->partner->id, $ledger->earner_id);
        // Two systems is $6,000 of revenue share; 10% of that.
        $this->assertSame('600.0000', $ledger->amount);
    }

    public function test_a_partner_with_no_sponsor_is_not_paid_on_their_own_purchase(): void
    {
        $lead = $this->enquire('SPONSOR1', ['email' => $this->sponsor->email]);

        $this->confirm($lead);
        $lead->refresh();

        $this->assertSame(VendorLead::ATTRIBUTION_SELF, $lead->attribution);
        $this->assertNull($lead->earner_id);
        $this->assertSame(0, CommissionLedger::count());
        // Paying no one is the design, not a reconciliation problem.
        $this->assertSame(0, VendorLead::uncommissioned()->count());
    }

    // ── Held for review ───────────────────────────────────────────────────────

    public function test_a_matching_address_alone_holds_the_commission_for_an_admin(): void
    {
        $lead = $this->enquire('OTHER001', ['email' => 'neighbour@example.net']);

        $this->assertSame(VendorLead::ATTRIBUTION_CUSTOMER, $lead->attribution);

        $this->post("/p/order/{$lead->public_ref}/address", [
            'address_line1' => '12 ELM STREET',
            'city'          => 'Ann Arbor',
            'state'         => 'MI',
            'postal_code'   => '48104-1234',
        ])->assertRedirect();

        $this->confirm($lead);
        $lead->refresh();

        $this->assertSame(VendorLead::ATTRIBUTION_REVIEW, $lead->attribution);
        $this->assertSame($this->partner->id, $lead->buyer_user_id);
        $this->assertSame(0, CommissionLedger::count());

        $this->actingAs($this->admin())
            ->post("/admin/vendor-leads/{$lead->id}/attribution", ['decision' => 'self'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame([$this->sponsor->id], CommissionLedger::pluck('earner_id')->all());
    }

    public function test_an_admin_can_clear_a_held_order_as_a_customer_sale(): void
    {
        $lead = $this->enquire('OTHER001', ['email' => 'neighbour@example.net']);
        $lead->forceFill(['address_line1' => '12 Elm Street', 'postal_code' => '48104'])->save();

        $this->confirm($lead);
        $this->assertSame(VendorLead::ATTRIBUTION_REVIEW, $lead->refresh()->attribution);

        $this->actingAs($this->admin())
            ->post("/admin/vendor-leads/{$lead->id}/attribution", ['decision' => 'customer'])
            ->assertRedirect();

        $this->assertSame(VendorLead::ATTRIBUTION_CUSTOMER, $lead->refresh()->attribution);
        $this->assertSame([$this->other->id], CommissionLedger::pluck('earner_id')->all());
    }

    public function test_an_admin_decision_is_not_undone_by_a_later_confirmation(): void
    {
        $lead = $this->enquire('OTHER001', ['email' => 'neighbour@example.net']);
        $lead->forceFill(['address_line1' => '12 Elm Street', 'postal_code' => '48104'])->save();

        app(VendorReferralService::class)->resolveAttribution($lead, 'customer', $this->admin());

        $this->confirm($lead);

        $this->assertSame([$this->other->id], CommissionLedger::pluck('earner_id')->all());
    }

    public function test_attribution_cannot_be_changed_once_commission_is_raised(): void
    {
        $lead = $this->enquire('PARTNER1', ['email' => 'dana@example.com']);
        $this->confirm($lead);

        $this->actingAs($this->admin())
            ->post("/admin/vendor-leads/{$lead->id}/attribution", ['decision' => 'customer'])
            ->assertSessionHasErrors();

        $this->assertSame(1, CommissionLedger::count());
    }

    // ── What partners see ─────────────────────────────────────────────────────

    public function test_the_sponsor_sees_the_purchase_and_the_commission_on_their_sales_page(): void
    {
        $this->confirm($this->enquire('PARTNER1', ['email' => 'pat@example.com']));

        $this->actingAs($this->sponsor)
            ->get('/member/sales')
            ->assertOk()
            ->assertSee("Your Partners' Own Purchases", false)
            ->assertSee('Pat Partner')
            ->assertSee('$300.00');
    }

    public function test_the_link_owner_is_told_why_they_were_not_paid(): void
    {
        $lead = $this->enquire('OTHER001', ['email' => 'pat@example.com']);

        $this->actingAs($this->other)
            ->get("/member/sales/{$lead->id}")
            ->assertOk()
            ->assertSee('another partner buying for themselves');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** @param  array<string,mixed>  $fields */
    private function enquire(string $code, array $fields): VendorLead
    {
        $this->post("/p/{$code}/plasmaguard/pro-in-duct", $fields + ['first_name' => 'Pat'])->assertRedirect();

        return VendorLead::latest('id')->firstOrFail();
    }

    private function confirm(VendorLead $lead): void
    {
        app(VendorReferralService::class)->convert(
            $lead,
            ['amount_total' => 641400, 'currency' => 'USD'],
            VendorLead::VIA_MANUAL,
        );
    }

    private function admin(): User
    {
        $role = Role::firstOrCreate(
            ['name' => Role::SUPER_ADMIN],
            ['display_name' => 'Super Admin', 'is_admin' => true, 'level' => 100],
        );

        return User::factory()->create(['role_id' => $role->id]);
    }
}
