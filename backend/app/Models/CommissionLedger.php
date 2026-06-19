<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommissionLedger extends Model
{
    protected $table = 'commission_ledger';

    protected $fillable = [
        'earner_id',
        'commission_plan_id',
        'payout_id',
        'source_type',
        'source_id',
        'type',
        'amount',
        'status',
        'clawback_eligible_until',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount'                 => 'decimal:4',
            'clawback_eligible_until' => 'date',
        ];
    }

    public function earner()
    {
        return $this->belongsTo(User::class, 'earner_id');
    }

    public function commissionPlan()
    {
        return $this->belongsTo(CommissionPlan::class);
    }

    public function payout()
    {
        return $this->belongsTo(CommissionPayout::class);
    }

    public function source()
    {
        return $this->morphTo();
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function clawback()
    {
        return $this->hasOne(CommissionClawback::class, 'original_ledger_id');
    }

    public function isCredit(): bool { return $this->type === 'credit'; }
    public function isDebit(): bool  { return $this->type === 'debit'; }
    public function isPaid(): bool   { return $this->status === 'paid'; }
    public function isVoided(): bool { return $this->status === 'voided'; }

    public function isClawbackEligible(): bool
    {
        if ($this->type !== 'credit' || $this->status === 'voided') {
            return false;
        }
        if ($this->clawback_eligible_until === null) {
            return false; // no automatic window; admin override only
        }
        return now()->lte($this->clawback_eligible_until);
    }

    public function scopeCredits($query)  { return $query->where('type', 'credit'); }
    public function scopeDebits($query)   { return $query->where('type', 'debit'); }
    public function scopePending($query)  { return $query->where('status', 'pending'); }
    public function scopeApproved($query) { return $query->where('status', 'approved'); }
    public function scopeUnpaid($query)
    {
        return $query->whereIn('status', ['pending', 'approved']);
    }
}
