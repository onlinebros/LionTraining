<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A one-way note from the stage, shown to everyone watching. */
class PresentationAnnouncement extends Model
{
    protected $fillable = ['presentation_id', 'user_id', 'body'];

    /** @return BelongsTo<Presentation, $this> */
    public function presentation(): BelongsTo
    {
        return $this->belongsTo(Presentation::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
