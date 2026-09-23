<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A payment the vendor says they have made against what they owe us.
 *
 * It is a claim until an admin confirms it. Only a confirmed payment counts
 * towards the balance, so the vendor can never move the number themselves —
 * but they can always put their side of it on the record, which is the
 * difference between one shared ledger and two people emailing spreadsheets.
 */
class ProductPartnerPayment extends Model
{
    public const STATUS_PENDING   = 'pending';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_REJECTED  = 'rejected';

    /*
     * `status`, `confirmed_by_user_id`, `confirmed_at` and `decision_note` are
     * not fillable on purpose: they are our side of the conversation, and the
     * only way to set them is confirm() or reject() below.
     */
    protected $fillable = [
        'vendor', 'amount', 'currency', 'paid_on', 'reference',
        'method', 'invoice_reference', 'note', 'recorded_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'paid_on'      => 'date',
            'confirmed_at' => 'datetime',
        ];
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by_user_id');
    }

    public function scopeForVendor($query, string $vendor)
    {
        return $query->where('vendor', $vendor);
    }

    public function scopeConfirmed($query)
    {
        return $query->where('status', self::STATUS_CONFIRMED);
    }

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isConfirmed(): bool
    {
        return $this->status === self::STATUS_CONFIRMED;
    }

    public function confirm(User $by, ?string $note = null): void
    {
        $this->forceFill([
            'status'               => self::STATUS_CONFIRMED,
            'confirmed_by_user_id' => $by->id,
            'confirmed_at'         => now(),
            'decision_note'        => $note,
        ])->save();
    }

    public function reject(User $by, ?string $note = null): void
    {
        $this->forceFill([
            'status'               => self::STATUS_REJECTED,
            'confirmed_by_user_id' => $by->id,
            'confirmed_at'         => now(),
            'decision_note'        => $note,
        ])->save();
    }

    public function amountDecimal(): string
    {
        return number_format(((int) $this->amount) / 100, 2);
    }
}
