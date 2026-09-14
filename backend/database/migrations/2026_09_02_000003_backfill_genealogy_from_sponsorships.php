<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the genealogy columns from the pre-existing `sponsorships` table.
 *
 * Without this, every account that existed before the genealogy was added has
 * a null sponsor_id and sits outside the tree — invisible to team counts and
 * unreachable from any upline.
 *
 * Two properties the source data does not guarantee and this migration has to
 * impose:
 *
 *   One sponsor per user. `sponsorships` is a many-to-many pivot, so a user can
 *   legitimately have several rows. A genealogy needs exactly one parent, so
 *   the earliest sponsorship wins — it is the one that actually introduced
 *   them, and it is stable across re-runs.
 *
 *   No cycles. A pivot table cannot express "a sponsors b, b sponsors a" as
 *   invalid, but a tree can never contain it. Paths are built top-down from the
 *   roots, so anyone reachable only through a cycle is left unplaced rather
 *   than corrupting the paths of everyone above them, and is reported below.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Earliest sponsorship per sponsored user.
        $sponsorOf = DB::table('sponsorships')
            ->orderBy('sponsored_id')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['sponsor_id', 'sponsored_id'])
            ->reduce(function (array $carry, $row) {
                $carry[$row->sponsored_id] ??= $row->sponsor_id;

                return $carry;
            }, []);

        // Self-sponsorship is meaningless and would make a one-node cycle.
        foreach ($sponsorOf as $child => $parent) {
            if ((int) $child === (int) $parent) {
                unset($sponsorOf[$child]);
            }
        }

        foreach ($sponsorOf as $child => $parent) {
            DB::table('users')->where('id', $child)->update(['sponsor_id' => $parent]);
        }

        // Build paths top-down, level by level. A user whose parent has no path
        // yet is deferred to the next pass; when a pass places nobody, whatever
        // remains is unreachable from any root — a cycle — and is left alone.
        $pending = DB::table('users')->pluck('sponsor_id', 'id')->all();
        $paths = [];

        while ($pending !== []) {
            $progressed = false;

            foreach ($pending as $id => $parentId) {
                $parentPath = null;

                if ($parentId !== null) {
                    if (! array_key_exists($parentId, $paths)) {
                        continue; // Parent not resolved yet — try next pass.
                    }
                    $parentPath = $paths[$parentId];
                }

                $paths[$id] = $parentPath === null ? (string) $id : $parentPath . '.' . $id;
                unset($pending[$id]);
                $progressed = true;
            }

            if (! $progressed) {
                break;
            }
        }

        $now = now();

        foreach ($paths as $id => $path) {
            DB::table('users')->where('id', $id)->update([
                'enrollment_path'     => $path,
                'placement_parent_id' => DB::table('users')->where('id', $id)->value('sponsor_id'),
                'placement_path'      => $path, // unilevel: the trees are identical
                'placement_status'    => User::PLACEMENT_PLACED,
                'placement_queued_at' => $now,
                'placed_at'           => $now,
            ]);
        }

        if ($pending !== []) {
            // Left queued rather than placed, so `network:place-queued --dry-run`
            // surfaces them instead of them vanishing silently.
            $ids = implode(', ', array_keys($pending));
            echo "\n  ! Sponsorship cycle: users [{$ids}] left unplaced. Resolve by hand.\n";
        }
    }

    public function down(): void
    {
        DB::table('users')->update([
            'sponsor_id'          => null,
            'placement_parent_id' => null,
            'enrollment_path'     => null,
            'placement_path'      => null,
            'placement_status'    => User::PLACEMENT_QUEUED,
            'placement_queued_at' => null,
            'placed_at'           => null,
        ]);
    }
};
