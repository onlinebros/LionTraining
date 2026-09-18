<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A named cue point in a recording.
 *
 * Attached to the recording rather than a showing, so a talk scheduled ten
 * times has its chapters typed once.
 */
class RecordingChapter extends Model
{
    protected $fillable = ['recording_id', 'label', 'starts_at_seconds'];

    protected $casts = ['starts_at_seconds' => 'integer'];

    /** @return BelongsTo<ScreenRecording, $this> */
    public function recording(): BelongsTo
    {
        return $this->belongsTo(ScreenRecording::class, 'recording_id');
    }

    public function formattedStart(): string
    {
        return PresentationAttendee::clock($this->starts_at_seconds);
    }
}
