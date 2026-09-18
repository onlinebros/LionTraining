<?php

namespace Tests\Feature\Vendor;

use App\Models\CommissionLedger;
use App\Models\PromotionAward;
use App\Models\Role;
use App\Models\User;
use App\Models\VendorLead;
use App\Services\Vendor\PromotionBonuses;
use App\Services\Vendor\VendorReferralService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The launch special's money (owner, 2026-09-17), at a small scale.
 *
 * Three places at $500, then four pool systems at $30 each. $30 across three
 * places is $10 a share.
 */
class PromotionBonusTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'plasmaguard-pro-first-100';

    private User $sponsor;

    private User $ann;

    private User $ben;

    private User $cara;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-20 12:00:00');

        config()->set('vendors.vendors.plasmaguard.enabled', true);
        // Sale commission kept out of the way: these tests count bonus credits.
        config()->set('vendors.vendors.plasmaguard.commission.rate', 0);

        $key = 'promotions.promotions.'.self::KEY;
        config()->set("{$key}.enabled", true);
        config()->set("{$key}.cap", 3);
        config()->set("{$key}.counts", 'units');
        config()->set("{$key}.starts_at", '2026-09-01 00:00:00');
        config()->set("{$key}.ends_at", null);
        config()->set("{$key}.bonus", [
            'enabled'       => true,
            'place_amount'  => 500,
            'pool_units'    => 4,
            'pool_per_unit' => 30,
            'lock_days'     => 60,
        ]);

        $this->sponsor = User::factory()->create(['name' => 'Sam Sponsor', 'billing_exempt' => true]);
        $this->ann  = User::factory()->create(['name' => 'Ann Able', 'sponsor_id' => $this->sponsor->id, 'billing_exempt' => true]);
        $this->ben  = User::factory()->create(['name' => 'Ben Baker', 'sponsor_id' => $this->sponsor->id, 'billing_exempt' => true]);
        $this->cara = User::factory()->create(['name' => 'Cara Cole', 'sponsor_id' => $this->sponsor->id, 'billing_exempt' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_each_of_the_first_places_earns_the_bonus_for_whoever_it_counts_for(): void
    {
        // Ann sells two to a customer; Ben buys his own through Ann's link.
        $this->sale($this->ann, 2, 'dana@example.com');
        $this->sale($this->ann, 1, $this->ben->email);

        $this->assertSame(['Ann Able' => 1000.0, 'Ben Baker' => 500.0], $this->credited());

        // Two place bonuses for Ann's order, one per system.
        $this->assertSame(2, PromotionAward::where('kind', 'place')->where('earner_id', $this->ann->id)->count());
    }

    public function test_the_next_sales_fund_a_pool_shared_one_share_per_place(): void
    {
        $this->sale($this->ann, 2, 'dana@example.com');   // places 1–2: Ann
        $this->sale($this->ben, 1, 'eve@example.com');    // place 3: Ben
        $this->sale($this->cara, 2, 'fay@example.com');   // pool sales 1–2
        $this->sale($this->cara, 3, 'gus@example.com');   // pool sales 3–4, one system past the pool
        $this->sale($this->cara, 1, 'hal@example.com');   // after the special

        // Pool: 4 systems × $10 a share. Ann holds 2 shares, Ben 1.
        $this->assertSame([
            'Ann Able'  => 1000.0 + 4 * 2 * 10,
            'Ben Baker' => 500.0 + 4 * 1 * 10,
        ], $this->credited());

        // Cara's sales fund the pool; they earn her no bonus.
        $this->assertSame(0, PromotionAward::where('earner_id', $this->cara->id)->count());
    }

    public function test_the_bonus_is_on_top_of_the_normal_commission(): void
    {
        config()->set('vendors.vendors.plasmaguard.commission.rate', 0.10);
        config()->set('vendors.vendors.plasmaguard.commission.basis', 'revenue_share');
        config()->set('vendors.vendors.plasmaguard.products.pro-in-duct.price', 6000);
        config()->set('vendors.vendors.plasmaguard.products.pro-in-duct.revenue_share_per_unit', 300000);

        // Ann buys her own: the $300 commission is her sponsor's, the $500 bonus is hers.
        $this->sale($this->ann, 1, $this->ann->email);

        $this->assertEqualsWithDelta(500.0, $this->creditedTo($this->ann), 0.001);
        $this->assertEqualsWithDelta(300.0, $this->creditedTo($this->sponsor), 0.001);
    }

    public function test_a_refund_inside_the_window_moves_the_next_sale_up(): void
    {
        $first = $this->sale($this->ann, 2, 'dana@example.com');
        $this->sale($this->ben, 1, 'eve@example.com');
        $this->sale($this->cara, 2, 'fay@example.com');   // pool, for now

        Carbon::setTestNow('2026-10-01 12:00:00');
        app(VendorReferralService::class)->refund($first);

        // Ann's places are gone. Cara's order moves into places 2–3. With no
        // sales past the places, there is no pool, and the old pool credits go.
        $this->assertSame(['Ben Baker' => 500.0, 'Cara Cole' => 1000.0], $this->credited());

        $this->assertSame(0, CommissionLedger::where('earner_id', $this->ann->id)->where('status', 'pending')->count());
        $this->assertGreaterThan(0, CommissionLedger::where('earner_id', $this->ann->id)->where('status', 'voided')->count());
    }

    public function test_a_place_is_final_after_the_lock_window(): void
    {
        $first = $this->sale($this->ann, 1, 'dana@example.com');
        $this->sale($this->ben, 2, 'eve@example.com');
        $this->sale($this->cara, 1, 'fay@example.com');   // pool

        Carbon::setTestNow('2026-11-30 12:00:00');         // 71 days later
        app(VendorReferralService::class)->refund($first);

        // Nobody moves and nobody loses their credit.
        $this->assertSame([
            'Ann Able'  => 500.0 + 10,
            'Ben Baker' => 1000.0 + 2 * 10,
        ], $this->credited());
    }

    public function test_a_place_held_for_review_earns_nothing_until_decided(): void
    {
        $this->ben->forceFill(['address_line1' => '12 Elm Street', 'postal_code' => '48104'])->save();

        // Ann's customer ships to Ben's address: held for an admin.
        $held = $this->sale($this->ann, 1, 'neighbour@example.net', ['address_line1' => '12 Elm Street', 'postal_code' => '48104']);
        $this->assertSame(VendorLead::ATTRIBUTION_REVIEW, $held->refresh()->attribution);
        $this->assertSame([], $this->credited());

        $admin = User::factory()->create(['role_id' => Role::firstOrCreate(
            ['name' => Role::SUPER_ADMIN], ['display_name' => 'Super Admin', 'is_admin' => true, 'level' => 100],
        )->id]);

        app(VendorReferralService::class)->resolveAttribution($held, VendorLead::ATTRIBUTION_SELF, $admin);

        $this->assertSame(['Ben Baker' => 500.0], $this->credited());
    }

    public function test_a_credit_already_paid_is_flagged_not_reversed(): void
    {
        $first = $this->sale($this->ann, 1, 'dana@example.com');

        $award = PromotionAward::firstOrFail();
        $award->ledger->forceFill(['status' => 'paid'])->save();

        app(VendorReferralService::class)->refund($first);

        $award->refresh();
        $this->assertSame(PromotionAward::STATUS_NEEDS_CLAWBACK, $award->status);
        $this->assertSame('paid', $award->ledger->status);
    }

    public function test_reconciling_again_changes_nothing(): void
    {
        $this->sale($this->ann, 2, 'dana@example.com');
        $this->sale($this->ben, 1, 'eve@example.com');
        $this->sale($this->cara, 2, 'fay@example.com');

        $before = CommissionLedger::count();

        app(PromotionBonuses::class)->reconcile(self::KEY);
        app(PromotionBonuses::class)->reconcile(self::KEY);
        $this->artisan('promotions:reconcile-bonuses')->assertSuccessful();

        $this->assertSame($before, CommissionLedger::count());
    }

    public function test_partners_and_admins_see_the_earnings(): void
    {
        $this->sale($this->ann, 2, 'dana@example.com');
        $this->sale($this->ben, 1, 'eve@example.com');
        $this->sale($this->cara, 1, 'fay@example.com');

        $this->actingAs($this->ann)->get('/member/sales/promotion')
            ->assertOk()
            ->assertSee('Your launch special earnings')
            ->assertSee('$1,020.00')
            ->assertSee('1 of 4 sold');

        $role  = Role::firstOrCreate(['name' => Role::SUPER_ADMIN], ['display_name' => 'Super Admin', 'is_admin' => true, 'level' => 100]);
        $admin = User::factory()->create(['role_id' => $role->id]);

        $this->actingAs($admin)->get('/admin/vendor-leads/promotion')
            ->assertOk()
            ->assertSee('Launch special credits')
            ->assertSee('$1,500.00')
            ->assertSee('$30.00');
    }

    public function test_the_banner_states_the_bonus_and_the_pool(): void
    {
        $this->actingAs($this->ann)->get('/member/dashboard')
            ->assertOk()
            ->assertSee('$500 bonus')
            ->assertSee('one share of a $120 pool')
            ->assertSee('$30 of each of the next 4 systems sold')
            ->assertDontSee('Ask your sponsor');
    }

    public function test_an_own_purchase_is_filed_in_the_sponsors_crm(): void
    {
        $this->actingAs($this->ann)->post('/member/sales/buy/plasmaguard/pro-in-duct', [
            'first_name'    => 'Ann',
            'address_line1' => '1 Main St',
            'city'          => 'Ann Arbor',
            'state'         => 'MI',
            'postal_code'   => '48104',
            'quantity'      => 1,
        ])->assertRedirect();

        $this->assertDatabaseHas('crm_contacts', [
            'owner_id'       => $this->sponsor->id,
            'email'          => $this->ann->email,
            'linked_user_id' => $this->ann->id,
        ]);
        $this->assertDatabaseMissing('crm_contacts', ['owner_id' => $this->ann->id]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * A confirmed sale through $member's link, one minute after the last.
     *
     * @param  array<string,mixed>  $extra
     */
    private function sale(User $member, int $units, string $email, array $extra = []): VendorLead
    {
        Carbon::setTestNow(Carbon::now()->addMinute());

        $lead = app(VendorReferralService::class)->capture('plasmaguard', 'pro-in-duct', $member, $extra + [
            'first_name' => 'Buyer'.Str::random(4),
            'email'      => $email,
            'quantity'   => $units,
        ]);

        return app(VendorReferralService::class)->convert($lead, ['amount_total' => 600000 * $units, 'currency' => 'USD'], VendorLead::VIA_MANUAL);
    }

    /** @return array<string,float> Pending credits per partner name. */
    private function credited(): array
    {
        return CommissionLedger::where('status', 'pending')
            ->credits()
            ->with('earner')
            ->get()
            ->groupBy(fn (CommissionLedger $row) => $row->earner->name)
            ->map(fn ($rows) => round((float) $rows->sum('amount'), 4))
            ->sortKeys()
            ->all();
    }

    private function creditedTo(User $user): float
    {
        return (float) CommissionLedger::where('earner_id', $user->id)->where('status', 'pending')->sum('amount');
    }
}
