<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One uploaded list, on its way from a CSV to live genealogy.
 *
 * @property string $status
 */
class PartnerImport extends Model
{
    // uploaded → validated → committed. 'failed' is validated-and-unusable and
    // is the only state a batch can return from: fix the file, re-validate.
    public const STATUS_UPLOADED  = 'uploaded';
    public const STATUS_VALIDATED = 'validated';
    public const STATUS_FAILED    = 'failed';
    public const STATUS_COMMITTED = 'committed';

    protected $fillable = [
        'partner_company_id',
        'original_filename',
        'stored_path',
        'status',
        'total_rows',
        'valid_rows',
        'error_rows',
        'committed_rows',
        'errors',
        'ignored_columns',
        'uploaded_by',
        'validated_at',
        'committed_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'errors'          => 'array',
            'ignored_columns' => 'array',
            'validated_at'    => 'datetime',
            'committed_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(PartnerCompany::class, 'partner_company_id');
    }

    public function rows(): HasMany
    {
        return $this->hasMany(PartnerImportRow::class)->orderBy('line_number');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** Rows with no parent inside the file — the legs an admin has to connect. */
    public function topRows(): HasMany
    {
        return $this->hasMany(PartnerImportRow::class)
            ->whereNull('external_parent_id')
            ->orderBy('line_number');
    }

    public function isCommitted(): bool
    {
        return $this->status === self::STATUS_COMMITTED;
    }

    /**
     * May this batch be turned into users rows?
     *
     * Validation passing is necessary but not sufficient: every top row must
     * also have been resolved to a parent in our system. A top row committed
     * without one becomes the root of its own tree, invisible to the upline
     * that was supposed to receive the leg, and there is no undo.
     */
    public function isCommittable(): bool
    {
        return $this->status === self::STATUS_VALIDATED
            && $this->unlinkedTopRows() === 0;
    }

    public function unlinkedTopRows(): int
    {
        return $this->topRows()->whereNull('parent_user_id')->count();
    }
}
