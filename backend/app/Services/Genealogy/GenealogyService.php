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
    /**
     * The most rows one tree render will pull.
     *
     * Not a display limit — the view already truncates by depth — but a ceiling
     * on what a single request will put in memory. An organisation of a million
     * positions is now a thing this application holds, and a partner near the
     * top of one should get a usable page rather than an out-of-memory error.
     */
    public const MAX_TREE_ROWS = 3000;

    public function __construct(private PlacementStrategy $strategy) {}

    /** The ceiling in force, so a test can exercise the fallback cheaply. */
    private function maxTreeRows(): int
    {
        return (int) config('genealogy.max_tree_rows', self::MAX_TREE_ROWS);
    }

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
     * Place a partner at a position that is given rather than computed.
     *
     * Used by the partner-spot importer, and only by it. Everywhere else
     * position is derived from the sponsor by the placement strategy; an
     * imported list already *is* a structure, agreed with another company, and
     * recomputing it would silently reshape somebody's organisation on the way
     * in.
     *
     * The parent must already hold a position. The importer guarantees that by
     * committing parents before children, and the check is here rather than
     * left to fail on a null path because a path built from a null parent path
     * silently produces a new root — a whole leg quietly detached from the
     * partner it was meant to hang beneath.
     */
    public function placeUnder(User $user, User $parent): User
    {
        if ($user->placement_status === User::PLACEMENT_PLACED) {
            return $user;
        }

        return DB::transaction(function () use ($user, $parent) {
            $parent = User::query()->lockForUpdate()->find($parent->id);

            if ($parent === null) {
                throw new RuntimeException("Cannot place user {$user->id}: the parent no longer exists.");
            }

            if ($parent->placement_status === User::PLACEMENT_EXCLUDED) {
                throw new RuntimeException(
                    "Cannot place user {$user->id} beneath user {$parent->id}: that account is "
                    .'excluded from placement, so it can never hold a position for anyone to sit under.'
                );
            }

            if ($parent->placement_path === null) {
                throw new RuntimeException(
                    "Cannot place user {$user->id} beneath user {$parent->id}: that account has no "
                    .'position yet. Imported positions must be committed parents-first.'
                );
            }

            $user->placement_parent_id = $parent->id;
            $user->placement_path = $this->childPath($parent->placement_path, $user->id);
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
    //
    // Everything here counts and returns activated accounts only. Unclaimed
    // holding spots brought in from a partner company hold a position in the
    // tree but are not members: counting them inflates every team number on
    // every screen, and showing them puts rows with no owner in front of people
    // who will try to contact them.
    //
    // Pass $includeHolding to see the structure as it physically is. Two
    // callers want that — the spots screens, and the importer — and they say so
    // explicitly.

    /** Everyone below $user in the placement tree, excluding $user. */
    public function descendants(User $user, bool $includeHolding = false): Builder
    {
        $query = $this->descendantsOfPath($user->placement_path)
            ->where('users.id', '!=', $user->id);

        return $includeHolding ? $query : $query->activated();
    }

    /** Everyone below $user in the enrollment tree, excluding $user. */
    public function enrollmentDescendants(User $user, bool $includeHolding = false): Builder
    {
        if ($user->enrollment_path === null) {
            return User::query()->whereRaw('1 = 0');
        }

        $query = User::query()
            ->whereRaw('enrollment_path <@ ?::ltree', [$user->enrollment_path])
            ->where('users.id', '!=', $user->id);

        return $includeHolding ? $query : $query->activated();
    }

    /**
     * $user's direct placements — one level down, literally.
     *
     * "Literally" matters: this is the physical child set, so with holding
     * excluded it does NOT include an activated partner sitting under an
     * unclaimed spot. For the number a partner should be shown as their first
     * level, use teamCountsByLevel(), which compresses.
     */
    public function directs(User $user, bool $includeHolding = false): Builder
    {
        $query = User::query()->where('placement_parent_id', $user->id);

        return $includeHolding ? $query : $query->activated();
    }

    /**
     * $user's upline, nearest ancestor first.
     *
     * Read straight off the path, so it costs one query regardless of depth.
     * Unclaimed spots are dropped, which is the same compression the rest of
     * this class applies looking downwards: the person above you is the nearest
     * one who is actually there.
     */
    public function upline(User $user, bool $includeHolding = false): Collection
    {
        $ids = $this->pathIds($user->placement_path);

        // Drop the user's own id from the tail.
        array_pop($ids);

        if ($ids === []) {
            return collect();
        }

        $query = User::query()->whereIn('id', $ids);

        if (! $includeHolding) {
            $query->activated();
        }

        $ancestors = $query->get()->keyBy('id');

        // Restore path order, then reverse so the direct upline comes first.
        return collect(array_reverse($ids))
            ->map(fn (int $id) => $ancestors->get($id))
            ->filter()
            ->values();
    }

    /**
     * The nearest ancestor who is a real member.
     *
     * This is compression, and it is what a commission walk should climb rather
     * than placement_parent_id: an unclaimed spot has no owner and no payout
     * account, so an amount that lands on one is an amount nobody receives.
     * Skipping it pays the nearest partner who is actually there, which is also
     * the answer that stops an activated partner being penalised for a downline
     * that has not finished claiming.
     */
    public function nearestActivatedAncestor(User $user): ?User
    {
        return $this->upline($user)->first();
    }

    /**
     * Team size per level below $user, keyed by level (1 = their first level).
     *
     * Levels are compressed: an unclaimed spot occupies no level, so a partner
     * who claimed beneath one counts on the level their nearest activated
     * upline sees them on. Without that, a leg whose head has not claimed yet
     * reports everybody below it one level deeper than they will be the day it
     * does — the level ladder would shift under people as claims came in.
     */
    public function teamCountsByLevel(User $user, ?int $maxDepth = null): Collection
    {
        if ($user->placement_path === null) {
            return collect();
        }

        $maxDepth ??= (int) config('genealogy.tree_depth', 5);
        $compression = $this->compressionMap($user);

        $counts = [];

        foreach ($compression['depth'] as $depth) {
            if ($depth >= 1 && $depth <= $maxDepth) {
                $counts[$depth] = ($counts[$depth] ?? 0) + 1;
            }
        }

        ksort($counts);

        return collect($counts);
    }

    /** Total placed team size below $user, at any depth. */
    public function teamSize(User $user, bool $includeHolding = false): int
    {
        if ($user->placement_path === null) {
            return 0;
        }

        return $this->descendants($user, $includeHolding)->count();
    }

    /**
     * Imported positions sitting *directly* beneath $user, claimed and not.
     *
     * Directly, not anywhere below. A partner does not need — and should not be
     * handed — a list of every unclaimed position in an organisation of a
     * million: their own first level is what they can act on, and the partner
     * company is the one chasing the rest, from their own system, using the
     * activations we send them.
     *
     * It is also the difference between an indexed lookup on
     * `placement_parent_id` and an aggregate over a million-row ltree range.
     *
     * @return array{claimed:int, unclaimed:int, total:int}
     */
    public function directSpotCounts(User $user): array
    {
        $row = User::query()
            ->where('placement_parent_id', $user->id)
            ->whereNotNull('partner_company_id')
            ->selectRaw('count(*) AS total')
            ->selectRaw('count(*) FILTER (WHERE account_status = ?) AS unclaimed', [User::ACCOUNT_HOLDING])
            ->first();

        $total     = (int) ($row->total ?? 0);
        $unclaimed = (int) ($row->unclaimed ?? 0);

        return [
            'claimed'   => $total - $unclaimed,
            'unclaimed' => $unclaimed,
            'total'     => $total,
        ];
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
     * Unclaimed holding spots are removed and their activated descendants
     * re-hung on the nearest ancestor who is a real member. That re-hanging is
     * not cosmetic: simply filtering the rows out would orphan every partner
     * sitting under an unclaimed spot, and they would vanish from the tree of
     * the person whose team they are in. Which is precisely the arrangement a
     * partial claim produces — an imported leg head has not claimed yet, but
     * three people under them have.
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

        // Ask for one row more than the ceiling. Getting fewer means the whole
        // subtree is in hand and every count below is exact — which is the case
        // for essentially every partner, and the behaviour this method has
        // always had.
        $rows = $this->subtreeRows($root)->limit($this->maxTreeRows() + 1)->get();

        $overflowed = $rows->count() > $this->maxTreeRows();

        if ($overflowed) {
            // An organisation too large to hold in one request. The iHub import
            // put 1.3 million positions under one account, and its widest node
            // has 6,035 direct children, so this is not hypothetical: before
            // the ceiling, opening My Team near the top of that tree fetched
            // 1,304,353 models against a 256MB limit. That is not a slow page,
            // it is a 500.
            //
            // Fall back to the levels that actually render. The counts on the
            // nodes below then describe what was loaded rather than everything
            // beneath them, so the view is told, and the root — the one node
            // the member is really looking at — gets its true total from a
            // single indexed count.
            $rows = $this->subtreeRows($root)
                ->whereRaw('nlevel(placement_path) <= ?', [$this->depthOf($root->placement_path) + $maxDepth])
                ->limit($this->maxTreeRows())
                ->get();
        }

        $compression = $this->compress($rows, $root);

        // Only rows that are members get rendered. The root is kept whatever it
        // is, so an admin can open the tree from a holding spot.
        $visible = $rows->filter(
            fn (User $row) => $row->id === $root->id || $row->account_status === User::ACCOUNT_ACTIVE,
        );

        // Team size per node, accumulated by walking each visible row's
        // activated ancestors once. O(rows × depth) rather than a query per
        // node, and it counts the same population the tree draws.
        $teamCounts = [];

        foreach ($visible as $row) {
            if ($row->id === $root->id) {
                continue;
            }

            foreach ($compression['ancestors'][$row->id] ?? [] as $ancestorId) {
                $teamCounts[$ancestorId] = ($teamCounts[$ancestorId] ?? 0) + 1;
            }
        }

        $childrenOf = [];

        foreach ($visible as $row) {
            if ($row->id !== $root->id) {
                $childrenOf[$compression['parent'][$row->id]][] = $row;
            }
        }

        $build = function (User $node, int $depth) use (&$build, $childrenOf, $teamCounts, $compression, $root, $maxDepth): array {
            $children = $childrenOf[$node->id] ?? [];
            $relativeDepth = $node->id === $root->id ? 0 : ($compression['depth'][$node->id] ?? 0);
            $truncated = $children !== [] && $relativeDepth >= $maxDepth;

            return [
                'id'           => $node->id,
                'name'         => $node->name,
                'email'        => $node->email,
                'phone'        => $node->phone,
                'is_active'    => (bool) $node->is_active,
                'is_holding'   => $node->account_status === User::ACCOUNT_HOLDING,
                // Personally enrolled by the partner viewing the tree — the
                // distinction that matters to them, and the only rows whose
                // contact details they are shown.
                'is_direct'    => $node->sponsor_id === $root->id,
                'direct_count' => count($children),
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

        $tree = $build($rootRow, 0);
        $tree['overflowed'] = $overflowed;

        if ($overflowed) {
            $tree['team_count'] = $this->teamSize($root);
        }

        return $tree;
    }

    /**
     * The rows a tree render reads, in the order it needs them.
     *
     * Shared between the ordinary path and the bounded fallback so the two
     * cannot select different columns or order differently — the build step
     * depends on parents arriving before children.
     *
     * @return Builder<User>
     */
    private function subtreeRows(User $root): Builder
    {
        return User::query()
            ->whereRaw('placement_path <@ ?::ltree', [$root->placement_path])
            ->orderByRaw('nlevel(placement_path)')
            ->orderBy('id')
            ->select(['id', 'name', 'email', 'phone', 'is_active', 'account_status', 'sponsor_id',
                      'placement_parent_id', 'placement_path', 'placed_at', 'created_at']);
    }

    // ── Compression ───────────────────────────────────────────────────────────

    /**
     * Where each activated descendant sits once unclaimed spots are ignored.
     *
     * For every row below $root this returns the nearest activated ancestor
     * (falling back to $root), how many activated ancestors stand between the
     * two, and the full list of activated ancestors so a caller can accumulate
     * team counts in one pass.
     *
     * @param  \Illuminate\Support\Collection<int, User>  $rows  The whole subtree, root included.
     * @return array{parent: array<int,int>, depth: array<int,int>, ancestors: array<int,list<int>>}
     */
    private function compress($rows, User $root): array
    {
        $activated = [];

        foreach ($rows as $row) {
            $activated[$row->id] = $row->account_status === User::ACCOUNT_ACTIVE;
        }

        $parent = $depth = $ancestors = [];

        foreach ($rows as $row) {
            if ($row->id === $root->id) {
                continue;
            }

            $ids = $this->pathIds($row->placement_path);

            // Keep only what lies strictly between the root and this row.
            $rootAt = array_search($root->id, $ids, true);
            $between = $rootAt === false
                ? array_slice($ids, 0, -1)
                : array_slice($ids, $rootAt + 1, count($ids) - $rootAt - 2);

            $chain = array_values(array_filter($between, fn (int $id) => $activated[$id] ?? false));

            $ancestors[$row->id] = array_merge($chain, [$root->id]);
            $parent[$row->id]    = $chain === [] ? $root->id : end($chain);
            $depth[$row->id]     = count($chain) + 1;
        }

        return ['parent' => $parent, 'depth' => $depth, 'ancestors' => $ancestors];
    }

    /**
     * compress() for a user whose subtree has not already been loaded.
     *
     * Reads the three columns compression needs and nothing else, and drops
     * unclaimed spots from the result — callers want levels for members.
     *
     * @return array{parent: array<int,int>, depth: array<int,int>, ancestors: array<int,list<int>>}
     */
    private function compressionMap(User $root): array
    {
        $rows = User::query()
            ->whereRaw('placement_path <@ ?::ltree', [$root->placement_path])
            ->orderByRaw('nlevel(placement_path)')
            ->get(['id', 'account_status', 'placement_path']);

        $map = $this->compress($rows, $root);

        foreach ($rows as $row) {
            if ($row->account_status !== User::ACCOUNT_ACTIVE) {
                unset($map['parent'][$row->id], $map['depth'][$row->id], $map['ancestors'][$row->id]);
            }
        }

        return $map;
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
