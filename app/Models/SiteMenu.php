<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;

/**
 * One of the public site's menus: the header, or a column of the footer.
 *
 * The set of menus is fixed, because each has a place in the layout; what is
 * in them is edited in the panel. Every public page reads them, so they are
 * kept as one small cached array, dropped whenever a menu is saved.
 */
class SiteMenu extends Model
{
    /** Each menu, what it is called in the panel, and how many links fit. */
    public const MENUS = [
        'header' => ['name' => 'Header', 'max' => 7, 'titled' => false],
        'footer_company' => ['name' => 'Footer, first column', 'max' => 12, 'titled' => true],
        'footer_account' => ['name' => 'Footer, second column', 'max' => 12, 'titled' => true],
    ];

    protected const CACHE_KEY = 'site.menus';

    protected $guarded = ['id'];

    public function items(): HasMany
    {
        return $this->hasMany(SiteMenuItem::class)->orderBy('sort_order')->orderBy('id');
    }

    public function maxItems(): int
    {
        return self::MENUS[$this->key]['max'] ?? 12;
    }

    /**
     * What the public layout gets.
     *
     * @return array{header: array, footer: array}
     */
    public static function forLayout(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, function () {
            $menus = static::query()->with('items')->get()->keyBy('key');
            $links = fn (?self $menu) => $menu?->items->map(fn (SiteMenuItem $item) => [
                'label' => $item->label,
                'href' => $item->href,
                'external' => $item->isExternal(),
            ])->values()->all() ?? [];

            return [
                'header' => $links($menus->get('header')),
                'footer' => collect(['footer_company', 'footer_account'])
                    ->map(fn (string $key) => ['title' => $menus->get($key)?->title, 'links' => $links($menus->get($key))])
                    ->values()
                    ->all(),
            ];
        });
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
