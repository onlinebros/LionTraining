<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * One piece of AI work for the Proposal Studio, waiting for a worker. What it
 * asks and what is done with the answer belong to its handler
 * (App\Services\ProposalStudio\Jobs); the worker only carries the prompt to
 * the CLI and the answer back.
 *
 * @property int $id
 * @property string $kind
 * @property string $subject_type
 * @property int $subject_id
 * @property string $status
 * @property ?Carbon $claimed_at
 * @property int $attempts
 * @property ?Carbon $finished_at
 * @property ?string $error
 * @property ?array<string, mixed> $meta
 */
class StudioJob extends Model
{
    public const PENDING = 'pending';

    public const CLAIMED = 'claimed';

    public const DONE = 'done';

    public const FAILED = 'failed';

    // A job claimed this long ago and not answered died with its worker, and
    // is offered again. Longer than the CLI's own timeout.
    public const STALE_MINUTES = 12;

    protected $fillable = ['kind', 'subject_type', 'subject_id', 'status', 'meta', 'created_by'];

    protected function casts(): array
    {
        return [
            'claimed_at' => 'datetime',
            'finished_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    /** @return MorphTo<Model, $this> */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function isOpen(): bool
    {
        return $this->status === self::PENDING
            || ($this->status === self::CLAIMED && $this->claimed_at?->gt(now()->subMinutes(self::STALE_MINUTES)));
    }
}
