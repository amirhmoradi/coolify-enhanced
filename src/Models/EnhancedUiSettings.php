<?php

namespace AmirhMoradi\CoolifyEnhanced\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class EnhancedUiSettings extends Model
{
    private const CACHE_TTL_SECONDS = 60;

    private const BOOLEAN_TRUE_VALUES = ['1', 'true', 'yes', 'on'];

    private const BOOLEAN_FALSE_VALUES = ['0', 'false', 'no', 'off'];

    protected $table = 'enhanced_ui_settings';

    protected $fillable = ['key', 'value'];

    /**
     * Get a setting value by key.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $cacheKey = self::cacheKey($key);

        return Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, function () use ($key, $default) {
            $row = static::query()->where('key', $key)->first();

            if (! $row) {
                return $default;
            }

            $value = $row->value;
            if ($value === null) {
                return $default;
            }

            $normalized = strtolower(trim((string) $value));
            if (in_array($normalized, self::BOOLEAN_TRUE_VALUES, true)) {
                return true;
            }
            if (in_array($normalized, self::BOOLEAN_FALSE_VALUES, true)) {
                return false;
            }

            return $value;
        });
    }

    /**
     * Set a setting value by key.
     */
    public static function set(string $key, mixed $value): void
    {
        $stringValue = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
        static::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $stringValue]
        );
        Cache::forget(self::cacheKey($key));
    }

    /**
     * Check whether the enhanced UI theme is active.
     *
     * Returns true only when the package is enabled AND the DB/config
     * flag is on.  Safe to call from Blade views — catches DB errors
     * (e.g. table not yet migrated) and falls back to config value.
     */
    public static function isThemeEnabled(): bool
    {
        if (! config('coolify-enhanced.enabled', false)) {
            return false;
        }

        $default = (bool) config('coolify-enhanced.ui_theme.enabled', false);

        try {
            return (bool) static::get('enhanced_theme_enabled', $default);
        } catch (\Throwable $e) {
            return $default;
        }
    }

    protected static function cacheKey(string $key): string
    {
        return 'enhanced_ui_settings:'.$key;
    }
}
