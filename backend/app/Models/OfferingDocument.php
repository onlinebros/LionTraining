<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A source the assistant learns an offering from: a brochure, spec sheet,
 * test report, FAQ, web page or our own notes. The file is kept on the
 * private disk; its text is extracted once and is what prompts carry.
 *
 * @property int $id
 * @property int $offering_id
 * @property string $title
 * @property string $kind
 * @property string $audience
 * @property bool $use_for_ai
 * @property ?string $source_url
 * @property ?Carbon $read_on
 * @property ?string $file_path
 * @property ?string $original_name
 * @property ?string $mime
 * @property ?int $size_bytes
 * @property ?string $extracted_text
 * @property int $text_chars
 * @property ?string $extraction_error
 */
class OfferingDocument extends Model
{
    public const KINDS = [
        'brochure' => 'Brochure or sell sheet',
        'spec_sheet' => 'Spec sheet or manual',
        'test_report' => 'Test report or certification',
        'claims' => 'Approved claims',
        'case_study' => 'Case study or testimonial',
        'pricing' => 'Pricing or terms',
        'faq' => 'FAQ or objection handling',
        'web_page' => 'Vendor web page',
        'training' => 'Training session transcript',
        'notes' => 'Our notes or call notes',
        'other' => 'Other',
    ];

    public const AUDIENCES = [
        'internal' => 'Internal only',
        'shareable' => 'May be shown to prospects',
    ];

    protected $fillable = [
        'offering_id', 'title', 'kind', 'audience', 'use_for_ai', 'source_url', 'read_on',
        'file_path', 'original_name', 'mime', 'size_bytes',
        'extracted_text', 'text_chars', 'extraction_error', 'uploaded_by',
    ];

    protected function casts(): array
    {
        return [
            'use_for_ai' => 'boolean',
            'read_on' => 'date',
        ];
    }

    /** @return BelongsTo<Offering, $this> */
    public function offering(): BelongsTo
    {
        return $this->belongsTo(Offering::class);
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** @return HasMany<KnowledgePassage, $this> */
    public function passages(): HasMany
    {
        return $this->hasMany(KnowledgePassage::class)->orderBy('position');
    }

    /** @return HasMany<KnowledgeClaim, $this> */
    public function claims(): HasMany
    {
        return $this->hasMany(KnowledgeClaim::class);
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? ucfirst($this->kind);
    }
}
