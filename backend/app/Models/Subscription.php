<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A mirror of a subscription held at the payment provider.
 *
 * The provider is the source of truth. These rows exist so screens render
 * without an API call per page, and the webhook ledger keeps them in step.
 */
class Subscription extends Model
{
    public const STATUS_TRIALING   = 'trialing';
    public const STATUS_ACTIVE     = 'active';
    public const STATUS_PAST_DUE   = 'past_due';
    public const STATUS_CANCELED   = 'canceled';
    public const STATUS_INCOMPLETE = 'incomplete';
    public const STATUS_UNPAID     = 'unpaid';

    /** Statuses that grant access outright, with no grace arithmetic. */
    public const ENTITLING = [self::STATUS_TRIALING, self::STATUS_ACTIVE];

    /** Enrollment options: what starts the first charge. */
    public const TRIGGER_LAUNCH     = 'launch';
    public const TRIGGER_COMMISSION = 'commission';

    public const TRIGGERS = [self::TRIGGER_LAUNCH, self::TRIGGER_COMMISSION];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'current_period_start' => 'datetime',
            'current_period_end'   => 'datetime',
            'trial_ends_at'        => 'datetime',
            'canceled_at'          => 'datetime',
            'ended_at'             => 'datetime',
            'last_synced_at'       => 'datetime',
            'cancel_at_period_end' => 'boolean',
            'is_prelaunch_trial'   => 'boolean',
            'billing_trigger_met_at' => 'datetime',
            'amount'               => 'integer',
        ];
    }

    /**
     * Parked until the partner's paid commissions reach the threshold.
     *
     * Only while still trialing: once the trial ends the partner is paying, and
     * the option they joined under no longer holds anything back.
     */
    public function isCommissionHold(): bool
    {
        return $this->billing_trigger === self::TRIGGER_COMMISSION
            && $this->status === self::STATUS_TRIALING;
    }

    public function scopeCommissionHolds(Builder $query): Builder
    {
        return $query->where('billing_trigger', self::TRIGGER_COMMISSION)
            ->where('status', self::STATUS_TRIALING);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Does this subscription currently entitle its owner to access?
     *
     * `past_due` is deliberately not a flat yes or no. A card that fails on
     * renewal should not lock someone out the same hour, but it must lock them
     * out eventually — so it grants access until `grace_days` past the period
     * end. That window is a documented config value rather than a side effect
     * of which statuses happen to be listed above.
     */
    public function entitles(): bool
    {
        if (in_array($this->status, self::ENTITLING, true)) {
            return true;
        }

        if ($this->status !== self::STATUS_PAST_DUE) {
            return false;
        }

        $graceDays = (int) config('stripe.grace_days', 0);

        // No period end recorded means the mirror is incomplete; fail closed
        // rather than grant indefinite access off a missing value.
        if ($this->current_period_end === null) {
            return false;
        }

        return $this->current_period_end->copy()->addDays($graceDays)->isFuture();
    }

    /** Ended for good — resume is impossible and a new subscription is needed. */
    public function hasEnded(): bool
    {
        return $this->ended_at !== null;
    }

    public function scopeEntitling(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->whereIn('status', self::ENTITLING)
                ->orWhere(function (Builder $q2) {
                    $q2->where('status', self::STATUS_PAST_DUE)
                        ->whereNotNull('current_period_end')
                        ->where('current_period_end', '>', now()->subDays((int) config('stripe.grace_days', 0)));
                });
        });
    }

    /** Subscriptions whose mirror has not been confirmed against the provider recently. */
    public function scopeStale(Builder $query, int $hours = 24): Builder
    {
        return $query->where(function (Builder $q) use ($hours) {
            $q->whereNull('last_synced_at')
                ->orWhere('last_synced_at', '<', now()->subHours($hours));
        });
    }
}
