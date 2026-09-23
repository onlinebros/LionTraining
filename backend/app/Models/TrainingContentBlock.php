<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrainingContentBlock extends Model
{
    protected $fillable = [
        'lesson_id', 'type', 'title',
        'video_url', 'video_provider', 'video_asset_id',
        'body',
        'file_path', 'file_disk', 'file_name', 'file_size', 'file_mime',
        'sort_order', 'is_active',
    ];

    protected $casts = ['is_active' => 'boolean'];

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(TrainingLesson::class, 'lesson_id');
    }

    public function videoAsset(): BelongsTo
    {
        return $this->belongsTo(VideoAsset::class, 'video_asset_id');
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

    /**
     * How this block's video should be shown.
     *
     * Self-hosting wins over any embed. Everything Kartra served came off a
     * CloudFront URL that is public to anyone who has it and would keep working
     * after someone cancels, so where we hold the file ourselves we play our
     * own copy through the gated stream and ignore the original source.
     *
     * 'embed' is left for material that genuinely lives elsewhere — a YouTube
     * link an admin adds by hand.
     */
    public function playbackMode(): string
    {
        if ($this->videoAsset?->isPlayable()) {
            return 'self';
        }

        if ($this->isEmbeddable()) {
            return 'embed';
        }

        return 'none';
    }

    public function fileDiskName(): string
    {
        return $this->file_disk ?: 'local';
    }

    public function formattedFileSize(): string
    {
        $bytes = (int) $this->file_size;
        if ($bytes < 1024) return $bytes . ' B';
        if ($bytes < 1048576) return round($bytes / 1024, 1) . ' KB';
        return round($bytes / 1048576, 1) . ' MB';
    }
}
