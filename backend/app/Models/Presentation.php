<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * A scheduled showing of a recording that a group watches together.
 *
 * The video is a recording from the library; what is live is the room —
 * admins and inviting members answering questions in chat while it plays.
 *
 * @property \Illuminate\Support\Carbon $scheduled_at
 * @property \Illuminate\Support\Carbon|null $started_at
 * @property \Illuminate\Support\Carbon|null $ended_at
 * @property string $cta_type
 * @property string|null $cta_headline
 * @property string|null $cta_label
 * @property string|null $cta_note
 * @property string|null $cta_url
 */
class Presentation extends Model
{
    use SoftDeletes;

    /** A shared moment at a set time, or a link that opens whenever it is used. */
    public const FORMAT_SCHEDULED = 'scheduled';
    public const FORMAT_ON_DEMAND = 'on_demand';

    public const FORMATS = [
        self::FORMAT_SCHEDULED => 'Scheduled — everyone watches together at a set time',
        self::FORMAT_ON_DEMAND => 'Always open — starts from the beginning whenever someone opens it',
    ];

    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_LIVE      = 'live';
    public const STATUS_ENDED     = 'ended';
    public const STATUS_CANCELLED = 'cancelled';

    public const REPLAY_NONE      = 'none';
    public const REPLAY_ATTENDEES = 'attendees';
    public const REPLAY_MEMBERS   = 'members';
    public const REPLAY_LINK      = 'link';

    public const REPLAY_OPTIONS = [
        self::REPLAY_NONE      => 'No replay',
        self::REPLAY_ATTENDEES => 'Only people who registered',
        self::REPLAY_MEMBERS   => 'Any signed-in member',
        self::REPLAY_LINK      => 'Anyone with the link',
    ];

    protected $fillable = [
        'uuid', 'slug', 'title', 'description', 'format', 'recording_id', 'series_id',
        'funnel_id', 'funnel_order',
        'scheduled_at', 'started_at', 'ended_at', 'duration_seconds',
        'status', 'replay_visibility', 'collect_phone', 'is_open', 'created_by', 'owner_user_id',
        'cta_type', 'cta_headline', 'cta_label', 'cta_note', 'cta_url', 'cta_opens_in',
    ];

    protected $casts = [
        'scheduled_at'     => 'datetime',
        'started_at'       => 'datetime',
        'ended_at'         => 'datetime',
        'duration_seconds' => 'integer',
        'collect_phone'    => 'boolean',
        'is_open'          => 'boolean',
    ];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    protected static function booted(): void
    {
        static::creating(function (self $presentation) {
            $presentation->uuid ??= (string) Str::uuid();
            $presentation->slug ??= self::uniqueSlug($presentation->title ?? 'presentation');
        });
    }

