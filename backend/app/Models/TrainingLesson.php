<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class TrainingLesson extends Model
{
    protected $fillable = [
        'category_id', 'title', 'slug', 'description', 'thumbnail',
        'required_role_id', 'sort_order', 'is_published', 'is_featured',
        'release_delay', 'release_delay_unit',
    ];

    protected $casts = [
        'is_published' => 'boolean',
        'is_featured'  => 'boolean',
    ];

    // ── Relationships ──────────────────────────────────────────────────────────

    public function category(): BelongsTo
    {
        return $this->belongsTo(TrainingCategory::class, 'category_id');
    }

    public function requiredRole(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'required_role_id');
    }

    public function contentBlocks(): HasMany
    {
        return $this->hasMany(TrainingContentBlock::class, 'lesson_id')
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function allContentBlocks(): HasMany
    {
        return $this->hasMany(TrainingContentBlock::class, 'lesson_id')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    public static function uniqueSlug(string $title, ?int $excludeId = null): string
    {
        $base = Str::slug($title);
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

    // ── Role access ────────────────────────────────────────────────────────────

    /** Lesson's required_role_id overrides category's if set. */
    public function effectiveRoleId(): ?int
    {
        return $this->required_role_id ?? $this->category?->required_role_id;
    }

    public function isRoleLocked(User $user): bool
    {
        $roleId = $this->effectiveRoleId();
        if (!$roleId) return false;
        $required = Role::find($roleId);
        if (!$required) return false;
        return !($user->role && $user->role->level >= $required->level);
    }

    // ── Time-based release ─────────────────────────────────────────────────────

    /**
     * Effective release delay: lesson's own delay first, then fall back to category's.
     * Returns ['value' => int, 'unit' => 'days'|'months'] or null for immediate.
     */
    public function effectiveReleaseDelay(): ?array
    {
        if ($this->release_delay) {
            return ['value' => (int) $this->release_delay, 'unit' => $this->release_delay_unit ?? 'days'];
        }
        if ($this->category?->release_delay) {
            return ['value' => (int) $this->category->release_delay, 'unit' => $this->category->release_delay_unit ?? 'days'];
        }
        return null;
    }

    /** Carbon date the lesson unlocks for this user, or null if already available. */
    public function availableAtForUser(User $user): ?\Carbon\Carbon
    {
        $delay = $this->effectiveReleaseDelay();
        if (!$delay) return null;
        if (!$user->active_start_date) return null;

        return $delay['unit'] === 'months'
            ? $user->active_start_date->copy()->addMonths($delay['value'])
            : $user->active_start_date->copy()->addDays($delay['value']);
    }

    public function isTimeLocked(User $user): bool
    {
        $delay = $this->effectiveReleaseDelay();
        if (!$delay) return false;
        if (!$user->active_start_date) return true; // delay configured but member has no start date
        $at = $this->availableAtForUser($user);
        return $at !== null && now()->lt($at);
    }

    public function userCanAccess(User $user): bool
    {
        return !$this->isRoleLocked($user) && !$this->isTimeLocked($user);
    }
}
