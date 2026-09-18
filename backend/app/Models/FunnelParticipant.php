<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * One person moving through one funnel.
 *
 * This is the row that makes a funnel hold together. A prospect who follows
 * three branches is three attendee rows on three presentations; without a
 * participant they are three unrelated strangers and nobody can say whose they
 * are. `host_user_id` is decided once, when they enter, and carried for the
 * rest of the journey — the same isolation boundary as an attendee, applied to
 * the whole path rather than a single video.
 *
 * The conversation is anchored here too. A guest who moves to the next video
 * keeps the thread they were already in, because a member mid-sentence should
 * not have their reply land in a room the prospect has left.
 */
class FunnelParticipant extends Model
{
    public const OUTCOME_MEMBER   = 'member';
    public const OUTCOME_CUSTOMER = 'customer';
    public const OUTCOME_CALL     = 'call';
    public const OUTCOME_REPORT   = 'report';

    protected $fillable = [
        'uuid', 'funnel_id', 'host_user_id', 'name', 'email', 'phone', 'token',
        'current_presentation_id', 'thread_attendee_id',
        'started_at', 'last_seen_at', 'finished_at', 'outcome',
    ];

    protected $casts = [
        'started_at'   => 'datetime',
        'last_seen_at' => 'datetime',
        'finished_at'  => 'datetime',
    ];

    protected $hidden = ['token'];

    protected static function booted(): void
    {
        static::creating(function (self $participant) {
            $participant->uuid  ??= (string) Str::uuid();
            $participant->token ??= Str::random(48);
        });
    }

    // ── Relationships ─────────────────────────────────────────────────────────

    /** @return BelongsTo<PresentationFunnel, $this> */
    public function funnel(): BelongsTo
    {
        return $this->belongsTo(PresentationFunnel::class, 'funnel_id');
    }

    /**
     * The member who invited them, for the whole journey.
     *
     * @return BelongsTo<User, $this>
     */
    public function host(): BelongsTo
    {
        return $this->belongsTo(User::class, 'host_user_id');
    }

    /** @return BelongsTo<Presentation, $this> */
    public function currentPresentation(): BelongsTo
    {
        return $this->belongsTo(Presentation::class, 'current_presentation_id');
    }

    /**
     * The attendee row this person's one conversation hangs off.
     *
     * @return BelongsTo<PresentationAttendee, $this>
     */
    public function threadAttendee(): BelongsTo
    {
        return $this->belongsTo(PresentationAttendee::class, 'thread_attendee_id');
    }

    /**
     * Their attendee row on every video they have reached.
     *
     * @return HasMany<PresentationAttendee, $this>
     */
    public function attendees(): HasMany
    {
        return $this->hasMany(PresentationAttendee::class, 'funnel_participant_id');
    }

    /** @return HasMany<FunnelChoiceEvent, $this> */
    public function choices(): HasMany
    {
        return $this->hasMany(FunnelChoiceEvent::class, 'participant_id')->orderBy('id');
    }

    // ── Isolation ─────────────────────────────────────────────────────────────

    /**
     * The same rule as an attendee, and deliberately the same shape.
     *
     * Admins see everyone; a member sees only the people they personally
     * invited. Written out rather than delegated so that a reader auditing
     * isolation finds it in the obvious place.
     *
     * @param Builder<FunnelParticipant> $query
     * @return Builder<FunnelParticipant>
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

    // ── Progress through the flow ─────────────────────────────────────────────

    public function isWatching(): bool
    {
        return $this->last_seen_at !== null
            && $this->last_seen_at->gt(now()->subSeconds(PresentationAttendee::PRESENCE_WINDOW_SECONDS));
    }

    public function hasFinished(): bool
    {
        return $this->finished_at !== null;
    }

    /** Their attendee row on the video they are on now. */
    public function currentAttendee(): ?PresentationAttendee
    {
        if (! $this->current_presentation_id) {
            return null;
        }

        return $this->attendees()
            ->where('presentation_id', $this->current_presentation_id)
            ->first();
    }

    /**
     * Which step of the flow they are on, counting from one.
     *
     * The flow is a graph, not a line, so this is the position of the video in
     * the admin's arrangement rather than the number of videos they have seen.
     * `pathLength()` answers the other question.
     */
    public function stepNumber(): ?int
    {
        $order = $this->currentPresentation?->funnel_order;

        return $order === null ? null : $order + 1;
    }

    public function pathLength(): int
    {
        return $this->attendees()->count();
    }

    public function outcomeLabel(): ?string
    {
        return match ($this->outcome) {
            self::OUTCOME_MEMBER   => 'Signed up',
            self::OUTCOME_CUSTOMER => 'Became a customer',
            self::OUTCOME_CALL     => 'Booked a call',
            self::OUTCOME_REPORT   => 'Asked for a report',
            default                => null,
        };
    }

    /**
     * The outcome a call-to-action kind represents, if it represents one.
     *
     * Clicking through is not the same as converting, and this deliberately
     * records only what the person asked for. Whether they went on to do it is
     * the conversion tracker's question, answered separately.
     */
    public static function outcomeForCtaKind(?string $kind): ?string
    {
        return match ($kind) {
            \App\Support\PresentationCta::JOIN     => self::OUTCOME_MEMBER,
            \App\Support\PresentationCta::SCHEDULE => self::OUTCOME_CALL,
            default                                => null,
        };
    }
}
