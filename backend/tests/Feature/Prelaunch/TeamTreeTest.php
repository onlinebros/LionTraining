<?php

namespace Tests\Feature\Prelaunch;

use App\Models\Role;
use App\Models\User;
use App\Services\Genealogy\EnrollmentService;
use App\Services\Genealogy\GenealogyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The team tree — the screen the pre-launch phase is built around.
 */
class TeamTreeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create([
            'name' => Role::FREE_MEMBER,
            'display_name' => 'Free Member',
            'is_admin' => false,
            'level' => 1,
        ]);
    }

    private function enroll(?User $sponsor, array $attributes = []): User
    {
        // Billing-exempt so the subscription gate stays out of the way.
        $user = User::factory()->create($attributes + ['billing_exempt' => true]);
        app(EnrollmentService::class)->enroll($user, $sponsor);

        return $user->refresh();
    }

    public function test_the_tree_page_renders_the_whole_downline(): void
    {
        $root = $this->enroll(null, ['name' => 'Root Partner']);
        $a    = $this->enroll($root, ['name' => 'Direct Alpha']);
        $b    = $this->enroll($root, ['name' => 'Direct Beta']);
        $deep = $this->enroll($a, ['name' => 'Deep Gamma']);

        $this->actingAs($root)->get(route('member.network'))
            ->assertOk()
            ->assertSee('Direct Alpha')
            ->assertSee('Direct Beta')
            ->assertSee('Deep Gamma')      // two levels down, still rendered
            ->assertSee('Your organisation');
    }

    public function test_the_tree_shows_counts_and_levels(): void
    {
        $root = $this->enroll(null);
        $a    = $this->enroll($root);
        $this->enroll($a);
        $this->enroll($a);

        $tree = app(GenealogyService::class)->subtree($root);

        $this->assertSame(1, $tree['direct_count']);
        $this->assertSame(3, $tree['team_count']);

        $alpha = $tree['children'][0];
        $this->assertSame(2, $alpha['direct_count']);
        $this->assertSame(2, $alpha['team_count']);
        $this->assertSame(1, $alpha['depth']);
    }

    public function test_the_subtree_is_built_in_a_constant_number_of_queries(): void
    {
        // The naive implementation runs a count query per node, which is the
        // failure mode on exactly the screen this exists for.
        $root = $this->enroll(null);
        $a = $this->enroll($root);
        $b = $this->enroll($a);
        $this->enroll($b);
        $this->enroll($b);
        $this->enroll($a);

        DB::enableQueryLog();
        app(GenealogyService::class)->subtree($root->refresh());
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(2, $queries, "subtree() ran {$queries} queries; it should run one.");
    }

    public function test_an_organisation_too_large_to_draw_still_renders(): void
    {
        // A partner company's list can be millions of positions — iHub's is 1.3
        // million under one account. Before the ceiling, opening this page near
        // the top of that tree fetched every descendant into PHP and died on
        // the memory limit. The ceiling is lowered here so the fallback can be
        // exercised without building a million rows.
        config(['genealogy.max_tree_rows' => 3]);

        $root = $this->enroll(null, ['name' => 'Root Partner']);

        foreach (range(1, 6) as $i) {
            $this->enroll($root, ['name' => "Child {$i}"]);
        }

        $tree = app(GenealogyService::class)->subtree($root->refresh());

        $this->assertTrue($tree['overflowed'], 'the render should report that it could not draw everything');
        $this->assertNotEmpty($tree['children'], 'it should still draw what it could');

        // The node the member is actually looking at keeps its true total, even
        // though the rows behind it were not all loaded.
        $this->assertSame(6, $tree['team_count']);
    }

    public function test_deep_branches_are_truncated_but_still_counted(): void
    {
        config(['genealogy.tree_depth' => 2]);

        $root = $this->enroll(null);
        $a = $this->enroll($root);
        $b = $this->enroll($a, ['name' => 'Level Two']);
        $this->enroll($b, ['name' => 'Level Three Hidden']);

        $tree = app(GenealogyService::class)->subtree($root->refresh());
        $levelTwo = $tree['children'][0]['children'][0];

        $this->assertTrue($levelTwo['truncated']);
        $this->assertSame([], $levelTwo['children']);
        // Counted even though not rendered, so the "N more below" link is honest.
        $this->assertSame(1, $levelTwo['team_count']);
    }

    public function test_a_partner_can_re_root_the_tree_inside_their_own_downline(): void
    {
        $root = $this->enroll(null);
        $a    = $this->enroll($root, ['name' => 'Direct Alpha']);
        $this->enroll($a, ['name' => 'Deep Gamma']);

        $this->actingAs($root)->get(route('member.network', ['view_from' => $a->id]))
            ->assertOk()
            ->assertSee('Team below Direct Alpha')
            ->assertSee('Deep Gamma');
    }

    public function test_re_rooting_outside_your_downline_is_refused(): void
    {
        $root      = $this->enroll(null);
        $stranger  = $this->enroll(null, ['name' => 'Unrelated Person']);
        $this->enroll($stranger, ['name' => 'Stranger Team Member']);

        // Without this check, any id in the URL would expose the whole
        // company's genealogy to any signed-in partner.
        $this->actingAs($root)->get(route('member.network', ['view_from' => $stranger->id]))
            ->assertOk()
            ->assertDontSee('Stranger Team Member')
            ->assertSee('Your organisation');
    }

    public function test_contact_details_are_only_shown_for_your_own_recruits(): void
    {
        $root = $this->enroll(null);
        $a    = $this->enroll($root, ['email' => 'direct@example.com']);
        $this->enroll($a, ['email' => 'indirect@example.com']);

        $this->actingAs($root)->get(route('member.network'))
            ->assertOk()
            ->assertSee('direct@example.com')
            ->assertDontSee('indirect@example.com');
    }

    public function test_a_partner_with_no_team_gets_a_prompt_not_an_empty_box(): void
    {
        $root = $this->enroll(null);

        $this->actingAs($root)->get(route('member.network'))
            ->assertOk()
            ->assertSee('Nobody in your team yet')
            ->assertSee('Get my referral link');
    }

    public function test_dashboard_shows_genealogy_counts(): void
    {
        $root = $this->enroll(null);
        $a    = $this->enroll($root);
        $this->enroll($a);

        $this->actingAs($root)->get(route('member.dashboard'))
            ->assertOk()
            ->assertSee('Personally Enrolled')
            ->assertSee('Total Team');
    }

    public function test_referral_page_counts_come_from_the_genealogy(): void
    {
        $root = $this->enroll(null);
        $a    = $this->enroll($root, ['name' => 'Recruit One']);
        $this->enroll($a, ['name' => 'Indirect Two']);

        $this->actingAs($root)->get(route('member.referrals'))
            ->assertOk()
            ->assertSee('Recruit One')
            ->assertSee('Total Team');
    }
}
