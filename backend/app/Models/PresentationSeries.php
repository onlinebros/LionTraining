<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A repeating schedule: one recording, shown on given days at given times.
 *
 * The series is a rule, not an event. Occurrences are real presentation rows
 * generated ahead of time by `presentations:generate`.
 *
 * @property string $cta_type
 * @property string|null $cta_headline
 * @property string|null $cta_label
 * @property string|null $cta_note
 * @property string|null $cta_url
 */
class PresentationSeries extends Model
{
    use SoftDeletes;

    protected $table = 'presentation_series';

    protected $fillable = [
        'recording_id', 'title', 'description', 'days', 'times',
        'weeks_ahead', 'starts_on', 'ends_on',
        'replay_visibility', 'collect_phone', 'is_active', 'created_by',
        'cta_type', 'cta_headline', 'cta_label', 'cta_note', 'cta_url',
    ];

    /*
     * Mirrors the column defaults. Without these a freshly created model has
     * null where the database has a default, and the generator — which asks
     * `is_active` before doing anything — quietly does nothing.
     */
    protected $attributes = [
        'weeks_ahead'       => 4,
        'is_active'         => true,
        'collect_phone'     => false,
        'replay_visibility' => 'none',
    ];

    protected $casts = [
        'days'          => 'array',
        'times'         => 'array',
        'starts_on'     => 'date',
        'ends_on'       => 'date',
        'collect_phone' => 'boolean',
        'is_active'     => 'boolean',
        'weeks_ahead'   => 'integer',
    ];

    public const DAY_NAMES = [
        1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday',
        5 => 'Friday', 6 => 'Saturday', 0 => 'Sunday',
    ];

    /** @return BelongsTo<ScreenRecording, $this> */
    public function recording(): BelongsTo
    {
        return $this->belongsTo(ScreenRecording::class, 'recording_id');
    }

    /** @return HasMany<Presentation, $this> */
    public function presentations(): HasMany
    {
        return $this->hasMany(Presentation::class, 'series_id');
    }

    /** @return HasMany<Presentation, $this> */
    public function upcoming(): HasMany
    {
        return $this->presentations()
            ->where('scheduled_at', '>=', now())
            ->orderBy('scheduled_at');
    }

    /** "Tuesdays and Thursdays at 7:00pm ET" */
    public function summary(): string
    {
        $days = collect($this->days ?? [])
            ->sort()
            ->map(fn ($d) => self::DAY_NAMES[(int) $d] ?? '?')
            ->map(fn ($name) => $name.'s')
            ->values();

        $times = collect($this->times ?? [])
            ->sort()
            ->map(fn ($t) => \Illuminate\Support\Carbon::createFromFormat('H:i', $t)->format('g:ia'))
            ->values();

        if ($days->isEmpty() || $times->isEmpty()) {
            return 'Not scheduled yet';
        }

        return $days->join(', ', ' and ').' at '.$times->join(', ', ' and ')
            .' '.$this->timezoneAbbreviation();
    }

    /** EST or EDT, correct for today rather than hard-coded. */
    public function timezoneAbbreviation(): string
    {
        return now(Presentation::bookingTimezone())->format('T');
    }
}
