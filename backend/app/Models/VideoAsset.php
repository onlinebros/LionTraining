<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class VideoAsset extends Model
{
    protected $fillable = [
        'title', 'description',
        'source', 'source_url', 'source_id',
        'local_path', 'local_filename', 'file_size', 'mime_type', 'duration_seconds', 'thumbnail_path',
        'disk', 'storage_path', 'published_at',
        'vimeo_status', 'vimeo_video_id', 'vimeo_uri', 'vimeo_url', 'vimeo_embed_url',
        'vimeo_privacy', 'vimeo_upload_error', 'vimeo_uploaded_at',
        'content_block_id', 'notes',
    ];

    protected $casts = [
        'vimeo_uploaded_at' => 'datetime',
        'published_at'      => 'datetime',
    ];

    public function contentBlock(): BelongsTo
    {
        return $this->belongsTo(TrainingContentBlock::class, 'content_block_id');
    }

    public function kartraImports(): HasMany
    {
        return $this->hasMany(KartraImport::class, 'video_asset_id');
    }

    public function isDownloaded(): bool
    {
        return $this->local_path && Storage::disk('local')->exists($this->local_path);
    }

    public function isOnVimeo(): bool
    {
        return $this->vimeo_status === 'uploaded' && $this->vimeo_video_id;
    }

    // ── Where the playable bytes are ──────────────────────────────────────────
    //
    // `storage_path`/`disk` are set once the asset has been published to its
    // serving location. Until then it plays straight from the Kartra download
    // on the private local disk, so the library is watchable on dev — and on
    // production the moment the extract lands — without waiting for a transfer.

    public function mediaDisk(): ?string
    {
        return $this->storage_path ? $this->disk : 'local';
    }

    public function mediaPath(): ?string
    {
        return $this->storage_path ?: $this->local_path;
    }

    /** Is there a file we can actually stream for this asset? */
    public function isPlayable(): bool
    {
        $path = $this->mediaPath();

        if (! $path) {
            return false;
        }

        return Storage::disk($this->mediaDisk() ?: config('training.disk', 'local'))->exists($path);
    }

    /** Has this asset been moved to its serving location yet? */
    public function isPublished(): bool
    {
        return $this->published_at !== null && filled($this->storage_path);
    }

    public function formattedSize(): string
    {
        $bytes = (int) $this->file_size;
        if ($bytes <= 0) return '—';
        if ($bytes < 1_048_576) return round($bytes / 1024, 1) . ' KB';
        if ($bytes < 1_073_741_824) return round($bytes / 1_048_576, 1) . ' MB';
        return round($bytes / 1_073_741_824, 2) . ' GB';
    }

    public function localAbsolutePath(): ?string
    {
        if (!$this->local_path) return null;
        return Storage::disk('local')->path($this->local_path);
    }
}
