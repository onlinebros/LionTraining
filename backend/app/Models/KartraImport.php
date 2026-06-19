<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KartraImport extends Model
{
    protected $fillable = [
        'kartra_type', 'kartra_id', 'kartra_title', 'kartra_description',
        'kartra_url', 'kartra_video_url', 'kartra_thumbnail_url', 'kartra_order',
        'parent_id', 'status', 'error_message',
        'local_category_id', 'local_lesson_id', 'local_content_block_id', 'video_asset_id',
        'raw_data',
    ];

    protected $casts = [
        'raw_data' => 'array',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('kartra_order');
    }

    public function videoAsset(): BelongsTo
    {
        return $this->belongsTo(VideoAsset::class, 'video_asset_id');
    }

    public function localCategory(): BelongsTo
    {
        return $this->belongsTo(TrainingCategory::class, 'local_category_id');
    }

    public function localLesson(): BelongsTo
    {
        return $this->belongsTo(TrainingLesson::class, 'local_lesson_id');
    }

    public function localContentBlock(): BelongsTo
    {
        return $this->belongsTo(TrainingContentBlock::class, 'local_content_block_id');
    }
}
