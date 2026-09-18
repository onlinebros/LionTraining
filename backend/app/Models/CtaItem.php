<?php

namespace App\Models;

use App\Support\PresentationCta;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Something a guest can be asked to do, written once and reused.
 *
 * "Book a call", "Get my report", "Join as a member" are the same three asks on
 * every customer-facing video, and retyping the wording per showing is how the
 * wording drifts. An item names the ask; where it *sends* somebody is resolved
 * per showing, because the destination always carries the inviting member's
 * referral code and that is not known until somebody is actually watching.
 */
class CtaItem extends Model
{
    protected $fillable = [
        'uuid', 'name', 'kind', 'headline', 'label', 'note', 'url', 'opens_in', 'is_active', 'created_by',
    ];

    protected $casts = ['is_active' => 'boolean'];

    protected static function booted(): void
    {
        static::creating(function (self $item) {
            $item->uuid ??= (string) Str::uuid();
        });
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Everywhere this item has been placed on a video.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<PresentationCue, $this>
     */
    public function cues(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PresentationCue::class, 'cta_item_id');
    }

    /**
     * @param Builder<CtaItem> $query
     * @return Builder<CtaItem>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** The wording a guest actually reads, falling back to the type's default. */
    public function effectiveLabel(): string
    {
        return $this->label ?: (PresentationCta::DEFAULTS[$this->kind]['label'] ?? 'Find out more');
    }

    public function effectiveHeadline(): string
    {
        return $this->headline ?: (PresentationCta::DEFAULTS[$this->kind]['headline'] ?? 'Interested?');
    }

    public function opensLabel(): string
    {
        return PresentationCta::OPENS[$this->opens_in] ?? PresentationCta::OPENS[PresentationCta::OPENS_NEW];
    }

    public function kindLabel(): string
    {
        return PresentationCta::TYPES[$this->kind] ?? $this->kind;
    }

    /**
     * Resolve this item against the member who invited the guest.
     *
     * Delegated rather than duplicated: there is one place that decides where a
     * call to action points, and adding a library of them must not become a
     * second place.
     */
    public function resolve(?User $host): PresentationCta
    {
        return PresentationCta::make(
            type:     $this->kind,
            headline: $this->headline,
            label:    $this->label,
            note:     $this->note,
            url:      $this->url,
            host:     $host,
            opensIn:  $this->opens_in,
        );
    }
}
