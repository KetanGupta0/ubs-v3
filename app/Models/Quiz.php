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

    public function grants(): HasMany
    {
        return $this->hasMany(QuizAttemptGrant::class);
    }

    /**
     * How many attempts this student may make: the quiz's own number plus any
     * extra attempts granted to them.
     */
    public function attemptsAllowedFor(User $user): int
    {
        return $this->attempts_allowed
            + (int) $this->grants()->where('user_id', $user->id)->sum('extra_attempts');
    }

    public function attemptsLeftFor(User $user): int
    {
        return max(0, $this->attemptsAllowedFor($user) - $this->attemptsUsedBy($user));
    }

    public function canBeAttemptedBy(User $user): bool
    {
        return $this->isOpen() && $this->attemptsLeftFor($user) > 0;
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
