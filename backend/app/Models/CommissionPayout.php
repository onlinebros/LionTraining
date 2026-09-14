<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property \Illuminate\Support\Carbon|null $period_start
 * @property \Illuminate\Support\Carbon|null $period_end
 * @property \Illuminate\Support\Carbon|null $transferred_at
 * @property-read User|null $earner
 */
class CommissionPayout extends Model
{
    protected $fillable = [
        'earner_id',
        'period_start',
        'period_end',
        'total_amount',
        'status',
        'payment_method',
        'payment_reference',
        'notes',
        'processed_by',
        'paid_at',

        // Written only by StripePayoutService and the Connect webhooks.
        'stripe_transfer_id',
        'stripe_destination_account',
        'transfer_status',
        'transfer_failure_reason',
        'transferred_at',
    ];

    protected function casts(): array
    {
        return [
            'period_start'   => 'date',
            'period_end'     => 'date',
            'total_amount'   => 'decimal:4',
            'paid_at'        => 'datetime',
            'transferred_at' => 'datetime',
        ];
    }

    public function earner()
    {
        return $this->belongsTo(User::class, 'earner_id');
    }

    public function processedBy()
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    public function ledgerEntries()
    {
        return $this->hasMany(CommissionLedger::class, 'payout_id');
    }

    public function isPaid(): bool      { return $this->status === 'paid'; }
    public function isPending(): bool   { return $this->status === 'pending'; }
    public function isApproved(): bool  { return $this->status === 'approved'; }
    public function isCancelled(): bool { return $this->status === 'cancelled'; }

    public function scopePending($query)   { return $query->where('status', 'pending'); }
    public function scopeApproved($query)  { return $query->where('status', 'approved'); }
}
