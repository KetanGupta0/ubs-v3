<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Quiz extends Model
{
    protected $guarded = ['id'];

    protected $attributes = [
        'attempts_allowed' => 1,
        'pass_percent' => 50,
        'shuffle_questions' => true,
        'show_answers' => true,
        'is_published' => false,
    ];

    protected function casts(): array
    {
        return [
            'opens_at' => 'datetime',
            'closes_at' => 'datetime',
            'shuffle_questions' => 'boolean',
            'show_answers' => 'boolean',
            'is_published' => 'boolean',
        ];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function module(): BelongsTo
    {
        return $this->belongsTo(CourseModule::class, 'course_module_id');
    }

    public function questions(): HasMany
    {
        return $this->hasMany(Question::class)->orderBy('sort_order');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(QuizAttempt::class);
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true);
    }

    public function totalMarks(): int
    {
        return (int) $this->questions()->sum('marks');
    }

    public function isOpen(): bool
    {
        if (! $this->is_published) {
            return false;
        }

        if ($this->opens_at && $this->opens_at->isFuture()) {
            return false;
        }

        return ! ($this->closes_at && $this->closes_at->isPast());
    }

    /** Why it cannot be sat right now, in words a student can act on. */
    public function closedReason(): ?string
    {
        return match (true) {
            ! $this->is_published => 'Not open yet.',
            (bool) $this->opens_at?->isFuture() => 'Opens '.$this->opens_at->format('j M, g:i a').'.',
            (bool) $this->closes_at?->isPast() => 'Closed on '.$this->closes_at->format('j M Y').'.',
            default => null,
        };
    }

    public function attemptsUsedBy(User $user): int
    {
        return $this->attempts()->where('user_id', $user->id)->count();
    }

    public function canBeAttemptedBy(User $user): bool
    {
        return $this->isOpen() && $this->attemptsUsedBy($user) < $this->attempts_allowed;
    }

    public function bestAttemptFor(User $user): ?QuizAttempt
    {
        return $this->attempts()
            ->where('user_id', $user->id)
            ->whereNotNull('submitted_at')
            ->orderByDesc('percent')
            ->first();
    }
}
