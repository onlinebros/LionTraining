<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A prospect company in the Proposal Studio: what we know about them, the
 * discovery conversation with the assistant, the plan and emails it drafted,
 * the proposal versions, and their portal. See memory-bank/proposal-studio.md.
 *
 * @property int $id
 * @property int $offering_id
 * @property string $company_name
 * @property ?string $website
 * @property ?string $industry
 * @property ?string $contact_name
 * @property ?string $contact_email
 * @property ?string $contact_phone
 * @property string $status
 * @property ?array{summary?: string, facts?: list<array{label: string, value: string, basis?: string}>, sites?: list<array<string, mixed>>, open_questions?: list<string>, vendor_questions?: list<string>} $profile
 * @property ?string $draft
 * @property ?Carbon $draft_at
 * @property ?string $portal_ref
 * @property ?string $outcome_note
 * @property ?int $created_by
 */
class Prospect extends Model
{
    public const STATUSES = [
        'discovery' => 'Discovery',
        'proposal' => 'Proposal',
        'won' => 'Won',
        'lost' => 'Lost',
    ];

    protected $fillable = [
        'offering_id', 'company_name', 'website', 'industry',
        'contact_name', 'contact_email', 'contact_phone',
        'status', 'outcome_note', 'created_by', 'referred_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'profile' => 'array',
            'draft_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Offering, $this> */
    public function offering(): BelongsTo
    {
        return $this->belongsTo(Offering::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<ProspectMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(ProspectMessage::class)->orderBy('id');
    }

    /** @return HasMany<Proposal, $this> */
    public function proposals(): HasMany
    {
        return $this->hasMany(Proposal::class)->orderByDesc('version');
    }

    /** @return HasOne<Proposal, $this> */
    public function publishedProposal(): HasOne
    {
        return $this->hasOne(Proposal::class)->where('status', 'published')->latestOfMany('version');
    }

    /** @return HasMany<PortalInvite, $this> */
    public function invites(): HasMany
    {
        return $this->hasMany(PortalInvite::class)->orderBy('id');
    }

    /** @return HasMany<PortalSubmission, $this> */
    public function submissions(): HasMany
    {
        return $this->hasMany(PortalSubmission::class)->orderByDesc('id');
    }

    /** @return HasMany<PortalEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(PortalEvent::class)->orderByDesc('id');
    }

    /** @return MorphMany<StudioJob, $this> */
    public function jobs(): MorphMany
    {
        return $this->morphMany(StudioJob::class, 'subject');
    }

    /** The AI job this prospect is waiting on, if any. */
    public function openJob(): ?StudioJob
    {
        return $this->jobs()->whereIn('status', [StudioJob::PENDING, StudioJob::CLAIMED])->latest('id')->get()
            ->first(fn (StudioJob $job) => $job->isOpen());
    }

    public function isAssistantBusy(): bool
    {
        return $this->openJob() !== null;
    }

    /** The portal's address, made the first time it is needed. */
    public function ensurePortalRef(): string
    {
        if (! $this->portal_ref) {
            $this->forceFill(['portal_ref' => Str::lower(Str::random(16))])->save();
        }

        return (string) $this->portal_ref;
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? ucfirst($this->status);
    }

    public function statusBadgeClass(): string
    {
        return match ($this->status) {
            'discovery' => 'badge-light-primary',
            'proposal' => 'badge-light-warning',
            'won' => 'badge-light-success',
            'lost' => 'badge-light-secondary',
            default => 'badge-light-secondary',
        };
    }
}
