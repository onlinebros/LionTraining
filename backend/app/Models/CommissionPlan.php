<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommissionPlan extends Model
{
    protected $fillable = [
        'name',
        'description',
        'type',
        'config',
        'clawback_window_days',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'config'               => 'array',
            'is_active'            => 'boolean',
            'clawback_window_days' => 'integer',
        ];
    }

    public function ledgerEntries()
    {
        return $this->hasMany(CommissionLedger::class);
    }

    // Human-readable type labels
    public function getTypeLabelAttribute(): string
    {
        return match ($this->type) {
            'flat'       => 'Flat Amount',
            'percentage' => 'Percentage',
            'tiered'     => 'Tiered',
            default      => ucfirst($this->type),
        };
    }

    // Preview of the rate/amount for display
    public function getRateSummaryAttribute(): string
    {
        return match ($this->type) {
            'flat'       => '$' . number_format($this->config['amount'] ?? 0, 2),
            'percentage' => (($this->config['rate'] ?? 0) * 100) . '%',
            'tiered'     => count($this->config['tiers'] ?? []) . ' tiers',
            default      => '—',
        };
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
