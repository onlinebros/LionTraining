<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A company we are building a B2B relationship with: its people, where the
 * relationship stands, a timeline, its opportunities (Proposal Studio
 * prospects, one offering each) and its private microsite. See
 * memory-bank/b2b-accounts.md.
 *
 * @property int $id
 * @property string $name
 * @property ?string $website
 * @property ?string $industry
 * @property ?string $size
 * @property ?string $headquarters
 * @property string $stage
 * @property ?string $notes
 * @property ?string $next_step
 * @property ?Carbon $next_step_due
 * @property ?int $owner_user_id
 * @property ?string $site_ref
 * @property ?string $site_title
 * @property ?string $site_intro
 * @property bool $site_show_progress
 * @property ?string $logo_path
 * @property ?string $brand_color
 */
class BusinessAccount extends Model
{
    public const STAGES = [
        'target' => 'Target',
        'engaged' => 'Engaged',
        'customer' => 'Customer',
        'dormant' => 'Dormant',
    ];

    public const SIZES = ['1–49', '50–249', '250–999', '1,000–4,999', '5,000+'];

    protected $fillable = [
        'name', 'website', 'industry', 'size', 'headquarters', 'stage', 'notes',
        'next_step', 'next_step_due', 'owner_user_id', 'created_by',
        'site_title', 'site_intro', 'site_show_progress', 'brand_color',
    ];

    protected function casts(): array
    {
        return [
            'next_step_due' => 'date',
            'site_show_progress' => 'boolean',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /** @return HasMany<BusinessContact, $this> */
    public function contacts(): HasMany
    {
        return $this->hasMany(BusinessContact::class)->orderByDesc('is_primary')->orderBy('name');
    }

    /** @return HasMany<BusinessActivity, $this> */
    public function activities(): HasMany
    {
        return $this->hasMany(BusinessActivity::class)->orderByDesc('id');
    }

    /** @return HasMany<Prospect, $this> */
    public function opportunities(): HasMany
    {
        return $this->hasMany(Prospect::class)->orderByDesc('id');
    }

    /** @return HasMany<MicrositeSection, $this> */
    public function sections(): HasMany
    {
        return $this->hasMany(MicrositeSection::class)->orderBy('position')->orderBy('id');
    }

    /** @return HasMany<MicrositeMember, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(MicrositeMember::class)->orderBy('id');
    }

    /** The microsite's address, made the first time it is needed. */
    public function ensureSiteRef(): string
    {
        if (! $this->site_ref) {
            $this->forceFill(['site_ref' => Str::lower(Str::random(16))])->save();
        }

        return (string) $this->site_ref;
    }

    public function siteTitle(): string
    {
        return $this->site_title ?: 'Prepared for '.$this->name;
    }

    /** Ink that reads on the brand colour: dark on light colours, light on dark. */
    public function brandInk(): string
    {
        if (! $this->brand_color) {
            return '#f5f1e8';
        }
        [$r, $g, $b] = array_map(fn ($hex) => hexdec($hex) / 255, str_split(substr($this->brand_color, 1), 2));
        $lin = fn ($c) => $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        $luminance = 0.2126 * $lin($r) + 0.7152 * $lin($g) + 0.0722 * $lin($b);

        return $luminance > 0.4 ? '#17181a' : '#ffffff';
    }

    public function stageLabel(): string
    {
        return self::STAGES[$this->stage] ?? ucfirst($this->stage);
    }

    public function stageBadgeClass(): string
    {
        return match ($this->stage) {
            'target' => 'badge-light-secondary',
            'engaged' => 'badge-light-primary',
            'customer' => 'badge-light-success',
            default => 'badge-light-dark',
        };
    }

    /** @param array<string, mixed> $meta */
    public function log(string $type, string $body, ?User $by = null, array $meta = []): BusinessActivity
    {
        return $this->activities()->create([
            'type' => $type,
            'body' => $body,
            'user_id' => $by?->id,
            'meta' => $meta ?: null,
        ]);
    }
}
