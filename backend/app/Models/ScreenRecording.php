<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * A screen capture made by an admin in the recording studio.
 *
 * The file itself lives on object storage (DigitalOcean Spaces in production);
 * this row is the catalogue entry the admin control panel manages and that
 * presentations and funnels play.
 *
 * @property-read Role|null $requiredRole
 */
class ScreenRecording extends Model
{
    use SoftDeletes;

    public const STATUS_UPLOADING = 'uploading';
    /** A combined recording whose file is still being built from its clips. */
    public const STATUS_RENDERING = 'rendering';
    public const STATUS_READY     = 'ready';
    public const STATUS_FAILED    = 'failed';

    public const SOURCE_COMPOSITION = 'composition';
    /** Recorded somewhere else and uploaded, rather than made in the studio. */
    public const SOURCE_UPLOAD = 'upload';

    /** Who may watch, once published. */
    public const VISIBILITY_ADMINS  = 'admins';
    public const VISIBILITY_MEMBERS = 'members';
    public const VISIBILITY_ROLE    = 'role';
    public const VISIBILITY_LINK    = 'link';

    public const VISIBILITIES = [
        self::VISIBILITY_ADMINS  => 'Admins only',
        self::VISIBILITY_MEMBERS => 'All signed-in members',
        self::VISIBILITY_ROLE    => 'Members at a role level',
        self::VISIBILITY_LINK    => 'Anyone with the link',
    ];

    public const CORNERS = [
        'top-left'     => 'Top left',
        'top-right'    => 'Top right',
        'bottom-left'  => 'Bottom left',
        'bottom-right' => 'Bottom right',
    ];

    public const TRIM_QUEUED     = 'queued';
    public const TRIM_PROCESSING = 'processing';
    public const TRIM_FAILED     = 'failed';

    protected $fillable = [
        'uuid', 'user_id', 'category_id', 'title', 'description',
        'disk', 'path', 'original_path', 'original_duration_seconds', 'thumbnail_path',
        'trim_start', 'trim_end', 'trim_status', 'trim_error',
        'mime', 'size_bytes', 'duration_seconds', 'width', 'height',
        'source', 'webcam_position', 'has_mic_audio', 'has_system_audio',
        'status', 'bytes_received', 'upload_error',
        'visibility', 'required_role_id', 'is_published', 'published_at', 'member_schedulable',
    ];

    protected $casts = [
        'has_mic_audio'    => 'boolean',
        'has_system_audio' => 'boolean',
        'is_published'     => 'boolean',
        'member_schedulable' => 'boolean',
        'published_at'     => 'datetime',
        'last_viewed_at'   => 'datetime',
        'size_bytes'       => 'integer',
        'bytes_received'   => 'integer',
        'duration_seconds' => 'integer',
        'trim_start'       => 'float',
        'trim_end'         => 'float',
    ];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected static function booted(): void
    {
        static::creating(function (self $recording) {
            $recording->uuid ??= (string) Str::uuid();
            $recording->disk ??= config('screen-recordings.disk');
        });
    }

