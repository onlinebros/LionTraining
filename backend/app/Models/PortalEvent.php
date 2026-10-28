<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Something that happened in a prospect's portal: a visit, a download, a
 * request. Shown to the admin as activity.
 *
 * @property int $id
 * @property string $type
 * @property ?array<string, mixed> $meta
 * @property Carbon $created_at
 */
class PortalEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['prospect_id', 'portal_invite_id', 'type', 'meta'];

    protected function casts(): array
    {
        return ['meta' => 'array', 'created_at' => 'datetime'];
    }

    /** @return BelongsTo<PortalInvite, $this> */
    public function invite(): BelongsTo
    {
        return $this->belongsTo(PortalInvite::class, 'portal_invite_id');
    }

    /** @param array<string, mixed> $meta */
    public static function record(Prospect $prospect, ?PortalInvite $invite, string $type, array $meta = []): void
    {
        self::create(['prospect_id' => $prospect->id, 'portal_invite_id' => $invite?->id, 'type' => $type, 'meta' => $meta ?: null]);
    }
}
