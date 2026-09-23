<?php

namespace Tests\Feature\Partner;

use App\Models\PartnerCompany;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\User;
use App\Models\VendorLead;
use App\Services\Partner\ActivationSalesReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * How many people came through a partner's activation, and what they have sold.
 *
 * The three things this report can get wrong, and therefore the three things
 * pinned here: counting positions nobody claimed as people, crediting a sale to
 * whoever's link was used rather than to whoever the sale counts for, and
 * losing a founder's sales because the position they claimed was merged into
 * the account they already had.
 */
class ActivationSalesReportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private PartnerCompany $company;
    private int $leadSeq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RoleSeeder::class);

        $this->admin = $this->member('Partner Admin', 'partner-admin@example.com');
        $this->admin->forceFill([
            'role_id'        => Role::findByName(Role::SUPER_ADMIN)->id,
            'billing_exempt' => true,
        ])->save();

        $this->company = PartnerCompany::create([
            'slug'            => 'acme',
            'name'            => 'Acme Group',
            'total_spots'     => 1_000,
            'unclaimed_spots' => 996,
        ]);
    }

    public function test_it_counts_people_who_claimed_and_not_positions_waiting(): void
    {
        $claimed = $this->claimedSpot('1001', 'Dana Whitfield', 'dana@example.com');
        $this->holdingSpot('1002');
        $this->holdingSpot('1003');

        $summary = app(ActivationSalesReport::class)->summary($this->company->id);

        $this->assertSame(1, $summary['activated']);
        $this->assertSame(1_000, $summary['imported'], 'Positions imported comes from the company counters.');
        $this->assertSame(996, $summary['unclaimed']);

        $rows = app(ActivationSalesReport::class)->query($this->company->id)->get();

        $this->assertSame([$claimed->id], $rows->pluck('id')->all());
    }

    public function test_a_sale_counts_for_the_member_it_is_credited_to(): void
    {
        $seller = $this->claimedSpot('2001', 'Marcus Ortiz', 'marcus@example.com');

        // Sold to a customer: theirs, and they are paid for it.
        $this->convertedLead($seller, VendorLead::ATTRIBUTION_CUSTOMER, 641_400);
        $this->convertedLead($seller, VendorLead::ATTRIBUTION_CUSTOMER, 200_000);

        // Their own purchase. Counts as their sale — see PurchaseAttribution —
        // but it is not them selling to anybody, so it is reported apart.
        $this->convertedLead($seller, VendorLead::ATTRIBUTION_SELF, 100_000);

        // Refunded. The money went back, so it is not revenue.
        $this->convertedLead($seller, VendorLead::ATTRIBUTION_CUSTOMER, 500_000)
            ->forceFill(['status' => VendorLead::STATUS_REFUNDED])->save();

        $row = app(ActivationSalesReport::class)->query($this->company->id)->first();

        $this->assertSame(2, (int) $row->customer_orders);
        $this->assertSame(841_400, (int) $row->customer_revenue);
        $this->assertSame(1, (int) $row->own_orders);
        $this->assertSame(100_000, (int) $row->own_revenue);

        $summary = app(ActivationSalesReport::class)->summary($this->company->id);

        $this->assertSame(1, $summary['selling']);
        $this->assertSame(2, $summary['orders']);
        $this->assertSame(841_400, $summary['revenue']);
    }

    public function test_a_merged_position_reports_the_surviving_account_s_sales(): void
    {
        // The founder case: they claimed their imported position and folded it
        // into the account they already had. They came through the activation,
        // and every sale since belongs to the account that survived.
        $founder = $this->member('Founder', 'founder@example.com');
        $spot    = $this->claimedSpot('3001', 'Founder', 'founder-ihub@example.com');

        $spot->forceFill([
            'account_status'      => User::ACCOUNT_MERGED,
            'merged_into_user_id' => $founder->id,
        ])->save();

        $this->convertedLead($founder, VendorLead::ATTRIBUTION_CUSTOMER, 641_400);

        $report = app(ActivationSalesReport::class);

        // Not in the default list: the position is no longer an account.
        $this->assertSame([], $report->query($this->company->id)->pluck('id')->all());

        $merged = $report->query($this->company->id, ['state' => ActivationSalesReport::STATE_MERGED])->first();

        $this->assertSame($spot->id, $merged->id);
        $this->assertSame(1, (int) $merged->customer_orders);
        $this->assertSame(641_400, (int) $merged->customer_revenue);

        $summary = $report->summary($this->company->id);

        $this->assertSame(0, $summary['activated']);
        $this->assertSame(1, $summary['merged']);
        $this->assertSame(641_400, $summary['revenue'], 'A merged founder\'s sales are not lost.');
    }

    public function test_membership_distinguishes_paying_from_waiting_on_commissions(): void
    {
        $paying = $this->claimedSpot('4001', 'Paying', 'paying@example.com');
        $waiting = $this->claimedSpot('4002', 'Waiting', 'waiting@example.com');
        $none    = $this->claimedSpot('4003', 'Nobody', 'nobody@example.com');

        $this->subscription($paying, Subscription::STATUS_ACTIVE, Subscription::TRIGGER_LAUNCH);
        $this->subscription($waiting, Subscription::STATUS_TRIALING, Subscription::TRIGGER_COMMISSION);

        $report = app(ActivationSalesReport::class);
        $rows   = $report->query($this->company->id)->get()->keyBy('id');

        $this->assertSame('Paying', $report->membershipLabel($rows[$paying->id])['label']);
        $this->assertSame('Waiting on commissions', $report->membershipLabel($rows[$waiting->id])['label']);
        $this->assertSame('None', $report->membershipLabel($rows[$none->id])['label']);

        // Only the one actually billing counts as a membership sale.
        $this->assertSame(1, $report->summary($this->company->id)['paying']);
    }

    public function test_the_admin_screen_renders_and_exports(): void
    {
        $seller = $this->claimedSpot('5001', 'Dana Whitfield', 'dana@example.com');
        $this->convertedLead($seller, VendorLead::ATTRIBUTION_CUSTOMER, 641_400);

        $this->actingAs($this->admin->refresh())
            ->get('/admin/partners/activations')
            ->assertOk()
            ->assertSee('Dana Whitfield')
            ->assertSee('5001')
            ->assertSee('$6,414.00');

        $csv = $this->actingAs($this->admin->refresh())
            ->get('/admin/partners/activations/export')
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('5001,"Dana Whitfield",dana@example.com', $csv);
        $this->assertStringContainsString('6414.00', $csv);
    }

    public function test_the_report_is_closed_to_everyone_but_an_admin(): void
    {
        $member = $this->member('Ordinary', 'ordinary@example.com');

        $this->get('/admin/partners/activations')->assertRedirect();

        $this->actingAs($member)
            ->get('/admin/partners/activations')
            ->assertForbidden();
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────

    private function member(string $name, string $email): User
    {
        $user = User::create(['name' => $name, 'email' => $email, 'password' => 'password']);

        $user->forceFill([
            'role_id'   => Role::findByName(Role::FREE_MEMBER)->id,
            'is_active' => true,
        ])->save();

        return $user->refresh();
    }

    private function claimedSpot(string $externalId, string $name, string $email): User
    {
        $user = $this->member($name, $email);

        $user->forceFill([
            'account_status'     => User::ACCOUNT_ACTIVE,
            'partner_company_id' => $this->company->id,
            'external_user_id'   => $externalId,
            'imported_at'        => now()->subDays(4),
            'claimed_at'         => now()->subDay(),
        ])->save();

        return $user->refresh();
    }

    private function holdingSpot(string $externalId): User
    {
        $user = User::create([
            'name'     => 'Spot '.$externalId,
            'email'    => 'spot-'.$externalId.'@import.invalid',
            'password' => 'password',
        ]);

        $user->forceFill([
            'account_status'     => User::ACCOUNT_HOLDING,
            'partner_company_id' => $this->company->id,
            'external_user_id'   => $externalId,
            'imported_at'        => now()->subDays(4),
        ])->save();

        return $user->refresh();
    }

    private function convertedLead(User $creditedTo, string $attribution, int $amountMinor): VendorLead
    {
        $lead = VendorLead::create([
            'public_ref'   => 'ref-'.(++$this->leadSeq),
            'vendor'       => 'plasmaguard',
            'product_key'  => 'pro',
            'member_id'    => $creditedTo->id,
            'first_name'   => 'Buyer',
            'email'        => 'buyer-'.$this->leadSeq.'@example.com',
            'quantity'     => 1,
        ]);

        // The attribution columns decide who is paid, so they are not fillable.
        $lead->forceFill([
            'status'             => VendorLead::STATUS_CONVERTED,
            'converted_at'       => now(),
            'amount_total'       => $amountMinor,
            'currency'           => 'usd',
            'attribution'        => $attribution,
            'credited_member_id' => $creditedTo->id,
        ])->save();

        return $lead;
    }

    private function subscription(User $user, string $status, string $trigger): Subscription
    {
        return Subscription::create([
            'user_id'                  => $user->id,
            'provider'                 => 'stripe',
            'provider_subscription_id' => 'sub_'.$user->id,
            'status'                   => $status,
            'billing_trigger'          => $trigger,
            'current_period_start'     => now()->subDays(2),
            'current_period_end'       => now()->addDays(28),
        ]);
    }
}
