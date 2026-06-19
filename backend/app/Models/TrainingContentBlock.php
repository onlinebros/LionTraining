<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrainingContentBlock extends Model
{
    protected $fillable = [
        'lesson_id', 'type', 'title',
        'video_url', 'video_provider',
        'body',
        'file_path', 'file_name', 'file_size', 'file_mime',
        'sort_order', 'is_active',
    ];

    protected $casts = ['is_active' => 'boolean'];

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(TrainingLesson::class, 'lesson_id');
    }

    /** Returns an embeddable iframe src for YouTube/Vimeo, or the raw URL for direct video files. */
    public function embedUrl(): ?string
    {
        if (!$this->video_url) return null;

        if (preg_match('/(?:youtube\.com\/watch\?v=|youtu\.be\/)([^&\s]+)/', $this->video_url, $m)) {
            return 'https://www.youtube.com/embed/' . $m[1];
        }

        if (preg_match('/vimeo\.com\/(\d+)/', $this->video_url, $m)) {
            return 'https://player.vimeo.com/video/' . $m[1];
        }

        return $this->video_url;
    }

    public function isYouTube(): bool
    {
        return $this->video_provider === 'youtube'
            || str_contains((string) $this->video_url, 'youtube')
            || str_contains((string) $this->video_url, 'youtu.be');
    }

    public function isVimeo(): bool
    {
        return $this->video_provider === 'vimeo'
            || str_contains((string) $this->video_url, 'vimeo');
    }

    public function isEmbeddable(): bool
    {
        return $this->isYouTube() || $this->isVimeo();
    }

    public function formattedFileSize(): string
    {
        $bytes = (int) $this->file_size;
        if ($bytes < 1024) return $bytes . ' B';
        if ($bytes < 1048576) return round($bytes / 1024, 1) . ' KB';
        return round($bytes / 1048576, 1) . ' MB';
    }
}
