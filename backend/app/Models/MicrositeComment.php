<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A contributor's feedback on a microsite section, and our reply.
 *
 * @property int $id
 * @property int $microsite_section_id
 * @property ?int $microsite_member_id
 * @property string $body
 * @property ?string $reply
 * @property ?Carbon $replied_at
 * @property Carbon $created_at
 */
class MicrositeComment extends Model
{
    protected $fillable = ['microsite_section_id', 'microsite_member_id', 'body'];

    protected function casts(): array
    {
        return ['replied_at' => 'datetime'];
    }

    /** @return BelongsTo<MicrositeSection, $this> */
    public function section(): BelongsTo
    {
        return $this->belongsTo(MicrositeSection::class, 'microsite_section_id');
    }

    /** @return BelongsTo<MicrositeMember, $this> */
    public function member(): BelongsTo
    {
        return $this->belongsTo(MicrositeMember::class, 'microsite_member_id');
    }

    /** @return BelongsTo<User, $this> */
    public function replier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'replied_by');
    }
}
