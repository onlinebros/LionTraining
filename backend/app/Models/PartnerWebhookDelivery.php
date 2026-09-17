<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One outbound event to a partner company, and what became of it.
 *
 * @property string $event_id
 * @property string $status
 */
class PartnerWebhookDelivery extends Model
{
    public const STATUS_PENDING   = 'pending';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_FAILED    = 'failed';

    /** The only event we send today. Named so a partner can switch on it. */
    public const EVENT_SPOT_CLAIMED = 'spot.claimed';

    /** A hand-fired event with no spot behind it, for checking an endpoint. */
    public const EVENT_PING = 'ping';

    protected $fillable = [
        'partner_company_id',
        'event_id',
        'event_type',
        'user_id',
        'external_user_id',
        'payload',
        'status',
        'attempts',
        'response_status',
        'response_body',
        'error',
        'delivered_at',
        'last_attempt_at',
    ];

    protected function casts(): array
    {
        return [
            'payload'         => 'array',
            'delivered_at'    => 'datetime',
            'last_attempt_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(PartnerCompany::class, 'partner_company_id');
    }

    public function spot(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function isDelivered(): bool
    {
        return $this->status === self::STATUS_DELIVERED;
    }
}
