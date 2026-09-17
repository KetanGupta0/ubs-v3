<?php

namespace App\Services\Lms;

use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\LessonCompletion;
use App\Models\User;

/**
 * How far through a student is.
 *
 * Counted from finished lessons against published ones, recalculated whenever a
 * lesson is finished. Stored on the enrolment so a list of twenty students does
 * not run twenty counting queries to draw twenty progress bars.
 */
class Progress
{
    public function __construct(protected Activity $activity) {}

    public function markComplete(User $student, Lesson $lesson, ?Enrollment $enrolment = null): LessonCompletion
    {
        $completion = LessonCompletion::query()->firstOrCreate(
            ['lesson_id' => $lesson->id, 'user_id' => $student->id],
            ['completed_at' => now()],
        );

        if ($completion->wasRecentlyCreated) {
            $this->activity->did($student, 'lesson.completed', $lesson, [
                'title' => $lesson->title,
            ], $lesson->course_id, $enrolment?->batch_id);
        }

        $enrolment ??= Enrollment::query()
            ->where('user_id', $student->id)
            ->where('course_id', $lesson->course_id)
            ->first();

        if ($enrolment) {
            $this->recalculate($enrolment);
        }

        return $completion;
    }

    public function markIncomplete(User $student, Lesson $lesson): void
    {
        LessonCompletion::query()
            ->where('lesson_id', $lesson->id)
            ->where('user_id', $student->id)
            ->delete();

        $enrolment = Enrollment::query()
            ->where('user_id', $student->id)
            ->where('course_id', $lesson->course_id)
            ->first();

        if ($enrolment) {
            $this->recalculate($enrolment);
        }
    }

    public function recalculate(Enrollment $enrolment): int
    {
        $total = $enrolment->course->lessonCount();

        if ($total === 0) {
            return $enrolment->progress_percent;
        }

        $done = LessonCompletion::query()
            ->where('user_id', $enrolment->user_id)
            ->whereIn('lesson_id', $enrolment->course->lessons()->where('is_published', true)->select('id'))
            ->count();

        $percent = (int) round($done / $total * 100);

        $changes = ['progress_percent' => $percent];

        // Finishing every lesson is not the same as passing, so this marks the
        // content done and leaves the result card to decide the outcome.
        if ($percent >= 100 && $enrolment->completed_at === null) {
            $changes['completed_at'] = now();
            $changes['status'] = 'completed';
        }

        $enrolment->forceFill($changes)->save();

        if (($changes['completed_at'] ?? null) !== null) {
            $this->activity->did(
                $enrolment->user,
                'course.completed',
                $enrolment->course,
                ['title' => $enrolment->course->title],
                $enrolment->course_id,
                $enrolment->batch_id,
            );
        }

        return $percent;
    }
}
