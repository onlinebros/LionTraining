<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A stretch of a document's text, for search when an offering's documents
 * no longer fit in a prompt whole.
 *
 * @property int $id
 * @property int $offering_document_id
 * @property int $position
 * @property string $text
 */
class KnowledgePassage extends Model
{
    public $timestamps = false;

    protected $fillable = ['offering_id', 'offering_document_id', 'position', 'text'];

    /** @return BelongsTo<OfferingDocument, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(OfferingDocument::class, 'offering_document_id');
    }
}
