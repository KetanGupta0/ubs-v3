<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Key and value configuration an administrator can change.
 *
 * Cached as one map rather than a row per lookup, because these are read on
 * nearly every request that renders an invoice, a certificate or an email.
 */
class Setting extends Model
{
    protected $guarded = ['id'];

    protected const CACHE_KEY = 'settings.all';

    protected function casts(): array
    {
        return ['value' => 'array'];
    }

    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget(self::CACHE_KEY));
        static::deleted(fn () => Cache::forget(self::CACHE_KEY));
    }

    /** @return array<string, mixed> */
    public static function all_values(): array
    {
        return Cache::rememberForever(
            self::CACHE_KEY,
            fn () => self::query()->pluck('value', 'key')->map(fn ($value) => $value['v'] ?? null)->all(),
        );
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::all_values()[$key] ?? $default;
    }

    public static function put(string $key, mixed $value, string $group = 'general'): void
    {
        self::query()->updateOrCreate(['key' => $key], ['value' => ['v' => $value], 'group' => $group]);
    }
}
