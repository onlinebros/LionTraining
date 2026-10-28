<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * One version of the proposal a prospect sees in their portal. Drafted by
 * the assistant, edited by an admin, reviewed by the vendor while the
 * offering is in its review period (owner decision D5), then published.
 * Editing a published proposal makes a new version.
 *
 * @property int $id
 * @property int $prospect_id
 * @property int $version
 * @property string $status
 * @property ?array{headline?: string, intro?: string, situation?: string, plan?: list<array{site?: string, product?: string, quantity?: string, rationale?: string}>, how_it_works?: string, benefits?: list<array{title?: string, text?: string}>, claim_ids?: list<int>, pricing?: string, next_steps?: string} $content
 * @property string $vendor_review
 * @property ?string $vendor_review_note
 * @property ?Carbon $vendor_reviewed_at
 * @property ?Carbon $published_at
 */
class Proposal extends Model
{
    public const STATUSES = [
        'draft' => 'Draft',
        'in_review' => 'With the vendor for review',
        'published' => 'Published',
        'superseded' => 'Replaced by a newer version',
    ];

    protected $fillable = ['prospect_id', 'version', 'status', 'content', 'vendor_review', 'created_by'];

    protected function casts(): array
    {
        return [
            'content' => 'array',
            'vendor_reviewed_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Prospect, $this> */
    public function prospect(): BelongsTo
    {
        return $this->belongsTo(Prospect::class);
    }

    /** @return BelongsTo<User, $this> */
    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    /** @return BelongsTo<User, $this> */
    public function vendorReviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'vendor_reviewed_by');
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? ucfirst($this->status);
    }

    /**
     * The approved claims this version cites, in its order. A claim that has
     * since been rejected drops out of the page rather than being shown.
     *
     * @return Collection<int, KnowledgeClaim>
     */
    public function claims(): Collection
    {
        $ids = array_map('intval', $this->content['claim_ids'] ?? []);
        if (! $ids) {
            return collect();
        }
        $claims = KnowledgeClaim::with('document')->whereIn('id', $ids)->where('status', 'approved')->get()->keyBy('id');

        return collect($ids)->map(fn ($id) => $claims->get($id))->filter()->values();
    }
}