    public static function uniqueSlug(string $title, ?int $excludeId = null): string
    {
        $base = Str::slug($title) ?: 'presentation';
        $slug = $base;
        $i    = 1;

        while (static::withTrashed()
            ->where('slug', $slug)
            ->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId))
            ->exists()
        ) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }

    // ── Relationships ─────────────────────────────────────────────────────────

    /** @return BelongsTo<ScreenRecording, $this> */
    public function recording(): BelongsTo
    {
        return $this->belongsTo(ScreenRecording::class, 'recording_id');
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The member this showing belongs to, if it is not the company's.
     *
     * Null means company-wide: every member gets their own link to it. Set
     * means one member's own room, visible to nobody else.
     *
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /**
     * The repeating schedule that produced this showing, if any.
     *
     * @return BelongsTo<PresentationSeries, $this>
     */
    public function series(): BelongsTo
    {
        return $this->belongsTo(PresentationSeries::class, 'series_id');
    }

    /** @return HasMany<PresentationAttendee, $this> */
    public function attendees(): HasMany
    {
        return $this->hasMany(PresentationAttendee::class);
    }

    /** @return HasMany<PresentationAttendeeClaim, $this> */
    public function claims(): HasMany
    {
        return $this->hasMany(PresentationAttendeeClaim::class);
    }

    /** @return HasMany<PresentationMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(PresentationMessage::class);
    }

    /** @return HasMany<PresentationAnnouncement, $this> */
    public function announcements(): HasMany
    {
        return $this->hasMany(PresentationAnnouncement::class)->orderBy('id');
    }

    /**
     * Chapters belong to the recording, so they survive re-scheduling it.
     *
     * @return \Illuminate\Support\Collection<int, RecordingChapter>
     */
    public function chapters(): \Illuminate\Support\Collection
    {
        return $this->recording
            ? $this->recording->chapters()->orderBy('starts_at_seconds')->get()
            : collect();
    }

    /** The chapter the room is inside right now — "The Compensation Plan". */
    public function currentChapter(): ?RecordingChapter
    {
        $offset = $this->currentOffset();

        if ($offset === null) {
            return null;
        }

        return $this->chapters()
            ->filter(fn (RecordingChapter $c) => $c->starts_at_seconds <= $offset)
            ->sortByDesc('starts_at_seconds')
            ->first();
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    /**
     * The flow this video is a step of, if it is a step of one.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<PresentationFunnel, $this>
     */
    public function funnel(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(PresentationFunnel::class, 'funnel_id');
    }

    /**
     * The choices placed on this video, in the order they appear.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<PresentationCue, $this>
     */
    public function cues(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PresentationCue::class, 'presentation_id')
            ->orderBy('starts_at_seconds')->orderBy('sort_order')->orderBy('id');
    }

    public function isFunnelStep(): bool
    {
        return $this->funnel_id !== null;
    }

    /**
     * Cues worth showing: active, coherent, and pointing somewhere real.
     *
     * Filtered here rather than in the view so the guest page and the admin
     * preview agree about what a viewer would actually be offered.
     */
    public function playableCues(): \Illuminate\Support\Collection
    {
        return $this->cues->filter->isPlayable()->values();
    }

    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_SCHEDULED, self::STATUS_LIVE])
            ->orderBy('scheduled_at');
    }

    public function scopePast(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_ENDED, self::STATUS_CANCELLED])
            ->orderByDesc('scheduled_at');
    }

    /**
     * Showings this user may see in the member area.
     *
     * The company's, plus their own. Never another member's — a personal room
     * is exactly as private as the guest list inside it.
     */
    public function scopeForMember(Builder $query, ?User $user): Builder
    {
        if ($user === null) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->isAdmin()) {
            return $query;
        }

        return $query->where(fn ($q) => $q
            ->whereNull('owner_user_id')
            ->orWhere('owner_user_id', $user->id));
    }

    public function isPersonal(): bool
    {
        return $this->owner_user_id !== null;
    }

    /** Only the member who created it, or an admin, may change or cancel it. */
    public function manageableBy(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        return $user->isAdmin() || $this->owner_user_id === $user->id;
    }

    // ── Lifecycle ─────────────────────────────────────────────────────────────

    public function isOnDemand(): bool { return $this->format === self::FORMAT_ON_DEMAND; }

    public function isScheduled(): bool { return $this->status === self::STATUS_SCHEDULED; }
    public function isLive(): bool      { return $this->status === self::STATUS_LIVE; }
    public function hasEnded(): bool    { return in_array($this->status, [self::STATUS_ENDED, self::STATUS_CANCELLED], true); }
    public function isCancelled(): bool { return $this->status === self::STATUS_CANCELLED; }

    /**
     * Begin the showing.
     *
     * started_at is deliberately the SCHEDULED time, not "now". The command
     * that starts presentations runs on a per-minute timer, so it may fire a
     * few seconds late — and since every viewer's position is derived from
     * started_at, using "now" would shift the whole audience and make the
     * showing end late.
     */
    public function start(): void
    {
        if (! $this->isScheduled()) {
            return;
        }

        $this->forceFill([
            'status'     => self::STATUS_LIVE,
            'started_at' => $this->scheduled_at,
        ])->save();
    }

    public function end(): void
    {
        if ($this->hasEnded()) {
            return;
        }

        $this->forceFill([
            'status'   => self::STATUS_ENDED,
            'ended_at' => now(),
        ])->save();
    }

    /** When the video runs out, whether or not anyone pressed anything. */
    public function endsAt(): ?\Illuminate\Support\Carbon
    {
        return $this->started_at?->copy()->addSeconds($this->duration_seconds);
    }

    /**
     * May this viewer see the replay after the showing has finished?
     *
     * `$user` is null for a registered guest with no account — which is most of
     * them. "attendees" therefore means anyone holding a valid attendee cookie,
     * which the caller has already checked.
     */
    public function replayIsOpenTo(?User $user): bool
    {
        return match ($this->replay_visibility) {
            self::REPLAY_LINK      => true,
            self::REPLAY_ATTENDEES => true,
            self::REPLAY_MEMBERS   => $user !== null,
            default                => false,
        };
    }

    // ── Position ──────────────────────────────────────────────────────────────

    /**
     * How many seconds into the video the room is right now.
     *
     * Null before it starts. Clamped to the duration so a showing nobody ended
     * still reports a sane position.
     */
    public function currentOffset(): ?int
    {
        // An on-demand share has no room clock — every viewer is at their own
        // point, which is exactly what makes it useful to a member watching the
        // list.
        if ($this->isOnDemand() || ! $this->started_at) {
            return null;
        }

        // started_at -> now, so positive once the showing has begun.
        $elapsed = (int) $this->started_at->diffInSeconds(now(), absolute: false);

        return min(max(0, $elapsed), $this->duration_seconds);
    }

    /** True once the video would have run out, regardless of stored status. */
    public function isOverrun(): bool
    {
        return ! $this->isOnDemand()
            && $this->started_at !== null
            && now()->greaterThan($this->endsAt());
    }

    public function secondsUntilStart(): ?int
    {
        if ($this->isOnDemand() || ! $this->isScheduled()) {
            return null;
        }

        // now -> scheduled_at, so positive while it is still in the future.
        return (int) max(0, now()->diffInSeconds($this->scheduled_at, absolute: false));
    }

    // ── Time ──────────────────────────────────────────────────────────────────

    /**
     * The timezone the company books in.
     *
     * Timestamps are stored in UTC; this is only how they are read in and shown.
     */
    public static function bookingTimezone(): string
    {
        return config('presentations.timezone', 'America/New_York');
    }

    /** `scheduled_at` as a clock in the booking timezone. */
    public function scheduledAtLocal(): \Illuminate\Support\Carbon
    {
        return $this->scheduled_at->copy()->setTimezone(self::bookingTimezone());
    }

    /**
     * "EST" or "EDT", correct for this particular date.
     *
     * Derived rather than hard-coded: the east coast switches in March and
     * November, and a showing labelled EST in July is simply wrong.
     */
    public function timezoneAbbreviation(): string
    {
        return $this->scheduledAtLocal()->format('T');
    }

    /** "Tue 2 Sep 2026, 7:00 PM EDT" — the canonical way to say when. */
    public function scheduledLabel(bool $withYear = false): string
    {
        $format = $withYear ? 'D j M Y, g:ia' : 'D j M, g:ia';

        return $this->scheduledAtLocal()->format($format).' '.$this->timezoneAbbreviation();
    }

    /** UTC ISO-8601, for a browser to render in the viewer's own zone. */
    public function scheduledAtIso(): string
    {
        return $this->scheduled_at->copy()->utc()->toIso8601String();
    }

    /**
     * Turn a datetime-local field into a UTC timestamp.
     *
     * The admin form has no timezone control on purpose — what is typed is
     * Eastern, always, because that is the one thing everybody in the company
     * can agree the clock means.
     */
    public static function parseBookingInput(string $value): \Illuminate\Support\Carbon
    {
        return \Illuminate\Support\Carbon::parse($value, self::bookingTimezone())->utc();
    }

    /** The value a datetime-local input needs to show this showing's time. */
    public function bookingInputValue(): string
    {
        return $this->scheduledAtLocal()->format('Y-m-d\TH:i');
    }

    // ── Presentation ──────────────────────────────────────────────────────────

    public function formattedDuration(): string
    {
        $minutes = intdiv($this->duration_seconds, 60);
        $seconds = $this->duration_seconds % 60;

        return sprintf('%d:%02d', $minutes, $seconds);
    }

    /** The link a member shares. */
    public function watchUrlFor(?User $member = null): string
    {
        return $member?->referral_code
            ? route('presentations.watch', ['presentation' => $this->slug, 'code' => $member->referral_code])
            : route('presentations.watch', ['presentation' => $this->slug]);
    }

    public function statusLabel(): string
    {
        if ($this->isOnDemand()) {
            return $this->hasEnded() ? 'Closed' : 'Always open';
        }

        return match ($this->status) {
            self::STATUS_LIVE      => 'In progress',
            self::STATUS_ENDED     => 'Finished',
            self::STATUS_CANCELLED => 'Cancelled',
            default                => 'Scheduled',
        };
    }
}
