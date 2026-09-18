<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A message in one guest's private thread.
 *
 * Visibility is never decided here from scratch — it is always delegated to the
 * attendee, so there is exactly one definition of who can see a conversation.
 *
 * @property-read User|null $sender
 */
class PresentationMessage extends Model
{
    public const FROM_ATTENDEE = 'attendee';
    public const FROM_MEMBER   = 'member';
    public const FROM_ADMIN    = 'admin';

    protected $fillable = [
        'presentation_id', 'attendee_id', 'sender_type', 'sender_user_id', 'body', 'read_at',
    ];

    protected $casts = ['read_at' => 'datetime'];

    /** @return BelongsTo<Presentation, $this> */
    public function presentation(): BelongsTo
    {
        return $this->belongsTo(Presentation::class);
    }

    /** @return BelongsTo<PresentationAttendee, $this> */
    public function attendee(): BelongsTo
    {
        return $this->belongsTo(PresentationAttendee::class, 'attendee_id');
    }

    /** @return BelongsTo<User, $this> */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }

    /**
     * Messages in threads this user is allowed to read.
     *
     * @param Builder<PresentationMessage> $query
     * @return Builder<PresentationMessage>
     */
    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        if ($user === null) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->isAdmin()) {
            return $query;
        }

        return $query->whereHas('attendee', fn ($q) => $q->where('host_user_id', $user->id));
    }

    public function isFromGuest(): bool
    {
        return $this->sender_type === self::FROM_ATTENDEE;
    }

    /** What the guest sees as the author. Members answer under their own name. */
    public function displayName(): string
    {
        return match ($this->sender_type) {
            self::FROM_ATTENDEE => $this->attendee->name ?? 'Guest',
            self::FROM_ADMIN    => $this->sender?->name ?? 'Host',
            default             => $this->sender?->name ?? 'Your host',
        };
    }
}
