<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A question a student asked on a lesson, and the thread under it.
 *
 * Readable by everybody on the asker's batch (or, for a course without
 * batches, everybody on the course without one), because the next student
 * stuck on the same line should find the answer already there.
 */
class LessonDoubt extends Model
{
    public const MAX_LENGTH = 3000;

    public const STATUSES = [
        'open' => 'Waiting for an answer',
        'answered' => 'Answered',
        'hidden' => 'Taken down',
    ];

    protected $fillable = [
        'lesson_id', 'course_id', 'batch_id', 'user_id', 'body', 'status', 'answered_at', 'last_activity_at',
    ];

    protected function casts(): array
    {
        return [
            'answered_at' => 'datetime',
            'last_activity_at' => 'datetime',
        ];
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    public function asker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(LessonDoubtReply::class)->orderBy('id');
    }

    /** What a student on this enrolment may read: their own cohort's, not taken down. */
    public function scopeVisibleOn(Builder $query, Enrollment $enrolment, Lesson $lesson): Builder
    {
        return $query
            ->where('lesson_id', $lesson->id)
            ->where('status', '!=', 'hidden')
            ->when(
                $enrolment->batch_id,
                fn (Builder $query) => $query->where('batch_id', $enrolment->batch_id),
                fn (Builder $query) => $query->whereNull('batch_id'),
            );
    }

    /** A first name and an initial: enough to tell batchmates apart, no more. */
    public static function shortName(?User $user): string
    {
        if (! $user) {
            return 'A student';
        }

        $parts = preg_split('/\s+/', trim($user->name)) ?: [];
        $first = $parts[0] ?? 'A student';
        $last = count($parts) > 1 ? mb_substr(end($parts), 0, 1).'.' : '';

        return trim("{$first} {$last}");
    }
}
