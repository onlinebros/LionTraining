<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommissionClawback extends Model
{
    protected $fillable = [
        'original_ledger_id',
        'debit_ledger_id',
        'earner_id',
        'amount',
        'reason',
        'status',
        'initiated_by',
        'applied_at',
        'override_window',
        'override_notes',
    ];

    protected function casts(): array
    {
        return [
            'amount'          => 'decimal:4',
            'applied_at'      => 'datetime',
            'override_window' => 'boolean',
        ];
    }

    public function originalLedger()
    {
        return $this->belongsTo(CommissionLedger::class, 'original_ledger_id');
    }

    public function debitLedger()
    {
        return $this->belongsTo(CommissionLedger::class, 'debit_ledger_id');
    }

    public function earner()
    {
        return $this->belongsTo(User::class, 'earner_id');
    }

    public function initiatedBy()
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }

    public function isPending(): bool  { return $this->status === 'pending'; }
    public function isApplied(): bool  { return $this->status === 'applied'; }
    public function isReversed(): bool { return $this->status === 'reversed'; }

    public function scopePending($query)  { return $query->where('status', 'pending'); }
    public function scopeApplied($query)  { return $query->where('status', 'applied'); }
}
