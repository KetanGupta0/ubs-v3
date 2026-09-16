<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A software product we can build.
 *
 * With no live client work to show, this catalogue is the portfolio. Each row
 * carries enough to render a real product page: what it does, what is in it,
 * what it runs on, an indicative budget band and an indicative timeline.
 */
class Solution extends Model
{
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
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(SolutionCategory::class, 'solution_category_id');
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
            'priceBand' => $this->priceBand(),
            'timeline' => $this->timeline(),
            'accent' => $this->accent,
            'featured' => $this->is_featured,
        ];
    }
}
