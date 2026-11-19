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
        'shuffle_options' => false,
        'one_at_a_time' => false,
        'allow_back' => true,
        'show_answers' => true,
        'answers_after_close' => false,
        'is_published' => false,
    ];

    protected function casts(): array
    {
        return [
            'opens_at' => 'datetime',
            'closes_at' => 'datetime',
            'integrity_checked_at' => 'datetime',
            'shuffle_questions' => 'boolean',
            'shuffle_options' => 'boolean',
            'one_at_a_time' => 'boolean',
            'allow_back' => 'boolean',
            'show_answers' => 'boolean',
            'answers_after_close' => 'boolean',
            'is_published' => 'boolean',
            'pool_size' => 'integer',
        ];
    }

    /**
     * Whether a finished attempt may show its answers and the key: the quiz
     * shows them at all, and, if it waits for the quiz to close for everybody
     * (§9.1), it has closed, so the first to finish cannot pass them on.
     */
    public function answersVisible(): bool
    {
        return $this->show_answers
            && (! $this->answers_after_close || ($this->closes_at !== null && $this->closes_at->isPast()));
    }

    /**
     * What a student is told before starting (§9.1): how the quiz is set, and
     * what is recorded during the attempt. A rule nobody was told about is a
     * trap, not a deterrent.
     *
     * @return array<int, string>
     */
    public function rulesForStudents(): array
    {
        $all = $this->questions()->count();
        $drawn = $this->pool_size && $this->pool_size < $all;

        return array_values(array_filter([
            $drawn ? "You get {$this->pool_size} questions drawn from a larger set, so your paper is not the same as anybody else's." : null,
            $this->one_at_a_time
                ? ($this->allow_back ? 'Questions come one at a time; you can go back to change an answer.' : 'Questions come one at a time, and once you move on you cannot go back.')
                : null,
            $this->time_limit_minutes ? "You have {$this->time_limit_minutes} minutes from the moment you start. The clock is ours and does not stop if you leave the page." : null,
            'This attempt belongs to the browser you start it in. Continuing on another device is possible, and is noted.',
            'We note when the quiz loses focus or you leave it, when you paste or copy, a change of network or device, and how long you spend on each question.',
            'A person looks at anything unusual. Nothing noted here fails you by itself, and you will hear what was decided and can reply.',
            $this->answers_after_close && $this->show_answers ? 'The answers are shown once the quiz has closed for everybody.' : null,
        ]));
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

    /** The marks a full paper is out of: every question, or what a pool draws. */
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

    /**
     * The quizzes one enrolment can see: the course's own, plus any set for
     * that enrolment's batch. A quiz set for another batch stays out of sight.
     */
    public function scopeForEnrolment(Builder $query, Enrollment $enrolment): Builder
    {
        return $query->where($this->qualifyColumn('course_id'), $enrolment->course_id)
            ->where(fn (Builder $inner) => $inner
                ->whereNull($this->qualifyColumn('batch_id'))
                ->orWhere($this->qualifyColumn('batch_id'), $enrolment->batch_id));
    }

    /**
     * Across every course a student is on, the same rule: course wide, or set
     * for the batch they are on for that course.
     */
    public function scopeVisibleTo(Builder $query, User $student): Builder
    {
        $table = $this->getTable();

        return $query->whereExists(fn ($enrolments) => $enrolments
            ->selectRaw('1')
            ->from('enrollments')
            ->where('enrollments.user_id', $student->id)
            ->whereColumn('enrollments.course_id', "{$table}.course_id")
            ->where(fn ($batch) => $batch
                ->whereNull("{$table}.batch_id")
                ->orWhereColumn('enrollments.batch_id', "{$table}.batch_id")));
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }
}
