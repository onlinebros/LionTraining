<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A change somebody would like made to the application, worked into a plan
 * with the Request Assistant. A plan is a proposal: approving one records a
 * decision, it does not build anything. See memory-bank/request-assistant.md.
 *
 * @property int $id
 * @property string $number
 * @property string $status
 * @property ?string $plan
 * @property ?string $source_url
 * @property ?string $source_title
 * @property ?string $claude_session_id
 * @property ?string $snapshot_commit
 * @property ?Carbon $plan_drafted_at
 * @property ?Carbon $assistant_busy_since
 * @property ?Carbon $submitted_at
 * @property ?Carbon $reviewed_at
 */
class ChangeRequest extends Model
{
    public const STATUSES = [
        'drafting' => 'Drafting',
        'submitted' => 'Submitted for review',
        'needs_info' => 'Needs more info',
        'approved' => 'Approved',
        'rejected' => 'Not planned',
        'implemented' => 'Implemented',
    ];

    // Statuses a reviewer can set. "drafting" and "submitted" belong to the requester.
    public const REVIEW_STATUSES = ['needs_info', 'approved', 'rejected', 'implemented'];

    // A reply that has been "busy" this long is taken to have died with its
    // worker. Longer than the job's own timeout (two CLI runs plus slack).
    public const BUSY_STALE_MINUTES = 25;

    protected $fillable = [
        'user_id',
        'title',
        'source_url',
        'source_title',
        'status',
        'plan',
        'plan_drafted_at',
        'claude_session_id',
        'snapshot_commit',
        'assistant_busy_since',
        'assistant_pending',
        'assistant_claimed_at',
        'reviewer_name',
        'submitted_at',
        'reviewed_by',
        'reviewed_at',
        'review_notes',
    ];

    protected function casts(): array
    {
        return [
            'plan_drafted_at' => 'datetime',
            'assistant_busy_since' => 'datetime',
            'assistant_claimed_at' => 'datetime',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (ChangeRequest $request) {
            if (empty($request->number)) {
                $request->number = 'CR-'.str_pad((string) ((static::max('id') ?? 0) + 1), 4, '0', STR_PAD_LEFT);
            }
        });
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** @return HasMany<ChangeRequestMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(ChangeRequestMessage::class)->orderBy('id');
    }

    // Reviews are made on dev by someone with no account here, so the name is
    // kept on the row; reviewed_by is for a reviewer who has one.
    public function reviewerLabel(): ?string
    {
        return $this->reviewer_name ?: $this->reviewer?->name;
    }

    public function isAssistantBusy(): bool
    {
        return $this->assistant_busy_since !== null
            && $this->assistant_busy_since->gt(now()->subMinutes(self::BUSY_STALE_MINUTES));
    }

    // The requester may keep talking until a decision is made; "needs_info"
    // hands it back to them.
    public function isOpenForConversation(): bool
    {
        return in_array($this->status, ['drafting', 'submitted', 'needs_info'], true);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? ucfirst($this->status);
    }

    public function statusBadgeClass(): string
    {
        return match ($this->status) {
            'drafting' => 'badge-light-secondary',
            'submitted' => 'badge-light-primary',
            'needs_info' => 'badge-light-warning',
            'approved' => 'badge-light-success',
            'rejected' => 'badge-light-danger',
            'implemented' => 'badge-light-info',
            default => 'badge-light-secondary',
        };
    }

    /** The whole request as one Markdown file, to hand to whoever builds it. */
    public function toMarkdown(): string
    {
        $this->loadMissing('user', 'reviewer', 'messages.user');

        $lines = [
            "# {$this->number}: {$this->title}",
            '',
            "- Requested by: {$this->user?->name} <{$this->user?->email}>",
            "- Status: {$this->statusLabel()}",
            '- Opened: '.$this->created_at?->toDayDateTimeString(),
        ];
        if ($this->source_url) {
            $lines[] = '- Started from: '.($this->source_title ? "{$this->source_title} — " : '').$this->source_url;
        }
        if ($this->snapshot_commit) {
            $lines[] = '- Code read at commit: '.substr($this->snapshot_commit, 0, 12);
        }
        if ($this->reviewed_at) {
            $lines[] = "- Reviewed by {$this->reviewerLabel()} on ".$this->reviewed_at->toDayDateTimeString();
        }
        if ($this->review_notes) {
            $lines[] = '';
            $lines[] = '## Reviewer notes';
            $lines[] = '';
            $lines[] = $this->review_notes;
        }

        $lines[] = '';
        $lines[] = '## Plan';
        $lines[] = '';
        $lines[] = $this->plan ?: '_No plan has been drafted yet._';

        $lines[] = '';
        $lines[] = '## Conversation';
        foreach ($this->messages as $message) {
            if ($message->role === ChangeRequestMessage::ROLE_ERROR) {
                continue;
            }
            $lines[] = '';
            $lines[] = '### '.$message->authorLabel().' — '.$message->created_at?->toDayDateTimeString();
            $lines[] = '';
            $lines[] = $message->body;
        }

        return implode("\n", $lines)."\n";
    }
}
