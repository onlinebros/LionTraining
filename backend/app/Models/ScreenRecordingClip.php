<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One entry in a combined recording's running order.
 *
 * @property-read ScreenRecording|null $source
 */
class ScreenRecordingClip extends Model
{
    protected $table = 'screen_recording_clips';

    protected $fillable = [
        'composition_id', 'source_recording_id', 'source_title', 'sort_order',
    ];

    /** @return BelongsTo<ScreenRecording, $this> */
    public function composition(): BelongsTo
    {
        return $this->belongsTo(ScreenRecording::class, 'composition_id');
    }

    /** @return BelongsTo<ScreenRecording, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(ScreenRecording::class, 'source_recording_id');
    }

    /** The name to show, even if the source has since been deleted. */
    public function label(): string
    {
        return $this->source?->title
            ?? $this->source_title
            ?? 'Deleted recording';
    }

    /** A clip whose source is gone cannot be rebuilt from. */
    public function isPlayable(): bool
    {
        return $this->source !== null && $this->source->isReady();
    }
}
