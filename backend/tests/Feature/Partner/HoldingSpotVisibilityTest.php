<?php

namespace Tests\Feature\Partner;

use App\Models\PartnerCompany;
use App\Models\Role;
use App\Models\User;
use App\Services\Partner\ActivationCode;
use App\Services\Genealogy\EnrollmentService;
use App\Services\Genealogy\GenealogyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Unclaimed positions hold their place and are counted nowhere else.
 *
 * The case that drives the design is a partly-claimed leg: an imported leg head
 * has not claimed, but somebody below them has. Naively filtering unclaimed
 * rows out of the tree orphans that partner — they disappear from the team of
 * the person whose team they are in. So the tree compresses instead, and these
 * tests pin that.
 */
class HoldingSpotVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private PartnerCompany $company;
    private User $founder;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => Role::FREE_MEMBER, 'display_name' => 'Free Member', 'is_admin' => false, 'level' => 1]);
        Role::create(['name' => Role::SUPER_ADMIN, 'display_name' => 'Super Admin', 'is_admin' => true, 'level' => 9]);

        $this->company = PartnerCompany::create(['slug' => 'acme', 'name' => 'iHub Global']);

        // billing_exempt so the member screens are reachable: the subscription
        // gate is not what these tests are about.
        $this->founder = User::factory()->create(['billing_exempt' => true]);
        app(EnrollmentService::class)->enroll($this->founder, null);
        $this->founder->refresh();
    }

    private function spot(string $externalId, User $parent): User
    {
        // Mirrors SpotImportCommitter: identifier and code, nothing personal.
        $spot = new User(['name' => "Spot {$externalId}"]);
        $spot->account_status       = User::ACCOUNT_HOLDING;
        $spot->partner_company_id   = $this->company->id;
        $spot->external_user_id     = $externalId;
        $spot->activation_code_hash = ActivationCode::hash('CODE-' . $externalId);
        $spot->imported_at          = now();
        $spot->is_active            = false;
        $spot->save();

        return app(EnrollmentService::class)->enrollImported($spot, $parent)->refresh();
    }

    private function member(User $parent): User
    {
        $user = User::factory()->create();

        return app(EnrollmentService::class)->enroll($user, $parent)->refresh();
    }

    private function genealogy(): GenealogyService
    {
        return app(GenealogyService::class);
    }

    // ── Counts ────────────────────────────────────────────────────────────────

    public function test_unclaimed_spots_are_not_counted_as_team(): void
    {
        $this->spot('A-1', $this->founder);
        $this->spot('A-2', $this->founder);
        $this->member($this->founder);

        $this->assertSame(1, $this->genealogy()->teamSize($this->founder));
        $this->assertSame(3, $this->genealogy()->teamSize($this->founder, includeHolding: true));
    }

    public function test_the_spot_counts_cover_the_first_level_and_stop_there(): void
    {
        $head    = $this->spot('A-1', $this->founder);
        $sibling = $this->spot('A-2', $this->founder);
        $deeper  = $this->spot('A-3', $head);

        // Claiming one flips it into the claimed column without moving it.
        $head->forceFill([
            'account_status' => User::ACCOUNT_ACTIVE,
            'is_active'      => true,
            'claimed_at'     => now(),
            'email'          => 'dana@example.com',
        ])->save();

        // Two positions directly below the founder, one now claimed. A-3 sits
        // under A-1 and is deliberately not counted: a partner is shown their
        // own first level, which is what they can act on, and the partner
        // company chases the rest from their own system.
        $this->assertSame(
            ['claimed' => 1, 'unclaimed' => 1, 'total' => 2],
            $this->genealogy()->directSpotCounts($this->founder),
        );
    }

    public function test_the_spots_page_lists_only_the_first_level(): void
    {
        $head = $this->spot('A-1', $this->founder);
        $this->spot('A-2', $head);

        $this->actingAs($this->founder)
            ->get(route('member.network.spots'))
            ->assertOk()
            ->assertSee('A-1')
            // Two levels down, and somebody else's to follow up.
            ->assertDontSee('A-2');
    }

    // ── The tree ──────────────────────────────────────────────────────────────

    public function test_the_tree_does_not_show_unclaimed_spots(): void
    {
        $this->spot('A-1', $this->founder);
        $member = $this->member($this->founder);

        $tree = $this->genealogy()->subtree($this->founder);

        $this->assertCount(1, $tree['children']);
        $this->assertSame($member->id, $tree['children'][0]['id']);
    }

    public function test_a_partner_under_an_unclaimed_spot_is_not_lost_from_the_tree(): void
    {
        // The partly-claimed leg. Without compression this partner vanishes
        // from the founder's team entirely.
        $unclaimedHead = $this->spot('A-1', $this->founder);
        $claimed = $this->spot('A-2', $unclaimedHead);

        $claimed->forceFill([
            'account_status' => User::ACCOUNT_ACTIVE,
            'is_active'      => true,
            'claimed_at'     => now(),
            'email'          => 'marcus@example.com',
            'name'           => 'Marcus Ortiz',
        ])->save();

        $tree = $this->genealogy()->subtree($this->founder->refresh());

        $this->assertCount(1, $tree['children']);
        $this->assertSame($claimed->id, $tree['children'][0]['id']);
        // And they sit at level 1, not level 2 — the unclaimed spot between
        // them occupies no level.
        $this->assertSame(1, $tree['children'][0]['depth']);
        $this->assertSame(1, $tree['team_count']);
    }

    public function test_levels_do_not_shift_under_people_as_claims_come_in(): void
    {
        $head    = $this->spot('A-1', $this->founder);
        $middle  = $this->spot('A-2', $head);
        $bottom  = $this->spot('A-3', $middle);

        $bottom->forceFill([
            'account_status' => User::ACCOUNT_ACTIVE,
            'is_active'      => true,
            'email'          => 'priya@example.com',
        ])->save();

        // Two unclaimed spots above them, so they are the founder's level 1.
        $this->assertSame([1 => 1], $this->genealogy()->teamCountsByLevel($this->founder)->all());

        $middle->forceFill([
            'account_status' => User::ACCOUNT_ACTIVE,
            'is_active'      => true,
            'email'          => 'marcus@example.com',
        ])->save();

        // Now one real partner stands between them: level 1 and level 2.
        $this->assertSame([1 => 1, 2 => 1], $this->genealogy()->teamCountsByLevel($this->founder)->all());
    }

    public function test_the_upline_skips_unclaimed_spots(): void
    {
        $head   = $this->spot('A-1', $this->founder);
        $member = $this->member($head);

        $upline = $this->genealogy()->upline($member->refresh());

        $this->assertSame([$this->founder->id], $upline->pluck('id')->all());
        $this->assertSame($this->founder->id, $this->genealogy()->nearestActivatedAncestor($member)->id);
    }

    // ── The screens ───────────────────────────────────────────────────────────

    public function test_the_team_page_shows_members_and_points_at_the_spots_page(): void
    {
        $this->spot('A-1', $this->founder);
        $member = $this->member($this->founder);

        $this->actingAs($this->founder)
            ->get(route('member.network'))
            ->assertOk()
            ->assertSee($member->name)
            ->assertDontSee('Spot A-1')
            ->assertSee('not claimed yet', false);
    }

    public function test_the_member_spots_page_shows_the_identifier_and_nothing_else(): void
    {
        $spot = $this->spot('A-1', $this->founder);

        // There is nothing else to show: an import carries identifiers and
        // structure, never the member's name, email or phone number. This is
        // the screen where that would leak if it were ever held.
        $this->assertNull($spot->email);
        $this->assertNull($spot->phone);

        $this->actingAs($this->founder)
            ->get(route('member.network.spots'))
            ->assertOk()
            ->assertSee('A-1')
            // Phrase chosen to sit on one source line: the view wraps, and
            // assertSee matches the rendered text literally.
            ->assertSee('given names or contact details', false);
    }

    public function test_the_admin_user_list_leaves_holding_spots_out(): void
    {
        $spot = $this->spot('A-1', $this->founder);

        $admin = User::factory()->create(['role_id' => Role::where('name', Role::SUPER_ADMIN)->value('id')]);

        $this->actingAs($admin)
            ->get(route('admin.users.index'))
            ->assertOk()
            ->assertDontSee($spot->name);
    }

    public function test_the_admin_spots_board_survives_its_own_cache(): void
    {
        // The board caches its per-company totals. The first version cached the
        // query builder's Collection of stdClass rows, which came back out of
        // the database cache store as __PHP_Incomplete_Class — a 500 on a live
        // admin page within two minutes of deploying it.
        //
        // Tests ran against the array cache driver and never round-tripped, so
        // nothing caught it. This loads the page twice against a store that
        // really serialises: the second load is the one that reads the cache.
        config(['cache.default' => 'database']);

        $this->spot('A-1', $this->founder);

        $admin = User::factory()->create(['role_id' => Role::where('name', Role::SUPER_ADMIN)->value('id')]);

        $this->actingAs($admin)->get(route('admin.partners.spots'))->assertOk();
        $this->actingAs($admin)->get(route('admin.partners.spots'))
            ->assertOk()
            ->assertSee('iHub Global');
    }

    public function test_the_admin_spots_board_is_where_they_do_appear(): void
    {
        $spot = $this->spot('A-1', $this->founder);

        $admin = User::factory()->create(['role_id' => Role::where('name', Role::SUPER_ADMIN)->value('id')]);

        $this->actingAs($admin)
            ->get(route('admin.partners.spots'))
            ->assertOk()
            ->assertSee('A-1')
            ->assertSee('Unclaimed');
    }
}
