<?php

namespace App\Services\Genealogy;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The genealogy: who enrolled whom, and where everyone sits in the structure.
 *
 * Two trees are maintained side by side. `enrollment_path` records recruitment
 * and never changes. `placement_path` records position in the structure that
 * pays. Under the configured unilevel they are always identical; the code keeps
 * them distinct so a later switch to a structure with spillover is a config
 * change rather than a migration on live genealogy data.
 *
 * Paths are Postgres `ltree` values of ancestor ids, root first, ending in the
 * user's own id — user 317 sponsored by 42 sponsored by 1 has path `1.42.317`.
 * That makes "my whole downline" a single indexed `<@` query rather than a
 * recursive walk, and "my level 3" a depth filter on the same index.
 *
 * Postgres-specific by design. The reference specification calls Postgres a
 * hard requirement for this module rather than a preference.
 */
class GenealogyService
{
    public function __construct(private PlacementStrategy $strategy) {}

    // ── Enrollment ────────────────────────────────────────────────────────────

    /**
     * Attach a newly registered partner to their sponsor and queue placement.
     *
     * Sponsorship is written at registration and is permanent — it is not a
     * request the sponsor later accepts. A pending sponsorship would leave the
     * partner outside the tree during exactly the window the pre-launch phase
     * exists to fill.
     */
    public function enroll(User $user, ?User $sponsor): User
    {
        return DB::transaction(function () use ($user, $sponsor) {
            $user->sponsor_id = $sponsor?->id;
            $user->enrollment_path = $this->childPath($sponsor?->enrollment_path, $user->id);
            $user->placement_status = User::PLACEMENT_QUEUED;
            $user->placement_queued_at = Carbon::now();
            $user->save();

            return $user;
        });
    }

    // ── Placement ─────────────────────────────────────────────────────────────

    /**
     * Place a queued partner in the structure.
     *
     * Idempotent: a partner who is already placed is returned untouched, so a
     * retried job or a double-submitted registration cannot move someone who is
     * already positioned.
     */
    public function place(User $user): User
    {
        if ($user->placement_status === User::PLACEMENT_PLACED) {
            return $user;
        }

        if ($user->placement_status === User::PLACEMENT_EXCLUDED) {
            throw new RuntimeException("User {$user->id} is excluded from placement.");
        }

        return DB::transaction(function () use ($user) {
            $parent = $this->strategy->findParentFor($user);

            if ($parent !== null) {
                // Lock the parent for the duration of the transaction and re-read
                // it. Two registrations resolving the same parent concurrently
                // must not both compute a path from a stale row — in a unilevel
                // that would only risk a duplicate path, but the same lock is
                // what makes slot occupancy safe under binary or matrix, and
                // acquiring it here means the switch needs no new concurrency
                // reasoning.
                $parent = User::query()->lockForUpdate()->find($parent->id);

                if ($parent !== null && $parent->placement_status === User::PLACEMENT_EXCLUDED) {
                    // An excluded account is never positioned, so its
                    // placement_path stays null permanently and the queued-parent
                    // branch below would recurse into the guard at the top of
                    // place() — reporting the ancestor as the thing that failed
                    // and saying nothing about the signup that triggered it.
                    //
                    // Fail here instead, naming both sides. An excluded account
                    // being used as a sponsor is a configuration mistake to fix
                    // on the sponsor; it is not something to resolve by quietly
                    // rerooting the person who just signed up.
                    throw new RuntimeException(
                        "Cannot place user {$user->id} beneath user {$parent->id}: that account is "
                        .'excluded from placement, so it can never hold a position for anyone to sit '
                        .'under. Enroll it into the structure, or point the signup at another sponsor.'
                    );
                }

                if ($parent !== null && $parent->placement_path === null) {
                    // The parent is queued but not yet placed. Place them first
                    // so paths are always built on a positioned ancestor —
                    // otherwise a burst of signups can produce orphan subtrees.
                    $parent = $this->place($parent);
                }
            }

            $user->placement_parent_id = $parent?->id;
            $user->placement_path = $this->childPath($parent?->placement_path, $user->id);
            $user->placement_status = User::PLACEMENT_PLACED;
            $user->placed_at = Carbon::now();
            $user->save();

            return $user;
        });
    }

