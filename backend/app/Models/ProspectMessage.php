<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $role
 * @property string $body
 * @property ?array<string, mixed> $meta
 */
class ProspectMessage extends Model
{
    public const ROLE_USER = 'user';

    public const ROLE_ASSISTANT = 'assistant';

    // Something the prospect sent through their portal, copied into the
    // conversation so the assistant and the admin see it in context.
    public const ROLE_PROSPECT = 'prospect';

    // A reply that failed. Shown so the admin knows to try again; never sent
    // back to the assistant as part of the conversation.
    public const ROLE_ERROR = 'error';

    protected $fillable = ['prospect_id', 'user_id', 'role', 'body', 'meta'];

    protected function casts(): array
    {
        return ['meta' => 'array'];
    }

    /** @return BelongsTo<Prospect, $this> */
    public function prospect(): BelongsTo
    {
        return $this->belongsTo(Prospect::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function authorLabel(): string
    {
        return match ($this->role) {
            self::ROLE_ASSISTANT => 'Assistant',
            self::ROLE_ERROR => 'System',
            self::ROLE_PROSPECT => ($this->meta['from'] ?? 'The prospect').' (portal)',
            default => $this->user->name ?? 'Admin',
        };
    }
}
