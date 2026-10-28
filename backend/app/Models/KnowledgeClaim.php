<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A claim about an offering, quoted word for word from one of its documents.
 * The quote is checked against the document's text when it is saved and is
 * never edited: a claim we reworded is a claim we own. Only approved claims
 * reach a prospect, and they are rendered from this row, never from AI text.
 *
 * @property int $id
 * @property int $offering_id
 * @property int $offering_document_id
 * @property ?string $topic
 * @property string $quote
 * @property ?string $footnote
 * @property string $status
 * @property ?Carbon $reviewed_at
 */
class KnowledgeClaim extends Model
{
    public const STATUSES = ['draft' => 'Waiting for review', 'approved' => 'Approved', 'rejected' => 'Rejected'];

    protected $fillable = ['offering_id', 'offering_document_id', 'topic', 'quote', 'footnote', 'status', 'reviewed_by', 'reviewed_at'];

    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime'];
    }

    /** @return BelongsTo<OfferingDocument, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(OfferingDocument::class, 'offering_document_id');
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * Whether $quote appears in $text, ignoring differences in whitespace,
     * quote marks and dashes that text extraction introduces.
     */
    public static function isQuotedFrom(string $quote, string $text): bool
    {
        $normal = fn (string $s) => preg_replace('/\s+/u', ' ', strtr(mb_strtolower($s), [
            "\u{2018}" => "'", "\u{2019}" => "'", "\u{201C}" => '"', "\u{201D}" => '"',
            "\u{2013}" => '-', "\u{2014}" => '-', "\u{00A0}" => ' ',
        ])) ?? $s;

        $needle = trim($normal($quote));

        return $needle !== '' && mb_strlen($needle) >= 8 && str_contains($normal($text), $needle);
    }
}
