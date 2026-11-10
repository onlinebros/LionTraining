<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A short public address (q3.life/live-zoom) that redirects to wherever staff
 * point it. See the create_live_links migration.
 */
class LiveLink extends Model
{
    /** Slugs: the prefix, then lowercase letters, digits and single hyphens. */
    public const SLUG_PATTERN = 'live-[a-z0-9]+(?:-[a-z0-9]+)*';

    protected $fillable = ['slug', 'label', 'destination_url', 'is_active', 'updated_by'];

    protected $casts = [
        'is_active'       => 'boolean',
        'clicks'          => 'integer',
        'last_clicked_at' => 'datetime',
    ];

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /** Whether a visitor is sent on, rather than shown the holding page. */
    public function isLive(): bool
    {
        return $this->is_active && filled($this->destination_url);
    }

    public function publicUrl(): string
    {
        return config('live_links.public_base_url') . '/' . $this->slug;
    }
}