    /**
     * Place everyone still queued, oldest first.
     *
     * Ordering is `placement_queued_at` then `id` — deterministic, and
     * reconstructable from stored data when a partner asks why they sit where
     * they do. Never order by anything that can change.
     */
    public function placeQueued(?int $limit = null): int
    {
        $query = User::query()
            ->where('placement_status', User::PLACEMENT_QUEUED)
            ->orderBy('placement_queued_at')
            ->orderBy('id');

        if ($limit !== null) {
            $query->limit($limit);
        }

        $placed = 0;

        foreach ($query->get() as $user) {
            // Re-read inside place(); a user placed as someone else's ancestor
            // earlier in this loop is skipped by the idempotency check.
            $this->place($user->fresh());
            $placed++;
        }

        return $placed;
    }

    // ── Queries ───────────────────────────────────────────────────────────────

    /** Everyone below $user in the placement tree, excluding $user. */
    public function descendants(User $user): Builder
    {
        return $this->descendantsOfPath($user->placement_path)
            ->where('users.id', '!=', $user->id);
    }

    /** Everyone below $user in the enrollment tree, excluding $user. */
    public function enrollmentDescendants(User $user): Builder
    {
        if ($user->enrollment_path === null) {
            return User::query()->whereRaw('1 = 0');
        }

        return User::query()
            ->whereRaw('enrollment_path <@ ?::ltree', [$user->enrollment_path])
            ->where('users.id', '!=', $user->id);
    }

    /** $user's direct placements — one level down. */
    public function directs(User $user): Builder
    {
        return User::query()->where('placement_parent_id', $user->id);
    }

    /**
     * $user's upline, nearest ancestor first.
     *
     * Read straight off the path, so it costs one query regardless of depth.
     */
    public function upline(User $user): Collection
    {
        $ids = $this->pathIds($user->placement_path);

        // Drop the user's own id from the tail.
        array_pop($ids);

        if ($ids === []) {
            return collect();
        }

        $ancestors = User::query()->whereIn('id', $ids)->get()->keyBy('id');

        // Restore path order, then reverse so the direct upline comes first.
        return collect(array_reverse($ids))
            ->map(fn (int $id) => $ancestors->get($id))
            ->filter()
            ->values();
    }

    /**
     * Team size per level below $user, keyed by depth (1 = directs).
     *
     * One grouped query over the GIST index rather than a walk per level.
     */
    public function teamCountsByLevel(User $user, ?int $maxDepth = null): Collection
    {
        if ($user->placement_path === null) {
            return collect();
        }

        $maxDepth ??= (int) config('genealogy.tree_depth', 5);
        $ownDepth = $this->depthOf($user->placement_path);

        return $this->descendantsOfPath($user->placement_path)
            ->where('users.id', '!=', $user->id)
            ->whereRaw('nlevel(placement_path) <= ?', [$ownDepth + $maxDepth])
            ->selectRaw('nlevel(placement_path) - ? AS level, count(*) AS total', [$ownDepth])
            ->groupByRaw('nlevel(placement_path)')
            ->orderByRaw('nlevel(placement_path)')
            ->pluck('total', 'level');
    }

    /** Total placed team size below $user, at any depth. */
    public function teamSize(User $user): int
    {
        if ($user->placement_path === null) {
            return 0;
        }

        return $this->descendants($user)->count();
    }

    // ── Tree building ─────────────────────────────────────────────────────────

