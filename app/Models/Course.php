<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
            'documents_provided' => 'array',
            'outcomes' => 'array',
            'prerequisites' => 'array',
            'tools' => 'array',
            'audience' => 'array',
            'seo' => 'array',
            'is_featured' => 'boolean',
            'is_published' => 'boolean',
        ];
    }

    public function modules(): HasMany
    {
        return $this->hasMany(CourseModule::class)->orderBy('sort_order');
    }

    public function lessons(): HasMany
    {
        return $this->hasMany(Lesson::class);
    }

    public function materials(): HasMany
    {
        return $this->hasMany(Material::class);
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    public function quizzes(): HasMany
    {
        return $this->hasMany(Quiz::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(Assignment::class);
    }

    public function certificates(): HasMany
    {
        return $this->hasMany(Certificate::class);
    }

    public function leadTrainer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'lead_trainer_id');
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

    /**
     * Courses and programmes, but not internships.
     *
     * Internships share this table because they are structurally the same, but
     * they are a different product sold to a different person, so the two
     * listings must never bleed into each other.
     */
    public function scopeTaught(Builder $query): Builder
    {
        return $query->whereIn('type', ['course', 'programme']);
    }

    public function scopeInternships(Builder $query): Builder
    {
        return $query->where('type', 'internship');
    }

    public function isInternship(): bool
    {
        return $this->type === 'internship';
    }

    /** "6 weeks" or "6 months", whichever the offering is sold by. */
    public function durationLabel(): ?string
    {
        if ($this->duration_months) {
            return $this->duration_months.' '.str('month')->plural($this->duration_months);
        }

        return $this->duration_weeks
            ? $this->duration_weeks.' '.str('week')->plural($this->duration_weeks)
            : null;
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function effectivePrice(): int
    {
        return $this->sale_price ?? $this->price;
    }

    public function isFree(): bool
    {
        return $this->effectivePrice() === 0;
    }

    /** Total published lessons, for a progress denominator. */
    public function lessonCount(): int
    {
        return $this->lessons()
            ->where('is_published', true)
            ->whereIn('course_module_id', $this->modules()->where('is_published', true)->select('id'))
            ->count();
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
            'durationMonths' => $this->duration_months,
            'durationLabel' => $this->durationLabel(),
            'hoursPerWeek' => $this->hours_per_week,
            'mode' => $this->mode,
            'projectFocus' => $this->project_focus,
            'documentCount' => count($this->documents_provided ?? []),
            'price' => $this->effectivePrice(),
            'priceLabel' => $this->isFree() ? null : Money::display($this->effectivePrice()),
            'originalPrice' => $this->isDiscounted() ? $this->price : null,
            'originalPriceLabel' => $this->isDiscounted() ? Money::display($this->price) : null,
            'savingLabel' => $this->isDiscounted()
                ? Money::display($this->price - $this->effectivePrice())
                : null,
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
