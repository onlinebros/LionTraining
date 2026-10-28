<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One line on a business account's timeline. A task is an activity with a
 * due date, done when done_at is set. "client" lines are what the account's
 * people did on their microsite; "system" lines are what we changed.
 *
 * @property int $id
 * @property int $business_account_id
 * @property ?int $user_id
 * @property string $type
 * @property string $body
 * @property ?array<string, mixed> $meta
 * @property ?Carbon $due_on
 * @property ?Carbon $done_at
 * @property Carbon $created_at
 */
class BusinessActivity extends Model
{
    public const TYPES = [
        'note' => 'Note',
        'call' => 'Call',
        'meeting' => 'Meeting',
        'email' => 'Email',
        'task' => 'Task',
    ];

    protected $fillable = ['business_account_id', 'user_id', 'type', 'body', 'meta', 'due_on', 'done_at'];

    protected function casts(): array
    {
        return ['meta' => 'array', 'due_on' => 'date', 'done_at' => 'datetime'];
    }

    /** @return BelongsTo<BusinessAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(BusinessAccount::class, 'business_account_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isOpenTask(): bool
    {
        return $this->type === 'task' && $this->done_at === null;
    }

    public function isOverdue(): bool
    {
        return $this->isOpenTask() && $this->due_on !== null && $this->due_on->lt(today());
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? match ($this->type) {
            'client' => 'Client',
            default => 'Update',
        };
    }
}
