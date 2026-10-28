<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A product line we represent in the Proposal Studio. Everything the
 * assistant knows about it comes from its documents and the guidance here,
 * so a second line is a new row, not new code. See memory-bank/proposal-studio.md.
 *
 * @property int $id
 * @property string $key
 * @property string $name
 * @property string $vendor_name
 * @property ?string $vendor_key
 * @property ?string $vendor_url
 * @property ?string $opportunity_key
 * @property ?string $summary
 * @property ?string $ai_guidance
 * @property ?string $prohibited_claims
 * @property ?string $discovery_checklist
 * @property bool $is_active
 */
class Offering extends Model
{
    protected $fillable = [
        'key', 'name', 'vendor_name', 'vendor_key', 'vendor_url', 'opportunity_key',
        'summary', 'ai_guidance', 'prohibited_claims', 'discovery_checklist', 'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return HasMany<OfferingDocument, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(OfferingDocument::class)->orderBy('id');
    }

    /** @return HasMany<Prospect, $this> */
    public function prospects(): HasMany
    {
        return $this->hasMany(Prospect::class);
    }

    /** @return HasMany<KnowledgeClaim, $this> */
    public function claims(): HasMany
    {
        return $this->hasMany(KnowledgeClaim::class)->orderBy('id');
    }

    /** @return HasMany<KnowledgeClaim, $this> */
    public function approvedClaims(): HasMany
    {
        return $this->claims()->where('status', 'approved');
    }

    /** @return HasMany<LibraryQuestion, $this> */
    public function libraryQuestions(): HasMany
    {
        return $this->hasMany(LibraryQuestion::class)->orderByDesc('id');
    }

    /** @return list<string> */
    public function checklistItems(): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $this->discovery_checklist) ?: [])));
    }
}
