<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SiteSetting extends Model
{
    protected $fillable = ['key', 'value'];

    // ── Defaults applied when a key has no DB value ───────────────────────────
    const DEFAULTS = [
        'color_scheme'  => 'color-1',
        'default_mode'  => 'light',
        'logo_light'    => null,
        'logo_dark'     => null,
        'logo_icon'     => null,
        'site_name'     => 'Quantum Life',
    ];

    public static function get(string $key, mixed $default = null): mixed
    {
        $fallback = $default ?? (static::DEFAULTS[$key] ?? null);

        return \Cache::remember("site_setting_{$key}", 3600, function () use ($key, $fallback) {
            return static::where('key', $key)->value('value') ?? $fallback;
        });
    }

    public static function set(string $key, mixed $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
        \Cache::forget("site_setting_{$key}");
        \Cache::forget('site_settings_all');
    }

    // Returns all settings merged with defaults — used in view composer
    public static function getAll(): array
    {
        return \Cache::remember('site_settings_all', 3600, function () {
            $stored = static::query()->pluck('value', 'key')->toArray();
            return array_merge(static::DEFAULTS, $stored);
        });
    }
}
