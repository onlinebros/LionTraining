<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * The Q3-AI Assistant's conversation about one support ticket, shared by every
 * member of staff who opens it. See memory-bank/request-assistant.md.
 *
 * @property int $id
 * @property int $ticket_id
 * @property ?string $pending
 * @property ?Carbon $claimed_at
 * @property ?Carbon $busy_since
 * @property ?string $claude_session_id
 * @property ?string $snapshot_commit
 * @property ?int $change_request_id
 */
class SupportAssist extends Model
{
    protected $fillable = [
        'ticket_id',
        'pending',
        'claimed_at',
        'busy_since',
        'claude_session_id',
        'snapshot_commit',
        'change_request_id',
    ];

    protected function casts(): array
    {
        return [
            'claimed_at' => 'datetime',
            'busy_since' => 'datetime',
        ];
    }

    /** @return BelongsTo<SupportTicket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'ticket_id');
    }

    /** @return BelongsTo<ChangeRequest, $this> */
    public function changeRequest(): BelongsTo
    {
        return $this->belongsTo(ChangeRequest::class);
    }

    /** @return HasMany<SupportAssistMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(SupportAssistMessage::class)->orderBy('id');
    }

    public function isBusy(): bool
    {
        return $this->busy_since !== null
            && $this->busy_since->gt(now()->subMinutes(ChangeRequest::BUSY_STALE_MINUTES));
    }

    // Asked, and nothing has taken it for longer than a reply can take: the
    // relay on dev is not running.
    public function isStuck(): bool
    {
        return $this->pending !== null && ! $this->isBusy();
    }

    public function latestAnswer(): ?SupportAssistMessage
    {
        return $this->messages->where('role', SupportAssistMessage::ROLE_ASSISTANT)->last();
    }
}
