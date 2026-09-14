<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A card saved at the provider. No raw card details are ever stored here —
 * only the provider's id and the display fragments needed to render "Visa
 * •••• 4242".
 */
class PaymentMethod extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'exp_month'  => 'integer',
            'exp_year'   => 'integer',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function label(): string
    {
        $brand = ucfirst($this->brand ?? 'Card');

        return $this->last4 ? "{$brand} •••• {$this->last4}" : $brand;
    }

    public function isExpired(): bool
    {
        if (! $this->exp_year || ! $this->exp_month) {
            return false;
        }

        return now()->startOfMonth()->gt(
            now()->setDate($this->exp_year, $this->exp_month, 1)->startOfMonth()
        );
    }
}
