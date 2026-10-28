<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A question an admin asked the knowledge library, and its answer.
 *
 * @property int $id
 * @property int $offering_id
 * @property string $question
 * @property ?string $answer
 */
class LibraryQuestion extends Model
{
    protected $fillable = ['offering_id', 'user_id', 'question', 'answer'];

    /** @return BelongsTo<Offering, $this> */
    public function offering(): BelongsTo
    {
        return $this->belongsTo(Offering::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