    // ── Relationships ─────────────────────────────────────────────────────────

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<TrainingCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(TrainingCategory::class, 'category_id');
    }

    /** @return BelongsTo<Role, $this> */
    public function requiredRole(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'required_role_id');
    }

    /**
     * Named cue points, shared by every showing of this recording.
     *
     * @return HasMany<RecordingChapter, $this>
     */
    public function chapters(): HasMany
    {
        return $this->hasMany(RecordingChapter::class, 'recording_id')->orderBy('starts_at_seconds');
    }

    /**
     * Showings that play this recording — its "used in" list.
     *
     * @return HasMany<Presentation, $this>
     */
    public function presentations(): HasMany
    {
        return $this->hasMany(Presentation::class, 'recording_id');
    }

    /**
     * The running order, when this recording was built from others.
     *
     * @return HasMany<ScreenRecordingClip, $this>
     */
    public function clips(): HasMany
    {
        return $this->hasMany(ScreenRecordingClip::class, 'composition_id')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    /**
     * Compositions this recording is used in — checked before deleting it.
     *
     * @return HasMany<ScreenRecordingClip, $this>
     */
    public function usedInCompositions(): HasMany
    {
        return $this->hasMany(ScreenRecordingClip::class, 'source_recording_id');
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    /**
     * @param Builder<ScreenRecording> $query
     * @return Builder<ScreenRecording>
     */
    public function scopeReady(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_READY);
    }

    /**
     * Recordings an admin has released for members to schedule themselves.
     *
     * @param Builder<ScreenRecording> $query
     * @return Builder<ScreenRecording>
     */
    public function scopeMemberSchedulable(Builder $query): Builder
    {
        return $query->ready()
            ->where('member_schedulable', true)
            ->whereNotNull('duration_seconds');
    }

    /**
     * @param Builder<ScreenRecording> $query
     * @return Builder<ScreenRecording>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_READY)->where('is_published', true);
    }

    // ── State ─────────────────────────────────────────────────────────────────

    public function isReady(): bool
    {
        return $this->status === self::STATUS_READY && filled($this->path);
    }

    public function isComposition(): bool
    {
        return $this->source === self::SOURCE_COMPOSITION;
    }

    /** Being built from its clips — there is no file to play yet. */
    public function isRendering(): bool
    {
        return $this->status === self::STATUS_RENDERING;
    }

    /** A trim is queued or running. The current file stays playable throughout. */
    public function isTrimming(): bool
    {
        return in_array($this->trim_status, [self::TRIM_QUEUED, self::TRIM_PROCESSING], true);
    }

    public function trimFailed(): bool
    {
        return $this->trim_status === self::TRIM_FAILED;
    }

    /** Has been trimmed at least once, so the untouched capture is still held. */
    public function hasOriginal(): bool
    {
        return filled($this->original_path) && $this->original_path !== $this->path;
    }

    /**
     * The key every trim cuts from.
     *
     * Always the pristine capture once one exists — re-encoding an already
     * re-encoded file would lose quality for nothing, and widening a trim after
     * narrowing it has to be possible.
     */
    public function trimSourcePath(): ?string
    {
        return $this->original_path ?: $this->path;
    }

    // ── Access ────────────────────────────────────────────────────────────────

    /**
     * May this viewer watch?
     *
     * $user is null for a signed-out visitor following a share link. Admins and
     * the recording's own author always can, published or not, so a draft can
     * be reviewed before it goes out.
     */
    public function viewableBy(?User $user): bool
    {
        if ($user && ($user->isAdmin() || $user->id === $this->user_id)) {
            return true;
        }

        if (! $this->isReady()) {
            return false;
        }

        if (! $this->is_published) {
            return false;
        }

        return match ($this->visibility) {
            self::VISIBILITY_LINK    => true,
            self::VISIBILITY_MEMBERS => $user !== null,
            self::VISIBILITY_ROLE    => $user !== null && $this->meetsRoleLevel($user),
            default                  => false, // admins-only, and we know they are not one
        };
    }

    private function meetsRoleLevel(User $user): bool
    {
        if (! $this->required_role_id) {
            return true;
        }

        $required = $this->requiredRole ?? Role::find($this->required_role_id);

        return $required !== null
            && $user->role !== null
            && $user->role->level >= $required->level;
    }

    // ── Presentation ──────────────────────────────────────────────────────────

    public function formattedDuration(): string
    {
        $seconds = (int) $this->duration_seconds;

        if ($seconds <= 0) {
            return '—';
        }

        $hours = intdiv($seconds, 3600);
        $mins  = intdiv($seconds % 3600, 60);
        $secs  = $seconds % 60;

        return $hours > 0
            ? sprintf('%d:%02d:%02d', $hours, $mins, $secs)
            : sprintf('%d:%02d', $mins, $secs);
    }

    public function formattedSize(): string
    {
        $bytes = (int) $this->size_bytes;

        if ($bytes < 1024) {
            return $bytes.' B';
        }
        if ($bytes < 1048576) {
            return round($bytes / 1024, 1).' KB';
        }
        if ($bytes < 1073741824) {
            return round($bytes / 1048576, 1).' MB';
        }

        return round($bytes / 1073741824, 2).' GB';
    }

    public function sourceLabel(): string
    {
        return match ($this->source) {
            'camera'                => 'Webcam only',
            'screen+camera'         => 'Screen + webcam ('.(self::CORNERS[$this->webcam_position] ?? 'corner').')',
            self::SOURCE_COMPOSITION => 'Combined from '.$this->clips()->count().' recordings',
            self::SOURCE_UPLOAD      => 'Uploaded video',
            default                 => 'Screen only',
        };
    }

    public function visibilityLabel(): string
    {
        if ($this->visibility === self::VISIBILITY_ROLE) {
            return ($this->requiredRole?->display_name ?? 'Role').' and above';
        }

        return self::VISIBILITIES[$this->visibility] ?? $this->visibility;
    }

    /** The in-app playback URL. Never the object URL — that is minted per request. */
    public function watchUrl(): string
    {
        return route('recordings.watch', $this->uuid);
    }

    public function streamUrl(): string
    {
        return route('recordings.stream', $this->uuid);
    }

    public function recordViewed(): void
    {
        // Deliberately not a model event: incrementing without touching
        // updated_at keeps "last edited" honest in the control panel.
        $this->newQuery()->whereKey($this->getKey())->update([
            'view_count'     => $this->view_count + 1,
            'last_viewed_at' => now(),
        ]);
    }
}
