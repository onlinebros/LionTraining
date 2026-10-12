<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $role
 * @property string $body
 * @property ?array<string, mixed> $meta
 * @property ?string $author_name
 */
class ChangeRequestMessage extends Model
{
    public const ROLE_USER = 'user';

    public const ROLE_ASSISTANT = 'assistant';

    public const ROLE_REVIEWER = 'reviewer';

    // A reply that failed. Shown so the requester knows to try again; never
    // sent back to the assistant as part of the conversation.
    public const ROLE_ERROR = 'error';

    protected $fillable = [
        'change_request_id',
        'role',
        'user_id',
        'body',
        'meta',
        'author_name',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
        ];
    }

    /** @return BelongsTo<ChangeRequest, $this> */
    public function changeRequest(): BelongsTo
    {
        return $this->belongsTo(ChangeRequest::class);
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
            self::ROLE_REVIEWER => ($this->author_name ?: $this->user->name ?? 'Reviewer').' (reviewer)',
            self::ROLE_ERROR => 'System',
            default => $this->author_name ?: $this->user->name ?? 'Requester',
        };
    }
}
