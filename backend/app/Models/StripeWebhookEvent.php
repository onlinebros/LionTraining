<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class StripeWebhookEvent extends Model
{
    public const STATUS_RECEIVED  = 'received';
    public const STATUS_PROCESSED = 'processed';
    public const STATUS_FAILED    = 'failed';

    /**
     * Written in place of a signature header on an event fetched from Stripe's
     * API rather than delivered to an endpoint. Its authenticity comes from
     * having asked Stripe for it with the account's own key.
     */
    public const SIGNATURE_API_FETCH = 'fetched-from-api';

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

    /**
     * Write one event to the ledger, keyed on Stripe's event id.
     *
     * insertOrIgnore rather than create()-and-catch: on Postgres a raised unique
     * violation aborts the surrounding transaction. See IngestsSignedWebhooks.
     *
     * @param  array<string,mixed>  $decoded
     * @return int Rows inserted — 0 means the event was already recorded.
     */
    public static function record(
        array $decoded,
        string $rawPayload,
        ?string $signature,
        string $endpoint,
        bool $signatureValid,
    ): int {
        $now = Carbon::now();

        return static::query()->insertOrIgnore([
            'stripe_event_id'  => $decoded['id'],
            'type'             => (string) ($decoded['type'] ?? 'unknown'),
            'api_version'      => isset($decoded['api_version']) ? (string) $decoded['api_version'] : null,
            'event_created_at' => isset($decoded['created']) && is_int($decoded['created']) ? $decoded['created'] : null,
            'livemode'         => (bool) ($decoded['livemode'] ?? false),
            'payload'          => $rawPayload,
            'signature'        => (string) $signature,
            'signature_valid'  => $signatureValid,
            'endpoint'         => $endpoint,
            'status'           => self::STATUS_RECEIVED,
            'received_at'      => $now,
            'created_at'       => $now,
            'updated_at'       => $now,
        ]);
    }

    public function isProcessed(): bool
    {
        return $this->status === self::STATUS_PROCESSED;
    }

    /** Recovered from the provider's event log rather than delivered by webhook. */
    public function wasFetchedFromApi(): bool
    {
        return $this->signature === self::SIGNATURE_API_FETCH;
    }
}
