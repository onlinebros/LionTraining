<?php

namespace Tests\Feature\Opportunities;

use App\Models\Role;
use App\Models\User;
use App\Models\UserOpportunity;
use App\Support\Opportunity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Staff putting a member on a business line, and finding them again afterwards.
 *
 * This is the lever behind "control what they see": the primary line decides
 * whether a card is asked for at all, so the screen that moves it has to be
 * hard to get wrong. The case being pinned is removing a member's primary line
 * and leaving the denormalised column pointing at something they no longer
 * hold.
 */
class OpportunityAdminTest extends TestCase
{
    use RefreshDatabase;

    private function roles(): void
    {
        foreach ([
            [Role::FREE_MEMBER, 'Free Member', false, 1],
            [Role::PAID_MEMBER, 'Paid Member', false, 2],
            [Role::SUPER_ADMIN, 'Super Admin', true, 9],
        ] as [$name, $display, $isAdmin, $level]) {
            Role::create(['name' => $name, 'display_name' => $display, 'is_admin' => $isAdmin, 'level' => $level]);
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->roles();
    }

    private function admin(): User
    {
        return User::factory()->create(['role_id' => Role::findByName(Role::SUPER_ADMIN)->id]);
    }

    private function member(): User
    {
        return User::factory()->create(['role_id' => Role::findByName(Role::FREE_MEMBER)->id]);
    }

    public function test_the_user_page_lists_the_lines_a_member_holds(): void
    {
        $member = $this->member();
        $member->associateOpportunity('plasmaguard', UserOpportunity::SOURCE_ADMIN, primary: true);

        $this->actingAs($this->admin())
            ->get(route('admin.users.show', $member))
            ->assertOk()
            ->assertSee('Opportunities')
            ->assertSee('PlasmaGuard Products')
            ->assertSee('No card');
    }

    public function test_staff_can_put_a_member_on_a_line_and_make_it_primary(): void
    {
        $admin  = $this->admin();
        $member = $this->member();

        $this->actingAs($admin)
            ->post(route('admin.users.opportunities.add', $member), [
                'opportunity' => 'plasmaguard',
                'primary'     => '1',
            ])
            ->assertRedirect();

        $member->refresh();

        $this->assertSame('plasmaguard', $member->opportunityKey());
        $this->assertFalse($member->requiresMembership());
        $this->assertDatabaseHas('user_opportunities', [
            'user_id'          => $member->id,
            'opportunity'      => 'plasmaguard',
            'source'           => UserOpportunity::SOURCE_ADMIN,
            'added_by_user_id' => $admin->id,
        ]);
    }

    public function test_adding_a_line_without_the_checkbox_leaves_the_primary_alone(): void
    {
        $member = $this->member();

        $this->actingAs($this->admin())
            ->post(route('admin.users.opportunities.add', $member), ['opportunity' => 'plasmaguard']);

        $member->refresh();

        // Still on the default, so still asked for a card — but they can now
        // see the product-sales side.
        $this->assertSame(Opportunity::defaultKey(), $member->opportunityKey());
        $this->assertTrue($member->requiresMembership());
        $this->assertTrue($member->hasOpportunity('plasmaguard'));
    }

    public function test_an_unknown_line_is_refused(): void
    {
        $member = $this->member();

        $this->actingAs($this->admin())
            ->post(route('admin.users.opportunities.add', $member), ['opportunity' => 'not-a-line'])
            ->assertSessionHasErrors('opportunity');

        $this->assertDatabaseCount('user_opportunities', 0);
    }

    public function test_the_primary_line_cannot_be_removed(): void
    {
        $member = $this->member();
        $member->associateOpportunity('plasmaguard', UserOpportunity::SOURCE_ADMIN, primary: true);

        $this->actingAs($this->admin())
            ->delete(route('admin.users.opportunities.remove', [$member, 'plasmaguard']))
            ->assertSessionHasErrors('error');

        $this->assertTrue($member->refresh()->hasOpportunity('plasmaguard'));
    }

    public function test_a_second_line_can_be_removed(): void
    {
        $member = $this->member();
        $member->associateOpportunity('plasmaguard', UserOpportunity::SOURCE_ADMIN, primary: true);
        $member->associateOpportunity(Opportunity::defaultKey(), UserOpportunity::SOURCE_ADMIN);

        $this->actingAs($this->admin())
            ->delete(route('admin.users.opportunities.remove', [$member, Opportunity::defaultKey()]))
            ->assertRedirect();

        $member->refresh();

        $this->assertFalse($member->canSee('training'));
        $this->assertSame('plasmaguard', $member->opportunityKey());
    }

    public function test_the_user_list_filters_by_line(): void
    {
        $plasma = $this->member();
        $plasma->associateOpportunity('plasmaguard', UserOpportunity::SOURCE_ADMIN, primary: true);
        $ordinary = $this->member();

        $this->actingAs($this->admin())
            ->get(route('admin.users.index', ['opportunity' => 'plasmaguard']))
            ->assertOk()
            ->assertSee($plasma->email)
            ->assertDontSee($ordinary->email);
    }

    public function test_filtering_by_the_default_line_includes_accounts_that_predate_the_feature(): void
    {
        // The column is null for every account created before this shipped, and
        // null means the default. A filter that missed them would show staff an
        // empty list and invite the conclusion that the data is broken.
        $ordinary = $this->member();
        $this->assertNull($ordinary->primary_opportunity);

        $this->actingAs($this->admin())
            ->get(route('admin.users.index', ['opportunity' => Opportunity::defaultKey()]))
            ->assertOk()
            ->assertSee($ordinary->email);
    }
}