    /**
     * The placement subtree beneath $root as a nested array, ready to render.
     *
     * One query for the whole subtree, with every node's direct and team counts
     * derived from the paths in PHP. The obvious implementation — a count query
     * per node — is O(nodes) round trips and falls over on the exact screen this
     * exists for, a partner watching a large team.
     *
     * Nodes deeper than $maxDepth are counted but not returned; their parent is
     * marked `truncated` so the view can offer to re-root there.
     *
     * @return array<string,mixed>|null Null when $root has no path yet.
     */
    public function subtree(User $root, ?int $maxDepth = null): ?array
    {
        if ($root->placement_path === null) {
            return null;
        }

        $maxDepth ??= (int) config('genealogy.tree_depth', 5);
        $rootDepth = $this->depthOf($root->placement_path);

        $rows = User::query()
            ->whereRaw('placement_path <@ ?::ltree', [$root->placement_path])
            ->orderByRaw('nlevel(placement_path)')
            ->orderBy('id')
            ->get(['id', 'name', 'email', 'phone', 'is_active', 'sponsor_id',
                   'placement_parent_id', 'placement_path', 'placed_at', 'created_at']);

        // Team size per node, accumulated by walking each row's ancestors once.
        // O(rows × depth) rather than a query per node.
        $teamCounts = [];

        foreach ($rows as $row) {
            $ids = $this->pathIds($row->placement_path);
            array_pop($ids); // exclude self

            foreach ($ids as $ancestorId) {
                $teamCounts[$ancestorId] = ($teamCounts[$ancestorId] ?? 0) + 1;
            }
        }

        $childrenOf = [];

        foreach ($rows as $row) {
            if ($row->id !== $root->id) {
                $childrenOf[$row->placement_parent_id][] = $row;
            }
        }

        $build = function (User $node, int $depth) use (&$build, $childrenOf, $teamCounts, $root, $maxDepth, $rootDepth): array {
            $children = $childrenOf[$node->id] ?? [];
            $relativeDepth = $this->depthOf($node->placement_path) - $rootDepth;
            $truncated = $children !== [] && $relativeDepth >= $maxDepth;

            return [
                'id'           => $node->id,
                'name'         => $node->name,
                'email'        => $node->email,
                'phone'        => $node->phone,
                'is_active'    => (bool) $node->is_active,
                // Personally enrolled by the partner viewing the tree — the
                // distinction that matters to them, and the only rows whose
                // contact details they are shown.
                'is_direct'    => $node->sponsor_id === $root->id,
                'direct_count' => count($childrenOf[$node->id] ?? []),
                'team_count'   => $teamCounts[$node->id] ?? 0,
                'joined_at'    => $node->placed_at ?? $node->created_at,
                'depth'        => $relativeDepth,
                'truncated'    => $truncated,
                'children'     => $truncated ? [] : array_map(
                    fn (User $child) => $build($child, $depth + 1),
                    $children,
                ),
            ];
        };

        $rootRow = $rows->firstWhere('id', $root->id) ?? $root;

        return $build($rootRow, 0);
    }

    // ── Path helpers ──────────────────────────────────────────────────────────

    /** Build a child's path from its parent's. A null parent path means a root. */
    public function childPath(?string $parentPath, int $childId): string
    {
        return $parentPath === null || $parentPath === ''
            ? (string) $childId
            : $parentPath . '.' . $childId;
    }

    /** Depth of a path — a root is 1. */
    public function depthOf(?string $path): int
    {
        return $path === null || $path === '' ? 0 : count(explode('.', $path));
    }

    /** @return list<int> Ancestor ids in path order, including the user's own. */
    public function pathIds(?string $path): array
    {
        if ($path === null || $path === '') {
            return [];
        }

        return array_map('intval', explode('.', $path));
    }

    private function descendantsOfPath(?string $path): Builder
    {
        if ($path === null) {
            // whereRaw('1 = 0') rather than an empty collection so callers can
            // keep chaining and paginating against a real Builder.
            return User::query()->whereRaw('1 = 0');
        }

        return User::query()->whereRaw('placement_path <@ ?::ltree', [$path]);
    }
}
