<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One lesson, and the conditions on opening it.
 *
 * The conditions are read by App\Services\Lms\ContentGate, never here: a model
 * that decides access has to know about the student, and then every query that
 * touches a lesson has to know about the student too.
 */
class Lesson extends Model
{
    protected $guarded = ['id'];

    protected $attributes = ['is_published' => true, 'sort_order' => 0];

    protected function casts(): array
    {
        return [
            'unlock_at' => 'datetime',
            'requires_payment' => 'boolean',
            'is_preview' => 'boolean',
            'is_published' => 'boolean',
        ];
    }

    public function module(): BelongsTo
    {
        return $this->belongsTo(CourseModule::class, 'course_module_id');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function materials(): HasMany
    {
        return $this->hasMany(Material::class)->orderBy('sort_order');
    }

    public function completions(): HasMany
    {
        return $this->hasMany(LessonCompletion::class);
    }

    public function prerequisite(): BelongsTo
    {
        return $this->belongsTo(self::class, 'prerequisite_lesson_id');
    }

    public function requiredQuiz(): BelongsTo
    {
        return $this->belongsTo(Quiz::class, 'required_quiz_id');
    }

    public function liveSessions(): HasMany
    {
        return $this->hasMany(LiveSession::class);
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true);
    }

    public function completedBy(User $user): bool
    {
        return $this->completions()->where('user_id', $user->id)->exists();
    }

    public function durationLabel(): ?string
    {
        if (! $this->duration_minutes) {
            return null;
        }

        return $this->duration_minutes >= 60
            ? round($this->duration_minutes / 60, 1).' hr'
            : $this->duration_minutes.' min';
    }
}
