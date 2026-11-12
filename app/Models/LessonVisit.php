<?php

namespace App\Models;

use App\Services\Lms\Activity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A student's time with one lesson: when they last had it open, how often, and
 * roughly how long in all.
 *
 * Time arrives from the page in beats while it is visible, each capped, and the
 * total is capped too, so a tab left open overnight reads as a long session
 * rather than as twelve hours of study. It is shown in coarse buckets for the
 * same reason: the figure is an indication, never a measurement to grade by.
 */
class LessonVisit extends Model
{
    /** The most one beat from the page can add. The page sends one every two minutes. */
    public const MAX_BEAT_SECONDS = 150;

    /** The most a lesson's total can reach. */
    public const MAX_TOTAL_SECONDS = 8 * 3600;

    /** Upper bounds, in minutes, and what each bucket is called. */
    public const BUCKETS = [
        5 => 'Under 5 minutes',
        15 => '5 to 15 minutes',
        30 => '15 to 30 minutes',
        60 => '30 minutes to an hour',
        PHP_INT_MAX => 'Over an hour',
    ];

    protected $fillable = [
        'user_id', 'lesson_id', 'course_id', 'seconds_spent', 'visits', 'first_opened_at', 'last_opened_at',
    ];

    protected function casts(): array
    {
        return [
            'first_opened_at' => 'datetime',
            'last_opened_at' => 'datetime',
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

    /**
     * Note that the lesson was opened. The first opening on a day goes in the
     * activity log; opening it again that day does not, or the log would be
     * nothing but page loads.
     */
    public static function opened(User $student, Lesson $lesson, ?int $batchId = null): ?self
    {
        if (Activity::muted()) {
            return null;
        }

        $visit = self::query()->firstOrNew(
            ['user_id' => $student->id, 'lesson_id' => $lesson->id],
            ['course_id' => $lesson->course_id, 'first_opened_at' => now()],
        );

        $firstToday = ! $visit->exists || ! $visit->last_opened_at->isToday();

        $visit->forceFill([
            'visits' => $visit->visits + 1,
            'last_opened_at' => now(),
        ])->save();

        if ($firstToday) {
            app(Activity::class)->record($student, 'lesson.opened', $lesson, [
                'title' => $lesson->title,
            ], $lesson->course_id, $batchId);
        }

        return $visit;
    }

    /** Add one beat of time from the page. */
    public function addTime(int $seconds): void
    {
        $seconds = max(0, min($seconds, self::MAX_BEAT_SECONDS));

        $this->forceFill([
            'seconds_spent' => min($this->seconds_spent + $seconds, self::MAX_TOTAL_SECONDS),
        ])->save();
    }

    public static function bucketFor(int $seconds): string
    {
        $minutes = $seconds / 60;

        foreach (self::BUCKETS as $limit => $label) {
            if ($minutes < $limit) {
                return $label;
            }
        }

        return end(self::BUCKETS);
    }

    public function bucket(): string
    {
        return self::bucketFor($this->seconds_spent);
    }
}
