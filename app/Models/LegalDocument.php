<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Cache;

/**
 * A legal document on the public site: the privacy policy, the terms, the
 * refund policy.
 *
 * The document is only a name and an address. What it says lives in its
 * versions: at most one draft being written, and every version ever
 * published, which are never changed or removed. The newest published version
 * is the one in force; the older ones stay readable, because somebody who
 * agreed to version 2 is entitled to see what version 2 said.
 */
class LegalDocument extends Model
{
    /** The documents the site has. A new one is added here and appears in the panel. */
    public const DOCUMENTS = [
        'privacy' => 'Privacy policy',
        'terms' => 'Terms of service',
        'refunds' => 'Refund and cancellation policy',
    ];

    /** Read by every public page for the footer, so kept as one small cached list. */
    protected const CACHE_KEY = 'legal.published';

    protected $guarded = ['id'];

    public static function syncDocuments(): void
    {
        foreach (self::DOCUMENTS as $key => $title) {
            static::query()->firstOrCreate(['key' => $key], ['title' => $title]);
        }
    }

    public function versions(): HasMany
    {
        return $this->hasMany(LegalDocumentVersion::class)->orderByDesc('version');
    }

    /** The version in force: the newest published one. */
    public function current(): HasOne
    {
        return $this->hasOne(LegalDocumentVersion::class)->ofMany(
            ['version' => 'max'],
            fn (Builder $query) => $query->where('status', LegalDocumentVersion::PUBLISHED),
        );
    }

    /** The version being written, if somebody has started one. */
    public function draft(): HasOne
    {
        return $this->hasOne(LegalDocumentVersion::class)->where('status', LegalDocumentVersion::DRAFT);
    }

    public function path(): string
    {
        return "/legal/{$this->key}";
    }

    /**
     * Documents with a published version, for the footer and the sitemap.
     *
     * @return array<int, array{key: string, title: string, href: string, publishedAt: ?string}>
     */
    public static function published(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, fn () => static::query()
            ->whereHas('versions', fn (Builder $query) => $query->where('status', LegalDocumentVersion::PUBLISHED))
            ->with('current')
            ->orderBy('id')
            ->get()
            ->map(fn (self $document) => [
                'key' => $document->key,
                'title' => $document->title,
                'href' => $document->path(),
                'publishedAt' => $document->current?->published_at?->toAtomString(),
            ])
            ->all());
    }

    public static function forgetPublished(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
