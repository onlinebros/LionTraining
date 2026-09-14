<?php

namespace Tests\Feature\Prelaunch;

use App\Models\Role;
use App\Models\Sponsorship;
use App\Models\User;
use App\Services\Genealogy\EnrollmentService;
use App\Services\Genealogy\GenealogyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The pre-launch phase's core promise: someone follows a referral link, and
 * their position in the structure is real, visible and permanent immediately.
 */
class GenealogyTest extends TestCase
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

    private function genealogy(): GenealogyService
    {
        return app(GenealogyService::class);
    }

    private function enrollment(): EnrollmentService
    {
        return app(EnrollmentService::class);
    }

    /** Enroll a chain root → a → b → c and return them. */
    private function chain(int $depth): array
    {
        $users = [];
        $parent = null;

        for ($i = 0; $i < $depth; $i++) {
            $user = User::factory()->create();
            $this->enrollment()->enroll($user, $parent);
            $users[] = $user->refresh();
            $parent = $user;
        }

        return $users;
    }

    // ── Registration ──────────────────────────────────────────────────────────

    public function test_registering_via_a_referral_link_places_the_partner_immediately(): void
    {
        $sponsor = User::factory()->create();
        $this->enrollment()->enroll($sponsor, null);
        $sponsor->refresh();

        $this->post(route('join.post', $sponsor->referral_code), [
            'name' => 'New Partner',
            'email' => 'new@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertRedirect(route('member.dashboard'));

        $partner = User::where('email', 'new@example.com')->firstOrFail();

        $this->assertSame($sponsor->id, $partner->sponsor_id);
        $this->assertSame($sponsor->id, $partner->placement_parent_id);
        $this->assertSame(User::PLACEMENT_PLACED, $partner->placement_status);
        $this->assertNotNull($partner->placed_at);

        // Position is real from minute one, not applied in a launch-day batch.
        $this->assertSame("{$sponsor->id}.{$partner->id}", $partner->placement_path);
    }

    public function test_sponsorship_is_active_immediately_not_pending(): void
    {
        $sponsor = User::factory()->create();
        $this->enrollment()->enroll($sponsor, null);

        $partner = User::factory()->create();
        $this->enrollment()->enroll($partner, $sponsor->refresh());

        // A sponsorship awaiting approval would leave the partner outside the
        // tree during exactly the window pre-launch exists to fill.
        $this->assertDatabaseHas('sponsorships', [
            'sponsor_id'   => $sponsor->id,
            'sponsored_id' => $partner->id,
            'status'       => 'active',
        ]);
    }

    public function test_the_genealogy_and_the_legacy_table_never_disagree(): void
    {
        [$root, $child] = $this->chain(2);

        $legacy = Sponsorship::where('sponsored_id', $child->id)->firstOrFail();

        $this->assertSame($child->sponsor_id, $legacy->sponsor_id);
        $this->assertSame(1, Sponsorship::where('sponsored_id', $child->id)->count());
    }

    public function test_registering_without_a_referral_creates_a_root(): void
    {
        // The general signup is invitation-only by default now, so this opens
        // it explicitly. The behaviour under test is what the controller does
        // with an unreferred signup — still reachable whenever the door is
        // open, and the shape `founder:create` relies on. Who is allowed
        // through the door is Tests\Feature\Registration\InviteOnlyTest.
        config(['registration.invite_only' => false]);

        $this->post(route('register.post'), [
            'name' => 'Root Partner',
            'email' => 'root@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertRedirect(route('member.dashboard'));

        $partner = User::where('email', 'root@example.com')->firstOrFail();

        $this->assertNull($partner->sponsor_id);
        $this->assertSame((string) $partner->id, $partner->placement_path);
        $this->assertTrue($partner->isPlaced());
    }

    public function test_unreferred_signups_go_under_the_house_account_when_configured(): void
    {
        $house = User::factory()->create();
        $this->enrollment()->enroll($house, null);
        config(['genealogy.default_sponsor_id' => $house->id]);

        $partner = User::factory()->create();
        $this->enrollment()->enroll($partner, null);

        $this->assertSame($house->id, $partner->refresh()->sponsor_id);
    }

    // ── Paths and queries ─────────────────────────────────────────────────────

    public function test_paths_are_built_from_the_full_ancestor_chain(): void
    {
        [$a, $b, $c] = $this->chain(3);

        $this->assertSame("{$a->id}", $a->placement_path);
        $this->assertSame("{$a->id}.{$b->id}", $b->placement_path);
        $this->assertSame("{$a->id}.{$b->id}.{$c->id}", $c->placement_path);
    }

    public function test_descendants_returns_the_whole_downline_at_any_depth(): void
    {
        [$a, $b, $c] = $this->chain(3);

        $ids = $this->genealogy()->descendants($a)->pluck('id')->sort()->values()->all();

        $this->assertSame([$b->id, $c->id], $ids);
        $this->assertSame(2, $this->genealogy()->teamSize($a));
        $this->assertSame(0, $this->genealogy()->teamSize($c));
    }

    public function test_upline_is_returned_nearest_ancestor_first(): void
    {
        [$a, $b, $c] = $this->chain(3);

        $upline = $this->genealogy()->upline($c);

        $this->assertSame([$b->id, $a->id], $upline->pluck('id')->all());
        $this->assertTrue($this->genealogy()->upline($a)->isEmpty());
    }

    public function test_team_counts_are_grouped_by_level_below_the_partner(): void
    {
        [$a, $b] = $this->chain(2);

        // Two more directs under $a, and one under $b.
        foreach (range(1, 2) as $_) {
            $u = User::factory()->create();
            $this->enrollment()->enroll($u, $a);
        }

        $deep = User::factory()->create();
        $this->enrollment()->enroll($deep, $b);

        $counts = $this->genealogy()->teamCountsByLevel($a);

        $this->assertSame(3, (int) $counts[1]); // $b plus the two directs
        $this->assertSame(1, (int) $counts[2]); // $deep, under $b
    }

    // ── Placement queue ───────────────────────────────────────────────────────

    public function test_placement_is_idempotent(): void
    {
        [$a, $b] = $this->chain(2);

        $originalPath = $b->placement_path;
        $originalPlacedAt = $b->placed_at;

        $this->genealogy()->place($b->refresh());

        $b->refresh();
        $this->assertSame($originalPath, $b->placement_path);
        $this->assertEquals($originalPlacedAt, $b->placed_at);
    }

    public function test_excluded_accounts_are_never_placed(): void
    {
        $user = User::factory()->create(['placement_status' => User::PLACEMENT_EXCLUDED]);

        $this->expectException(\RuntimeException::class);
        $this->genealogy()->place($user);
    }

    public function test_the_queue_is_drained_in_a_deterministic_order(): void
    {
        $root = User::factory()->create();
        $this->enrollment()->enroll($root, null);

        // Queued directly, bypassing enroll(), the way an importer or a failed
        // registration transaction would leave them.
        $queued = collect(range(1, 3))->map(function (int $i) use ($root) {
            return User::factory()->create([
                'sponsor_id' => $root->id,
                'placement_status' => User::PLACEMENT_QUEUED,
                'placement_queued_at' => now()->addSeconds($i),
            ]);
        });

        $placed = $this->genealogy()->placeQueued();

        $this->assertSame(3, $placed);

        foreach ($queued as $user) {
            $this->assertTrue($user->refresh()->isPlaced());
            $this->assertSame("{$root->id}.{$user->id}", $user->placement_path);
        }
    }

    public function test_a_queued_parent_is_placed_before_its_child(): void
    {
        $root = User::factory()->create();
        $this->enrollment()->enroll($root, null);
        $root->refresh();

        // Parent and child both queued, with the CHILD first in queue order —
        // the ordering that would otherwise produce an orphan subtree.
        $parent = User::factory()->create([
            'sponsor_id' => $root->id,
            'placement_status' => User::PLACEMENT_QUEUED,
            'placement_queued_at' => now()->addSeconds(10),
        ]);

        $child = User::factory()->create([
            'sponsor_id' => $parent->id,
            'placement_status' => User::PLACEMENT_QUEUED,
            'placement_queued_at' => now()->addSeconds(1),
        ]);

        $this->genealogy()->placeQueued();

        $parent->refresh();
        $child->refresh();

        $this->assertTrue($parent->isPlaced());
        $this->assertSame("{$root->id}.{$parent->id}.{$child->id}", $child->placement_path);
    }

    public function test_place_queued_command_reports_what_it_did(): void
    {
        $root = User::factory()->create();
        $this->enrollment()->enroll($root, null);

        User::factory()->create([
            'sponsor_id' => $root->refresh()->id,
            'placement_status' => User::PLACEMENT_QUEUED,
            'placement_queued_at' => now(),
        ]);

        $this->artisan('network:place-queued --dry-run')->assertSuccessful();

        // Dry run must not have placed anyone.
        $this->assertSame(1, User::where('placement_status', User::PLACEMENT_QUEUED)->count());

        $this->artisan('network:place-queued')->assertSuccessful();
        $this->assertSame(0, User::where('placement_status', User::PLACEMENT_QUEUED)->count());
    }

    // ── Preview command ───────────────────────────────────────────────────────

    public function test_preview_command_toggles_the_flag(): void
    {
        $user = User::factory()->create(['email' => 'founder@example.com']);

        $this->artisan('prelaunch:preview founder@example.com --enable')->assertSuccessful();
        $this->assertTrue($user->refresh()->prelaunch_preview);

        $this->artisan('prelaunch:preview founder@example.com --disable')->assertSuccessful();
        $this->assertFalse($user->refresh()->prelaunch_preview);
    }

    public function test_preview_command_requires_exactly_one_flag(): void
    {
        User::factory()->create(['email' => 'founder@example.com']);

        $this->artisan('prelaunch:preview founder@example.com')->assertFailed();
        $this->artisan('prelaunch:preview founder@example.com --enable --disable')->assertFailed();
    }
}
