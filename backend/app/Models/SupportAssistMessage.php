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
class SupportAssistMessage extends Model
{
    public const ROLE_USER = 'user';

    public const ROLE_ASSISTANT = 'assistant';

    // A reply that failed. Shown so staff know to try again; never sent back
    // to the assistant.
    public const ROLE_ERROR = 'error';

    // What the assistant concluded about the ticket (meta.verdict).
    public const VERDICTS = [
        'answerable' => ['Can be answered now', 'badge-light-success'],
        'manual_action' => ['Needs an admin action', 'badge-light-warning'],
        'not_built' => ['Needs something not built yet', 'badge-light-danger'],
    ];

    protected $fillable = [
        'support_assist_id',
        'role',
        'user_id',
        'body',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
        ];
    }

    /** @return BelongsTo<SupportAssist, $this> */
    public function assist(): BelongsTo
    {
        return $this->belongsTo(SupportAssist::class, 'support_assist_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function verdict(): ?string
    {
        $verdict = $this->meta['verdict'] ?? null;

        return isset(self::VERDICTS[$verdict]) ? $verdict : null;
    }

    public function section(string $key): ?string
    {
        $value = $this->meta['sections'][$key] ?? null;

        return filled($value) ? (string) $value : null;
    }
}
