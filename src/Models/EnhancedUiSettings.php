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

    public static function getActiveTheme(): ?string
    {
        if (! config('coolify-enhanced.enabled', false)) {
            return null;
        }

        try {
            $theme = static::get('active_theme', null);
        } catch (\Throwable $e) {
            $theme = null;
        }

        if (! $theme || ! is_string($theme) || trim($theme) === '') {
            $theme = config('coolify-enhanced.ui_theme.default');
        }

        if (! $theme || ! is_string($theme) || trim($theme) === '') {
            return null;
        }

        $themes = config('coolify-enhanced.ui_theme.themes', []);

        if (! array_key_exists($theme, $themes)) {
            return null;
        }

        return $theme;
    }

    public static function setActiveTheme(?string $theme): void
    {
        static::set('active_theme', ($theme && trim($theme) !== '') ? $theme : '');
    }

    public static function isThemeActive(): bool
    {
        return static::getActiveTheme() !== null;
    }

    public static function getAvailableThemes(): array
    {
        return config('coolify-enhanced.ui_theme.themes', []);
    }

    /**
     * Backward-compatible check — returns true when any theme is active.
     */
    public static function isThemeEnabled(): bool
    {
        return static::isThemeActive();
    }

    protected static function cacheKey(string $key): string
    {
        return 'enhanced_ui_settings:'.$key;
    }
}
