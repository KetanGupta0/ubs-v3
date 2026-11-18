<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Collection;

/**
 * A software product we can build.
 *
 * With no live client work to show, this catalogue is the portfolio. Each row
 * carries enough to render a real product page: what it does, what is in it,
 * what it runs on, an indicative budget band and an indicative timeline.
 */
class Solution extends Model
{
    /** What a solution can be bought as (§4). */
    public const OFFERINGS = [
        'custom_build' => 'Built to order',
        'source_code' => 'Source code, at a fixed price',
        'subscription' => 'Hosted, as a plan',
        'demo' => 'Live demo',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'industries' => 'array',
            'platforms' => 'array',
            'tech_stack' => 'array',
            'features' => 'array',
            'modules' => 'array',
            'outcomes' => 'array',
            'integrations' => 'array',
            'seo' => 'array',
            'is_featured' => 'boolean',
            'is_published' => 'boolean',
            'needs_api_keys' => 'boolean',
            'offerings' => 'array',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(SolutionCategory::class, 'solution_category_id');
    }

    public function tiers(): HasMany
    {
        return $this->hasMany(ProductTier::class)->orderBy('sort_order')->orderBy('price');
    }

    public function addons(): HasMany
    {
        return $this->hasMany(ProductAddon::class)->orderBy('sort_order')->orderBy('id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(ProductVersion::class)->newestFirst();
    }

    public function latestVersion(): HasOne
    {
        return $this->hasOne(ProductVersion::class)->ofMany(['released_on' => 'max', 'id' => 'max'], fn (Builder $q) => $q->where('is_available', true));
    }

    /** @return array<int, string> */
    public function offeringKeys(): array
    {
        return $this->offerings ?? ['custom_build'];
    }

    /**
     * Whether the site actually offers this.
     *
     * Switched on is not enough: the source code needs a live licence tier,
     * a plan needs a live plan, a demo needs its address. A switch with
     * nothing behind it offers nothing rather than an empty box.
     */
    public function offers(string $offering): bool
    {
        if (! in_array($offering, $this->offeringKeys(), true)) {
            return false;
        }

        return match ($offering) {
            'source_code' => $this->activeTiers('licence')->isNotEmpty(),
            'subscription' => $this->activeTiers('plan')->isNotEmpty(),
            'demo' => filled($this->demo_url),
            default => true,
        };
    }

    /** Anything with a fixed price, so a cart can take it. */
    public function isSold(): bool
    {
        return $this->offers('source_code') || $this->offers('subscription');
    }

    /** @return Collection<int, ProductTier> */
    public function activeTiers(string $kind)
    {
        return $this->tiers->where('kind', $kind)->where('is_active', true)->values();
    }

    /** The lowest fixed price, before tax, or null. */
    public function fromPrice(): ?int
    {
        if (! $this->isSold()) {
            return null;
        }

        $kinds = array_filter(['licence' => $this->offers('source_code'), 'plan' => $this->offers('subscription')]);

        return $this->tiers->where('is_active', true)->whereIn('kind', array_keys($kinds))->min('price');
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true);
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * The budget band as text.
     *
     * Deliberately a band rather than a number. Any real figure depends on
     * scope, and quoting one on a catalogue page would be a guess presented as
     * a price.
     */
    public function priceBand(): ?string
    {
        if (! $this->price_band_min) {
            return null;
        }

        $format = fn (int $amount) => $amount >= 100000
            ? '₹'.rtrim(rtrim(number_format($amount / 100000, 1), '0'), '.').'L'
            : '₹'.number_format($amount / 1000).'K';

        return $this->price_band_max && $this->price_band_max !== $this->price_band_min
            ? $format($this->price_band_min).' – '.$format($this->price_band_max)
            : 'from '.$format($this->price_band_min);
    }

    public function timeline(): ?string
    {
        if (! $this->timeline_weeks_min) {
            return null;
        }

        return $this->timeline_weeks_max && $this->timeline_weeks_max !== $this->timeline_weeks_min
            ? "{$this->timeline_weeks_min}–{$this->timeline_weeks_max} weeks"
            : "{$this->timeline_weeks_min} weeks";
    }

    /** Shape used by the catalogue grid. */
    public function toCardArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'tagline' => $this->tagline,
            'summary' => $this->summary,
            'category' => $this->category?->name,
            'categorySlug' => $this->category?->slug,
            'industries' => $this->industries ?? [],
            'platforms' => $this->platforms ?? [],
            'techStack' => array_slice($this->tech_stack ?? [], 0, 4),
            'moduleCount' => count($this->modules ?? []),
            // A band only for what is built to order; a figure only for what
            // is sold at that figure (§4), and it is before tax.
            'priceBand' => $this->offers('custom_build') ? $this->priceBand() : null,
            'fromPrice' => ($from = $this->fromPrice()) !== null ? Money::price($from) : null,
            'hasDemo' => $this->offers('demo'),
            'timeline' => $this->offers('custom_build') ? $this->timeline() : null,
            'accent' => $this->accent,
            'featured' => $this->is_featured,
        ];
    }
}
