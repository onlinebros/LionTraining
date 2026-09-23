<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class KartraFile extends Model
{
    protected $fillable = [
        'kartra_import_id',
        'kartra_download_id',
        'kartra_download_url',
        'display_name',
        'original_filename',
        'local_path',
        'local_filename',
        'disk',
        'file_size',
        'mime_type',
        'status',
        'error_message',
    ];

    public function kartraImport(): BelongsTo
    {
        return $this->belongsTo(KartraImport::class);
    }

    /** The disk this file's bytes are on; null means the local private disk. */
    public function diskName(): string
    {
        return $this->disk ?: 'local';
    }

    public function isDownloaded(): bool
    {
        return $this->local_path && Storage::disk($this->diskName())->exists($this->local_path);
    }

    public function formattedSize(): string
    {
        $bytes = (int) $this->file_size;
        if ($bytes <= 0) return '—';
        if ($bytes < 1_048_576) return round($bytes / 1024, 1) . ' KB';
        if ($bytes < 1_073_741_824) return round($bytes / 1_048_576, 1) . ' MB';
        return round($bytes / 1_073_741_824, 2) . ' GB';
    }
}
