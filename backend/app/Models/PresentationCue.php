<?php

namespace App\Models;

use App\Support\PresentationCta;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A choice placed on a video at a moment.
 *
 * Two kinds, deliberately in one table: a cue either offers a call to action
 * that ends the journey, or branches to another video. To the person watching
 * they are the same thing — a button that appeared when it became relevant —
 * and the timing, the wording and the tracking are identical, so splitting them
 * would mean maintaining the same three things twice.
 */
class PresentationCue extends Model
{
    public const KIND_CTA    = 'cta';
    public const KIND_BRANCH = 'branch';

    public const KINDS = [
        self::KIND_CTA    => 'Call to action',
        self::KIND_BRANCH => 'Play another video',
    ];

    protected $fillable = [
        'presentation_id', 'kind', 'cta_item_id', 'next_presentation_id',
        'label', 'note', 'starts_at_seconds', 'ends_at_seconds', 'sort_order', 'is_active',
    ];

    protected $casts = [
        'starts_at_seconds' => 'integer',
        'ends_at_seconds'   => 'integer',
        'sort_order'        => 'integer',
        'is_active'         => 'boolean',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────

    /** @return BelongsTo<Presentation, $this> */
    public function presentation(): BelongsTo
    {
        return $this->belongsTo(Presentation::class);
    }

    /** @return BelongsTo<CtaItem, $this> */
    public function ctaItem(): BelongsTo
    {
        return $this->belongsTo(CtaItem::class, 'cta_item_id');
    }

    /** @return BelongsTo<Presentation, $this> */
    public function nextPresentation(): BelongsTo
    {
        return $this->belongsTo(Presentation::class, 'next_presentation_id');
    }

    /**
     * @param Builder<PresentationCue> $query
     * @return Builder<PresentationCue>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->orderBy('starts_at_seconds')->orderBy('sort_order')->orderBy('id');
    }

    // ── What the guest sees ───────────────────────────────────────────────────

    public function isBranch(): bool
    {
        return $this->kind === self::KIND_BRANCH;
    }

    /**
     * Is this cue coherent enough to show anybody?
     *
     * A branch with nothing to branch to, or a call to action whose item was
     * deleted, is a button that does nothing. Better it never appears than that
     * a prospect clicks it at the one moment they were ready to act.
     */
    public function isPlayable(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        return $this->isBranch()
            ? $this->nextPresentation !== null && ! $this->nextPresentation->isCancelled()
            : $this->ctaItem !== null && $this->ctaItem->is_active;
    }

    public function buttonLabel(): string
    {
        if (filled($this->label)) {
            return $this->label;
        }

        return $this->isBranch()
            ? ($this->nextPresentation?->title ?: 'Watch the next one')
            : ($this->ctaItem?->effectiveLabel() ?: 'Find out more');
    }

    public function headline(): ?string
    {
        return $this->isBranch() ? null : $this->ctaItem?->effectiveHeadline();
    }

    public function subtext(): ?string
    {
        return $this->note ?: ($this->isBranch() ? null : $this->ctaItem?->note);
    }

    /** Where a call-to-action cue sends them, for this inviting member. */
    public function resolveCta(?User $host): ?PresentationCta
    {
        return $this->isBranch() ? null : $this->ctaItem?->resolve($host);
    }

    /** Is the cue on screen at this point in the video? */
    public function isVisibleAt(int $seconds): bool
    {
        return $seconds >= $this->starts_at_seconds
            && ($this->ends_at_seconds === null || $seconds < $this->ends_at_seconds);
    }

    public function formattedStart(): string
    {
        return PresentationAttendee::clock($this->starts_at_seconds);
    }
}
