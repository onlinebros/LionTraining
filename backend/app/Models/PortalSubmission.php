<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Something a prospect sent from their portal.
 *
 * @property int $id
 * @property int $prospect_id
 * @property ?int $portal_invite_id
 * @property string $kind
 * @property ?string $body
 * @property ?array<string, string> $fields
 * @property ?list<array{path: string, name: string, size: int}> $files
 * @property ?string $answered_by
 * @property ?string $answer
 * @property ?Carbon $answered_at
 */
class PortalSubmission extends Model
{
    public const KINDS = [
        'details' => 'Added details',
        'correction' => 'Corrected our summary',
        'question' => 'Asked a question',
        'quote_request' => 'Asked for a quote',
        'survey_request' => 'Asked for a site survey',
    ];

    protected $fillable = ['prospect_id', 'portal_invite_id', 'kind', 'body', 'fields', 'files', 'answered_by', 'answer', 'answered_by_user_id', 'answered_at'];

    protected function casts(): array
    {
        return [
            'fields' => 'array',
            'files' => 'array',
            'answered_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Prospect, $this> */
    public function prospect(): BelongsTo
    {
        return $this->belongsTo(Prospect::class);
    }

    /** @return BelongsTo<PortalInvite, $this> */
    public function invite(): BelongsTo
    {
        return $this->belongsTo(PortalInvite::class, 'portal_invite_id');
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? ucfirst($this->kind);
    }

    public function isWaitingForAnswer(): bool
    {
        return $this->kind === 'question' && $this->answer === null;
    }
}
