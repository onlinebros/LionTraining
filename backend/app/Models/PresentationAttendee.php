<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A guest who registered for one showing.
 *
 * `host_user_id` is the isolation boundary for the whole feature. Every
 * visibility question — can this member see this guest, read their messages,
 * reply to them — reduces to a comparison against it, and the comparison lives
 * in exactly two places: `scopeVisibleTo()` here, and the policy.
 *
 * @property-read User|null $host
 */
class PresentationAttendee extends Model
{
    protected $fillable = [
        'presentation_id', 'host_user_id', 'funnel_participant_id', 'name', 'email', 'phone', 'token',
        'registered_at', 'first_joined_at', 'last_seen_at', 'joined_at_offset',
        'watch_seconds', 'position_seconds', 'furthest_seconds',
        'ip_address', 'user_agent', 'utm_source', 'utm_medium', 'utm_campaign',
        'cta_clicked_at',
        'converted_user_id', 'converted_at', 'conversion_match', 'cta_token', 'crm_contact_id',
    ];

    protected $casts = [
        'registered_at'    => 'datetime',
        'first_joined_at'  => 'datetime',
        'last_seen_at'     => 'datetime',
        'cta_clicked_at'   => 'datetime',
        'converted_at'     => 'datetime',
        'joined_at_offset' => 'integer',
        'watch_seconds'    => 'integer',
        'position_seconds' => 'integer',
        'furthest_seconds' => 'integer',
    ];

    /** A guest is considered present if we heard from them this recently. */
    public const PRESENCE_WINDOW_SECONDS = 60;

    // ── Relationships ─────────────────────────────────────────────────────────

    /** @return BelongsTo<Presentation, $this> */
    public function presentation(): BelongsTo
    {
        return $this->belongsTo(Presentation::class);
    }

    /**
     * The member who invited them. Null for the plain company link.
     *
     * @return BelongsTo<User, $this>
     */
    public function host(): BelongsTo
    {
        return $this->belongsTo(User::class, 'host_user_id');
    }

    /** @return \Illuminate\Database\Eloquent\Relations\HasMany<PresentationMessage, $this> */
    public function messages(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PresentationMessage::class, 'attendee_id')->orderBy('id');
    }

    /**
     * The funnel journey this row is one step of, if any.
     *
     * @return BelongsTo<FunnelParticipant, $this>
     */
    public function funnelParticipant(): BelongsTo
    {
        return $this->belongsTo(FunnelParticipant::class, 'funnel_participant_id');
    }

    /**
     * The attendee row this person's conversation actually hangs off.
     *
     * Inside a funnel that is the row they entered on, for every video they go
     * on to reach. A guest who branches keeps the thread they were already in,
     * because a member halfway through answering them should not have their
     * reply land in a room the prospect has left.
     */
    public function conversationAnchor(): self
    {
        $anchor = $this->funnelParticipant?->threadAttendee;

        return $anchor && $anchor->id !== $this->id ? $anchor : $this;
    }

    /**
     * The account this guest became, if they signed up.
     *
     * @return BelongsTo<User, $this>
     */
    public function convertedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'converted_user_id');
    }

    /** @return BelongsTo<CrmContact, $this> */
    public function crmContact(): BelongsTo
    {
        return $this->belongsTo(CrmContact::class, 'crm_contact_id');
    }

    public function hasConverted(): bool
    {
        return $this->converted_user_id !== null;
    }

    /**
     * They signed up under a different address to the one they watched under.
     *
     * Worth surfacing: it is the normal case, and a member looking at a list
     * needs to know the two records are the same person.
     */
    public function signedUpUnderAnotherEmail(): bool
    {
        return $this->hasConverted()
            && $this->convertedUser
            && mb_strtolower($this->convertedUser->email) !== mb_strtolower($this->email);
    }

    // ── Isolation ─────────────────────────────────────────────────────────────

    /**
     * Narrow a query to what this user is allowed to see.
     *
     * Admins see everyone. A member sees only the guests they personally
     * invited — never another member's, and never the unattributed guests who
     * arrived on the company link.
     *
     * This scope is the only sanctioned way to list attendees for a member. Any
     * query that skips it is a bug, and the isolation test suite exists to
     * catch exactly that.
     *
     * @param Builder<PresentationAttendee> $query
     * @return Builder<PresentationAttendee>
     */
    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        if ($user === null) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->isAdmin()) {
            return $query;
        }

        return $query->where('host_user_id', $user->id);
    }

    public function isVisibleTo(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        return $user->isAdmin() || $this->host_user_id === $user->id;
    }

    // ── Presence and progress ─────────────────────────────────────────────────

    public function isWatching(): bool
    {
        return $this->last_seen_at !== null
            && $this->last_seen_at->gt(now()->subSeconds(self::PRESENCE_WINDOW_SECONDS))
            && $this->presentation?->isLive();
    }

    public function hasJoined(): bool
    {
        return $this->first_joined_at !== null;
    }

    public function formattedJoinOffset(): string
    {
        return $this->joined_at_offset === null
            ? '—'
            : self::clock($this->joined_at_offset);
    }

    public function formattedWatchTime(): string
    {
        if ($this->watch_seconds < 60) {
            return $this->watch_seconds.'s';
        }

        return intdiv($this->watch_seconds, 60).'m';
    }

    /** How far through they have got — the number a member acts on. */
    public function progressPercent(int $duration): int
    {
        if ($duration < 1) {
            return 0;
        }

        return (int) min(100, round(($this->furthest_seconds / $duration) * 100));
    }

    public function formattedPosition(): string
    {
        return $this->position_seconds === null ? '—' : self::clock($this->position_seconds);
    }

    public static function clock(int $seconds): string
    {
        $hours   = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $secs    = $seconds % 60;

        return $hours > 0
            ? sprintf('%d:%02d:%02d', $hours, $minutes, $secs)
            : sprintf('%d:%02d', $minutes, $secs);
    }
}
