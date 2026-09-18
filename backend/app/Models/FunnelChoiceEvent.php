<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One choice, kept.
 *
 * What people pick is the most useful thing a funnel produces: it is the only
 * direct evidence of what a prospect actually wants, and it is what tells you
 * which branch of a flow is worth keeping. The wording is snapshotted, because
 * a cue can be reworded or deleted afterwards and the record of what somebody
 * chose must not quietly change under it.
 *
 * @property-read Presentation|null $presentation
 */
class FunnelChoiceEvent extends Model
{
    protected $fillable = [
        'funnel_id', 'participant_id', 'presentation_id', 'cue_id',
        'kind', 'label', 'next_presentation_id', 'cta_item_id', 'at_seconds',
    ];

    protected $casts = ['at_seconds' => 'integer'];

    /** @return BelongsTo<FunnelParticipant, $this> */
    public function participant(): BelongsTo
    {
        return $this->belongsTo(FunnelParticipant::class, 'participant_id');
    }

    /** @return BelongsTo<PresentationFunnel, $this> */
    public function funnel(): BelongsTo
    {
        return $this->belongsTo(PresentationFunnel::class, 'funnel_id');
    }

    /**
     * The video they were watching when they chose.
     *
     * @return BelongsTo<Presentation, $this>
     */
    public function presentation(): BelongsTo
    {
        return $this->belongsTo(Presentation::class);
    }

    /** @return BelongsTo<Presentation, $this> */
    public function nextPresentation(): BelongsTo
    {
        return $this->belongsTo(Presentation::class, 'next_presentation_id');
    }

    /** @return BelongsTo<CtaItem, $this> */
    public function ctaItem(): BelongsTo
    {
        return $this->belongsTo(CtaItem::class, 'cta_item_id');
    }

    public function isBranch(): bool
    {
        return $this->kind === PresentationCue::KIND_BRANCH;
    }

    public function formattedAt(): string
    {
        return $this->at_seconds === null ? '—' : PresentationAttendee::clock($this->at_seconds);
    }

    /**
     * Only choices made by people this user may see.
     *
     * Joined through the participant, which is where attribution lives — a
     * member must not be able to read another member's prospects' choices, and
     * a report is exactly the place that boundary gets forgotten.
     *
     * @param Builder<FunnelChoiceEvent> $query
     * @return Builder<FunnelChoiceEvent>
     */
    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        if ($user === null) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->isAdmin()) {
            return $query;
        }

        return $query->whereHas('participant', fn ($q) => $q->where('host_user_id', $user->id));
    }
}
