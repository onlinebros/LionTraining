<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * One section of a business account's microsite. The assistant drafts its
 * text from the brief; an admin edits it and publishes it, section by
 * section, so the client sees the site grow. Claims are kept as ids and shown
 * word for word from the library, as in proposals.
 *
 * @property int $id
 * @property int $business_account_id
 * @property int $position
 * @property string $kind
 * @property string $title
 * @property ?string $brief
 * @property ?string $body
 * @property ?list<int> $claim_ids
 * @property ?int $prospect_id
 * @property bool $pricing_only
 * @property string $status
 * @property ?Carbon $published_at
 * @property ?Carbon $drafted_at
 */
class MicrositeSection extends Model
{
    /** Kind => [label, default title, default brief, whether the assistant writes it]. */
    public const KINDS = [
        'welcome' => ['Welcome', 'Welcome', 'Welcome the company by name, say why we put this site together for them and what they will find on it.', true],
        'challenges' => ['Your challenges', 'What we have heard from you', 'Their situation and what they want to solve, in their terms, from everything we know about them.', true],
        'solution' => ['Our approach', 'How we would approach it', 'How the offering fits their buildings and their goals, and what working with us looks like.', true],
        'evidence' => ['Evidence', 'What the testing shows', 'Pick the approved claims that matter most to this company.', true],
        'proposal' => ['Proposal', 'Your proposal', 'Shows the published proposal of the chosen opportunity.', false],
        'documents' => ['Documents', 'Documents for you', 'The offering\'s shareable documents.', false],
        'faq' => ['Questions', 'Questions you may have', 'The questions a company like this is likely to ask, answered from the documents and approved claims.', true],
        'next_steps' => ['Next steps', 'Next steps', 'What happens next, who to talk to, and how to give us feedback here.', true],
        'custom' => ['Custom', 'More', '', true],
    ];

    protected $fillable = [
        'business_account_id', 'position', 'kind', 'title', 'brief', 'body',
        'claim_ids', 'prospect_id', 'pricing_only', 'status',
    ];

    protected function casts(): array
    {
        return [
            'claim_ids' => 'array',
            'pricing_only' => 'boolean',
            'published_at' => 'datetime',
            'drafted_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<BusinessAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(BusinessAccount::class, 'business_account_id');
    }

    /** @return BelongsTo<Prospect, $this> */
    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Prospect::class, 'prospect_id');
    }

    /** @return HasMany<MicrositeComment, $this> */
    public function comments(): HasMany
    {
        return $this->hasMany(MicrositeComment::class)->orderBy('id');
    }

    /** @return MorphMany<StudioJob, $this> */
    public function jobs(): MorphMany
    {
        return $this->morphMany(StudioJob::class, 'subject');
    }

    public function isAssistantBusy(): bool
    {
        return $this->jobs()->whereIn('status', [StudioJob::PENDING, StudioJob::CLAIMED])->get()
            ->contains(fn (StudioJob $job) => $job->isOpen());
    }

    public function isPublished(): bool
    {
        return $this->status === 'published';
    }

    public function isWritten(): bool
    {
        return (bool) (self::KINDS[$this->kind][3] ?? true);
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind][0] ?? ucfirst($this->kind);
    }

    /** The offering this section draws on: its opportunity's, if it has one. */
    public function offering(): ?Offering
    {
        return $this->opportunity?->offering;
    }

    /**
     * The approved claims it cites, in its order. A claim since rejected
     * drops out rather than being shown.
     *
     * @return Collection<int, KnowledgeClaim>
     */
    public function claims(): Collection
    {
        $ids = array_map('intval', $this->claim_ids ?? []);
        if (! $ids) {
            return collect();
        }
        $claims = KnowledgeClaim::with('document')->whereIn('id', $ids)->where('status', 'approved')->get()->keyBy('id');

        return collect($ids)->map(fn ($id) => $claims->get($id))->filter()->values();
    }
}
