<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A course or a longer programme.
 *
 * `visibility` decides whether it is advertised publicly or only exists inside
 * the learning management system for a cohort enrolled some other way.
 */
class Course extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'syllabus' => 'array',
            'outcomes' => 'array',
            'prerequisites' => 'array',
            'tools' => 'array',
            'audience' => 'array',
            'seo' => 'array',
            'is_featured' => 'boolean',
            'is_published' => 'boolean',
        ];
    }

    public function batches(): HasMany
    {
        return $this->hasMany(Batch::class);
    }

    /** Only what the public site may render. */
    public function scopePubliclyVisible(Builder $query): Builder
    {
        return $query->where('is_published', true)->where('visibility', 'public');
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function effectivePrice(): int
    {
        return $this->sale_price ?? $this->price;
    }

    public function isDiscounted(): bool
    {
        return $this->sale_price !== null && $this->sale_price < $this->price;
    }

    public function nextBatch(): ?Batch
    {
        return $this->batches()
            ->where('is_published', true)
            ->where('status', 'upcoming')
            ->whereNotNull('starts_on')
            ->orderBy('starts_on')
            ->first();
    }

    public function toCardArray(): array
    {
        $next = $this->relationLoaded('batches') ? $this->nextBatchFromLoaded() : $this->nextBatch();

        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'type' => $this->type,
            'tagline' => $this->tagline,
            'summary' => $this->summary,
            'level' => $this->level,
            'durationWeeks' => $this->duration_weeks,
            'hoursPerWeek' => $this->hours_per_week,
            'price' => $this->effectivePrice(),
            'originalPrice' => $this->isDiscounted() ? $this->price : null,
            'accent' => $this->accent,
            'featured' => $this->is_featured,
            'tools' => array_slice($this->tools ?? [], 0, 4),
            'nextBatch' => $next?->toPublicArray(),
        ];
    }

    /** Avoids a query per card when batches were eager loaded for a listing. */
    protected function nextBatchFromLoaded(): ?Batch
    {
        return $this->batches
            ->filter(fn (Batch $batch) => $batch->is_published && $batch->status === 'upcoming' && $batch->starts_on)
            ->sortBy('starts_on')
            ->first();
    }
}
