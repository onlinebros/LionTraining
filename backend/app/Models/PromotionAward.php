<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One launch-special credit: a place bonus, or a share of one order's pool
 * contribution. See App\Services\Vendor\PromotionBonuses.
 */
class PromotionAward extends Model
{
    public const KIND_PLACE = 'place';
    public const KIND_POOL  = 'pool';

    public const STATUS_ACTIVE         = 'active';
    public const STATUS_VOIDED         = 'voided';
    public const STATUS_NEEDS_CLAWBACK = 'needs_clawback';

    protected $fillable = [
        'promotion_key', 'kind', 'vendor_lead_id', 'unit_index', 'earner_id',
        'place', 'units', 'shares', 'amount', 'commission_ledger_id', 'status', 'voided_at',
    ];

    protected function casts(): array
    {
        return [
            'unit_index' => 'integer',
            'place'      => 'integer',
            'units'      => 'integer',
            'shares'     => 'integer',
            'amount'     => 'decimal:4',
            'voided_at'  => 'datetime',
        ];
    }

    /** @return BelongsTo<VendorLead, $this> */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(VendorLead::class, 'vendor_lead_id');
    }

    /** @return BelongsTo<User, $this> */
    public function earner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'earner_id');
    }

    /** @return BelongsTo<CommissionLedger, $this> */
    public function ledger(): BelongsTo
    {
        return $this->belongsTo(CommissionLedger::class, 'commission_ledger_id');
    }

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /**
     * What this award is for, independent of amount and place number.
     *
     * A place bonus belongs to a system (order + unit), so it survives the
     * places renumbering. A pool award belongs to an order and an earner.
     */
    public function identity(): string
    {
        return self::identityFor((string) $this->kind, (int) $this->vendor_lead_id, (int) $this->unit_index, (int) $this->earner_id);
    }

    public static function identityFor(string $kind, int $leadId, int $unit, int $earnerId): string
    {
        return $kind === self::KIND_PLACE
            ? "place:{$leadId}:{$unit}"
            : "pool:{$leadId}:{$earnerId}";
    }
}
