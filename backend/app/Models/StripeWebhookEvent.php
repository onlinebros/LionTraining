<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StripeWebhookEvent extends Model
{
    public const STATUS_RECEIVED  = 'received';
    public const STATUS_PROCESSED = 'processed';
    public const STATUS_FAILED    = 'failed';

    protected $fillable = [
        'stripe_event_id',
        'type',
        'api_version',
        'event_created_at',
        'livemode',
        'payload',
        'signature',
        'status',
        'processing_attempts',
        'received_at',
        'processed_at',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'event_created_at'    => 'integer',
            'livemode'            => 'boolean',
            'processing_attempts' => 'integer',
            'received_at'         => 'datetime',
            'processed_at'        => 'datetime',
        ];
    }

    public function isProcessed(): bool
    {
        return $this->status === self::STATUS_PROCESSED;
    }
}
