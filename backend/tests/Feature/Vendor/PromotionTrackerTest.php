<?php

namespace Tests\Feature\Vendor;

use App\Models\Role;
use App\Models\User;
use App\Models\VendorLead;
use App\Services\Vendor\PromotionTracker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The first-100 PlasmaGuard PRO promotion.
 *
 * Places are handed out by confirmed payment, computed from the orders every
 * time, so a refund frees its place without anything having to be kept in step.
 */
class PromotionTrackerTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'plasmaguard-pro-first-100';

    private User $ann;

    private User $ben;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('vendors.vendors.plasmaguard.enabled', true);

        $key = 'promotions.promotions.'.self::KEY;
        config()->set("{$key}.enabled", true);
        config()->set("{$key}.cap", 5);
        config()->set("{$key}.counts", 'units');
        config()->set("{$key}.starts_at", '2026-09-01 00:00:00');
        config()->set("{$key}.ends_at", null);
        config()->set("{$key}.timezone", 'America/New_York');

        $this->ann = User::factory()->create(['name' => 'Ann Able', 'billing_exempt' => true]);
        $this->ben = User::factory()->create(['name' => 'Ben Baker', 'billing_exempt' => true]);
    }

    public function test_systems_fill_places_in_the_order_payment_was_confirmed(): void
    {
        // Created out of order: places follow confirmation time, not row order.
        $this->sale($this->ben, 2, '2026-09-15 11:00');
        $this->sale($this->ben, 1, '2026-09-15 13:00');   // too late, the cap is reached
        $last = $this->sale($this->ann, 3, '2026-09-15 12:00');   // only one place left
        $this->sale($this->ann, 2, '2026-09-15 10:00');

        $s = $this->standings();

        $this->assertSame(5, $s['filled']);
        $this->assertSame(0, $s['remaining']);
        $this->assertTrue($s['full']);
        $this->assertSame([[1, 2], [3, 4], [5, 5]], array_map(fn ($e) => [$e['from'], $e['to']], $s['entries']));

        $this->assertSame($last->id, $s['entries'][2]['lead']->id);
        $this->assertTrue($s['entries'][2]['partial']);

        $this->assertSame(
            [['Ann Able', 3], ['Ben Baker', 2]],
            array_map(fn ($row) => [$row['name'], $row['units']], $s['leaderboard']),
        );
    }

    public function test_refunded_and_unpaid_orders_do_not_hold_a_place(): void
    {
        $this->sale($this->ann, 2, '2026-09-15 10:00', VendorLead::STATUS_REFUNDED);
        $this->sale($this->ann, 1, '2026-09-15 11:00', VendorLead::STATUS_HANDED_OFF);
        $paid = $this->sale($this->ben, 1, '2026-09-15 12:00');

        $s = $this->standings();

        $this->assertSame(1, $s['filled']);
        $this->assertSame($paid->id, $s['entries'][0]['lead']->id);
        $this->assertSame(1, $s['entries'][0]['from']);
    }

    public function test_sales_confirmed_before_the_start_do_not_count(): void
    {
        // The start is midnight Eastern, which is 04:00 UTC in September.
        $this->sale($this->ann, 1, '2026-09-01 03:59');
        $this->sale($this->ben, 1, '2026-09-01 04:01');

        $this->assertSame(['Ben Baker'], array_column($this->standings()['leaderboard'], 'name'));
    }

    public function test_an_own_purchase_counts_for_the_buyer_not_the_link_owner(): void
    {
        $this->sale($this->ann, 2, '2026-09-15 10:00', credited: $this->ben);

        $this->assertSame(['Ben Baker'], array_column($this->standings()['leaderboard'], 'name'));
    }

    public function test_orders_can_be_counted_once_each_instead_of_by_unit(): void
    {
        config()->set('promotions.promotions.'.self::KEY.'.counts', 'orders');

        $this->sale($this->ann, 3, '2026-09-15 10:00');

        $this->assertSame(1, $this->standings()['filled']);
    }

    public function test_an_ended_promotion_is_not_shown_to_partners(): void
    {
        config()->set('promotions.promotions.'.self::KEY.'.ends_at', '2026-01-01 00:00:00');

        $this->assertNull(app(PromotionTracker::class)->currentKey());

        $this->actingAs($this->ann)
            ->get('/member/sales/promotion')
            ->assertOk()
            ->assertSee('There is no promotion running right now.');
    }

    public function test_partners_see_the_leaderboard_and_places_left(): void
    {
        $this->sale($this->ann, 2, '2026-09-15 10:00');

        $this->actingAs($this->ben)
            ->get('/member/sales/promotion')
            ->assertOk()
            ->assertSee('First 100 PlasmaGuard PRO Systems')
            ->assertSee('Ann Able')
            ->assertSee('3 places left');

        $this->actingAs($this->ben)
            ->get('/member/sales')
            ->assertOk()
            ->assertSee('View leaderboard');
    }

    public function test_admins_see_every_order_holding_a_place(): void
    {
        $lead = $this->sale($this->ann, 2, '2026-09-15 10:00');

        $role  = Role::firstOrCreate(['name' => Role::SUPER_ADMIN],
            ['display_name' => 'Super Admin', 'is_admin' => true, 'level' => 100]);
        $admin = User::factory()->create(['role_id' => $role->id]);

        $this->actingAs($admin)
            ->get('/admin/vendor-leads/promotion')
            ->assertOk()
            ->assertSee($lead->public_ref)
            ->assertSee('Ann Able');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function standings(): array
    {
        return app(PromotionTracker::class)->standings(self::KEY);
    }

    /** A confirmed (or otherwise) order, with $confirmedAt in UTC. */
    private function sale(
        User $member,
        int $units,
        string $confirmedAt,
        string $status = VendorLead::STATUS_CONVERTED,
        ?User $credited = null,
    ): VendorLead {
        $paid = in_array($status, [VendorLead::STATUS_CONVERTED, VendorLead::STATUS_REFUNDED], true);

        $lead = VendorLead::create([
            'public_ref'   => 'QLV-'.strtoupper(Str::random(10)),
            'vendor'       => 'plasmaguard',
            'product_key'  => 'pro-in-duct',
            'member_id'    => $member->id,
            'first_name'   => 'Dana',
            'email'        => 'dana@example.com',
            'quantity'     => $units,
            'status'       => $status,
            'converted_at' => $paid ? Carbon::parse($confirmedAt, 'UTC') : null,
        ]);

        $lead->forceFill([
            'attribution'        => $credited ? VendorLead::ATTRIBUTION_SELF : VendorLead::ATTRIBUTION_CUSTOMER,
            'buyer_user_id'      => $credited?->id,
            'credited_member_id' => ($credited ?? $member)->id,
            'earner_id'          => $member->id,
        ])->save();

        return $lead;
    }
}
