<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class TrainingCategory extends Model
{
    protected $fillable = [
        'parent_id', 'name', 'slug', 'description', 'thumbnail',
        'required_role_id', 'sort_order', 'is_active',
        'release_delay', 'release_delay_unit',
    ];

    protected $casts = ['is_active' => 'boolean'];

    // ── Relationships ──────────────────────────────────────────────────────────

    public function parent(): BelongsTo
    {
        return $this->belongsTo(TrainingCategory::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(TrainingCategory::class, 'parent_id')->orderBy('sort_order')->orderBy('name');
    }

    public function lessons(): HasMany
    {
        return $this->hasMany(TrainingLesson::class, 'category_id')->orderBy('sort_order')->orderBy('title');
    }

    public function requiredRole(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'required_role_id');
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    public static function uniqueSlug(string $name, ?int $excludeId = null): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $i    = 1;

        while (
            static::where('slug', $slug)
                ->when($excludeId, fn($q) => $q->where('id', '!=', $excludeId))
                ->exists()
        ) {
            $slug = $base . '-' . $i++;
        }

        return $slug;
    }

    /** Recursively load all ancestor category names for breadcrumb. */
    public function breadcrumb(): array
    {
        $chain = [];
        $cat   = $this;
        while ($cat) {
            array_unshift($chain, $cat);
            $cat = $cat->parent;
        }
        return $chain;
    }

    // ── Release delay helpers ──────────────────────────────────────────────────

    /** Returns ['value' => int, 'unit' => 'days'|'months'] or null if no delay. */
    public function releaseDelay(): ?array
    {
        if (!$this->release_delay) return null;
        return ['value' => (int) $this->release_delay, 'unit' => $this->release_delay_unit ?? 'days'];
    }

    /**
     * Carbon date when this category becomes available for the given user, or
     * null if immediately.
     *
     * Counted from the start of the member's paid membership — see
     * User::trainingClockStartedAt(), which explains why that is not the same
     * as when they signed up.
     */
    public function availableAtForUser(User $user): ?\Carbon\Carbon
    {
        $delay = $this->releaseDelay();
        if (!$delay) return null;

        $start = $user->trainingClockStartedAt();
        if (!$start) return null;

        return $delay['unit'] === 'months'
            ? $start->addMonths($delay['value'])
            : $start->addDays($delay['value']);
    }

    public function isTimeLocked(User $user): bool
    {
        $delay = $this->releaseDelay();
        if (!$delay) return false;
        // A delay is configured but the member's membership has not started, so
        // month one has not begun. Locked until it does.
        if (!$user->trainingClockStartedAt()) return true;
        $at = $this->availableAtForUser($user);
        return $at !== null && now()->lt($at);
    }

    public function userCanAccess(User $user): bool
    {
        if ($this->requiredRole && (!$user->role || $user->role->level < $this->requiredRole->level)) {
            return false;
        }
        return !$this->isTimeLocked($user);
    }

    /** Load all root categories with nested children (recursive). */
    public static function tree(): \Illuminate\Database\Eloquent\Collection
    {
        return static::whereNull('parent_id')
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->with('children.children.children') // 3 levels deep
            ->get();
    }
}
