<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Search wording for one of the site's fixed pages, set in the admin panel.
 *
 * Read on every public page, so it is cached as one small map and the cache
 * is dropped whenever a row is saved.
 */
class SeoOverride extends Model
{
    /** The fixed public pages whose search wording can be set here. */
    public const PAGES = [
        '/' => 'Home',
        '/solutions' => 'Solutions',
        '/services' => 'Services',
        '/training' => 'Training',
        '/internships' => 'Internships',
        '/for-colleges' => 'For colleges',
        '/about' => 'About',
        '/process' => 'How we work',
        '/technology' => 'Technology',
        '/faq' => 'FAQ',
        '/contact' => 'Contact',
        '/legal/privacy' => 'Privacy policy',
        '/legal/terms' => 'Terms of service',
    ];

    protected const CACHE_KEY = 'seo.overrides';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['noindex' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget(self::CACHE_KEY));
        static::deleted(fn () => Cache::forget(self::CACHE_KEY));
    }

    /** One row per fixed page, so the admin list always shows all of them. */
    public static function syncPages(): void
    {
        $existing = static::query()->pluck('path')->all();

        foreach (self::PAGES as $path => $label) {
            if (! in_array($path, $existing, true)) {
                static::query()->create(['path' => $path, 'label' => $label]);
            }
        }
    }

    /** The settings for a path, or null when nothing has been set for it. */
    public static function forPath(string $path): ?array
    {
        $map = Cache::rememberForever(self::CACHE_KEY, fn () => static::query()
            ->where(fn ($query) => $query->whereNotNull('title')->orWhereNotNull('description')
                ->orWhereNotNull('image_path')->orWhere('noindex', true))
            ->get(['path', 'title', 'description', 'image_path', 'noindex'])
            ->keyBy('path')
            ->map->only(['title', 'description', 'image_path', 'noindex'])
            ->all());

        return $map[$path] ?? null;
    }
}
