<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a partner's CSV, as staged.
 *
 * @property string $external_user_id
 * @property string|null $external_parent_id
 * @property string $status
 */
class PartnerImportRow extends Model
{
    public const STATUS_PENDING   = 'pending';
    public const STATUS_VALID     = 'valid';
    public const STATUS_INVALID   = 'invalid';
    public const STATUS_COMMITTED = 'committed';
    public const STATUS_SKIPPED   = 'skipped';

    protected $fillable = [
        'partner_import_id',
        'line_number',
        'external_user_id',
        'activation_code',
        'external_parent_id',
        'external_sponsor_id',
        'effective_sponsor_id',
        'link_to_existing',
        'parent_user_id',
        'status',
        'errors',
        'warnings',
        'created_user_id',
    ];

    protected function casts(): array
    {
        return [
            'errors'   => 'array',
            'warnings' => 'array',
        ];
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(PartnerImport::class, 'partner_import_id');
    }

    /** The user in our system this row's leg hangs beneath, once resolved. */
    public function parentUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'parent_user_id');
    }

    /** The holding spot this row became at commit. */
    public function createdUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_user_id');
    }

    public function isTopRow(): bool
    {
        return blank($this->external_parent_id);
    }

    /**
     * What to call the position before anyone claims it.
     *
     * The partner's own identifier, because it is the only thing we know about
     * the position and the only thing an admin can match against the partner's
     * export. We do not receive the member's name — see SpotImportTemplate.
     */
    public function displayName(): string
    {
        return "Spot {$this->external_user_id}";
    }
}
