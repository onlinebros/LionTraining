<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * A flow of videos the viewer steers themselves.
 *
 * Each step is an ordinary always-open presentation, which is the whole design:
 * a funnel adds branching and a single carried identity, and inherits the room,
 * the chat, the isolation, the heartbeat and the reporting untouched.
 */
class PresentationFunnel extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'uuid', 'slug', 'title', 'description', 'entry_presentation_id',
        'is_active', 'member_shareable', 'created_by',
    ];

    protected $casts = [
        'is_active'        => 'boolean',
        'member_shareable' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $funnel) {
            $funnel->uuid ??= (string) Str::uuid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    // ── Relationships ─────────────────────────────────────────────────────────

    /**
     * Every video in the flow, in the order an admin arranged them.
     *
     * @return HasMany<Presentation, $this>
     */
    public function steps(): HasMany
    {
        return $this->hasMany(Presentation::class, 'funnel_id')
            ->orderBy('funnel_order')->orderBy('id');
    }

    /** @return BelongsTo<Presentation, $this> */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(Presentation::class, 'entry_presentation_id');
    }

    /** @return HasMany<FunnelParticipant, $this> */
    public function participants(): HasMany
    {
        return $this->hasMany(FunnelParticipant::class, 'funnel_id');
    }

    /** @return HasMany<FunnelChoiceEvent, $this> */
    public function choices(): HasMany
    {
        return $this->hasMany(FunnelChoiceEvent::class, 'funnel_id');
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    /**
     * @param Builder<PresentationFunnel> $query
     * @return Builder<PresentationFunnel>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Funnels this user may share.
     *
     * Admins can share anything, including one still being built. A member gets
     * only what head office has released, for the same reason a member can only
     * schedule a released recording: the flow is a sales conversation and not
     * everything in the library is fit to be one.
     *
     * @param Builder<PresentationFunnel> $query
     * @return Builder<PresentationFunnel>
     */
    public function scopeShareableBy(Builder $query, ?User $user): Builder
    {
        if ($user === null) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->isAdmin()) {
            return $query;
        }

        return $query->where('is_active', true)->where('member_shareable', true);
    }

    public function isShareableBy(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        return $user->isAdmin() || ($this->is_active && $this->member_shareable);
    }

    // ── Sharing ───────────────────────────────────────────────────────────────

    /**
     * A member's own link into the flow.
     *
     * Always through their referral code. A funnel link with no code is refused
     * on arrival, exactly like a presentation link — a prospect five videos deep
     * who belongs to nobody is worse than one who never started.
     */
    public function shareUrlFor(?User $user): string
    {
        return $user?->referral_code
            ? route('funnels.enter', ['funnel' => $this->slug, 'code' => $user->referral_code])
            : route('funnels.enter', ['funnel' => $this->slug]);
    }

    public function isReady(): bool
    {
        return $this->entry_presentation_id !== null;
    }

    public static function uniqueSlug(string $title): string
    {
        $base = Str::slug(Str::limit($title, 60, '')) ?: 'funnel';
        $slug = $base;
        $n    = 1;

        while (static::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.(++$n);
        }

        return $slug;
    }
}
