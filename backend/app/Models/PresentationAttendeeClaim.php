<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A second member's unsuccessful attempt to claim a guest who was already
 * registered by someone else. Visible to admins only — see the migration.
 */
class PresentationAttendeeClaim extends Model
{
    protected $fillable = ['presentation_id', 'attendee_id', 'claimed_by_user_id'];

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
    public function claimedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'claimed_by_user_id');
    }
}
