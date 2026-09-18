<?php

namespace Tests\Feature\Partner;

use App\Models\Role;
use App\Models\User;
use App\Services\Genealogy\EnrollmentService;
use App\Services\Genealogy\GenealogyService;
use App\Services\Genealogy\PositionMover;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Moving a position, which the rest of this module exists to avoid needing.
 *
 * "Placements are permanent" is the rule the import pipeline is built around, so
 * the exception has to be watertight: the branch arrives intact under its new
 * parent, keeps its internal shape, and nothing is left pointing where it used
 * to be.
 */
class PositionMoveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => Role::FREE_MEMBER, 'display_name' => 'Free Member', 'is_admin' => false, 'level' => 1]);
    }

    private function enroll(?User $sponsor, array $attributes = []): User
    {
        $user = User::factory()->create($attributes + ['billing_exempt' => true]);
        app(EnrollmentService::class)->enroll($user, $sponsor);

        return $user->refresh();
    }

    private function mover(): PositionMover
    {
        return app(PositionMover::class);
    }

    public function test_the_whole_branch_travels_and_keeps_its_shape(): void
    {
        $root   = $this->enroll(null, ['name' => 'Root']);
        $oldUp  = $this->enroll($root, ['name' => 'Old upline']);
        $newUp  = $this->enroll($root, ['name' => 'New upline']);

        $moving = $this->enroll($oldUp, ['name' => 'Moving']);
        $child  = $this->enroll($moving, ['name' => 'Child']);
        $grand  = $this->enroll($child, ['name' => 'Grandchild']);

        $rewritten = $this->mover()->move($moving->refresh(), $newUp->refresh());

        // The position and both descendants.
        $this->assertSame(3, $rewritten);

        $moving = $moving->refresh();
        $this->assertSame($newUp->id, $moving->placement_parent_id);
        $this->assertSame("{$newUp->placement_path}.{$moving->id}", $moving->placement_path);

        // The branch below it is unchanged in shape — still child, then
        // grandchild, in that order — just hanging somewhere else.
        $this->assertSame($moving->id, $child->refresh()->placement_parent_id);
        $this->assertSame($child->id, $grand->refresh()->placement_parent_id);
        $this->assertSame(
            "{$newUp->placement_path}.{$moving->id}.{$child->id}.{$grand->id}",
            $grand->refresh()->placement_path,
        );
    }

    public function test_the_old_upline_loses_the_branch_and_the_new_one_gains_it(): void
    {
        $root  = $this->enroll(null);
        $oldUp = $this->enroll($root);
        $newUp = $this->enroll($root);

        $moving = $this->enroll($oldUp);
        $this->enroll($moving);

        $genealogy = app(GenealogyService::class);

        $this->assertSame(2, $genealogy->teamSize($oldUp->refresh()));
        $this->assertSame(0, $genealogy->teamSize($newUp->refresh()));

        $this->mover()->move($moving->refresh(), $newUp->refresh());

        $this->assertSame(0, $genealogy->teamSize($oldUp->refresh()));
        $this->assertSame(2, $genealogy->teamSize($newUp->refresh()));
    }

    public function test_the_sponsor_moves_with_the_placement(): void
    {
        $root   = $this->enroll(null);
        $oldUp  = $this->enroll($root);
        $newUp  = $this->enroll($root, ['name' => 'New upline']);
        $moving = $this->enroll($oldUp);

        $this->mover()->move($moving->refresh(), $newUp->refresh());

        // A unilevel means these are the same relationship. Leaving the old
        // sponsor behind would credit somebody who is no longer anywhere near
        // them in the structure.
        $this->assertSame($newUp->id, $moving->refresh()->sponsor_id);

        // And the legacy table, which about fifteen screens still read.
        $this->assertDatabaseHas('sponsorships', [
            'sponsor_id' => $newUp->id, 'sponsored_id' => $moving->id, 'status' => 'active',
        ]);
        $this->assertDatabaseMissing('sponsorships', [
            'sponsor_id' => $oldUp->id, 'sponsored_id' => $moving->id,
        ]);
    }

    public function test_nothing_is_left_pointing_at_the_old_location(): void
    {
        $root   = $this->enroll(null);
        $oldUp  = $this->enroll($root);
        $newUp  = $this->enroll($root);
        $moving = $this->enroll($oldUp);
        $this->enroll($moving);

        $oldPath = $moving->refresh()->placement_path;

        $this->mover()->move($moving->refresh(), $newUp->refresh());

        $this->assertSame(0, User::whereRaw('placement_path <@ ?::ltree', [$oldPath])->count());
        $this->assertSame(0, User::whereRaw('enrollment_path <@ ?::ltree', [$oldPath])->count());
    }

    // ── What it refuses ───────────────────────────────────────────────────────

    public function test_a_position_cannot_be_moved_beneath_its_own_descendant(): void
    {
        $root   = $this->enroll(null);
        $moving = $this->enroll($root);
        $below  = $this->enroll($moving);

        // This would detach the branch from the tree and leave it circling
        // itself — every path under it rewritten to start with a path that is
        // itself inside it.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/end up below itself/');

        $this->mover()->move($moving->refresh(), $below->refresh());
    }

    public function test_a_merged_position_cannot_be_moved(): void
    {
        $root   = $this->enroll(null);
        $target = $this->enroll($root);
        $gone   = $this->enroll($root);

        $gone->forceFill([
            'merged_into_user_id' => $target->id,
            'account_status'      => User::ACCOUNT_MERGED,
            'placement_path'      => null,
        ])->save();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/must already hold a position/');

        $this->mover()->move($gone->refresh(), $target->refresh());
    }

    public function test_moving_somewhere_it_already_sits_is_refused(): void
    {
        $root   = $this->enroll(null);
        $moving = $this->enroll($root);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/already sits directly beneath/');

        $this->mover()->move($moving->refresh(), $root->refresh());
    }
}
