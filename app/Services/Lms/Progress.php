<?php

namespace App\Services\Lms;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\LessonCompletion;
use App\Models\LessonVisit;
use App\Models\LiveSession;
use App\Models\User;

/**
 * How far through a student is.
 *
 * Counted from finished lessons against published ones, recalculated whenever a
 * lesson is finished; for a course with no lessons, from the batch's classes
 * held, recalculated whenever a class changes (`LiveSession`) or a student joins
 * or moves batch (`Enrollment`). Stored on the enrolment so a list of twenty
 * students does not run twenty counting queries to draw twenty progress bars.
 */
class Progress
{
    public function __construct(protected Activity $activity) {}

    public function markComplete(User $student, Lesson $lesson, ?Enrollment $enrolment = null): LessonCompletion
    {
        $completion = LessonCompletion::query()->firstOrCreate(
            ['lesson_id' => $lesson->id, 'user_id' => $student->id],
            [
                'completed_at' => now(),
                // The time on the page up to finishing it, which is what the
                // completion is worth knowing about later.
                'seconds_spent' => min((int) LessonVisit::query()
                    ->where('user_id', $student->id)
                    ->where('lesson_id', $lesson->id)
                    ->value('seconds_spent'), 65535) ?: null,
            ],
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
            return $this->fromClasses($enrolment);
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

    /**
     * Every enrolment on a batch whose course has no lessons, after one of its
     * classes was added, held, cancelled or removed. A course with lessons is
     * left alone: its figure moves when a lesson is finished.
     */
    public function recalculateBatch(int $batchId): void
    {
        Enrollment::query()
            ->where('batch_id', $batchId)
            ->with('course')
            ->get()
            ->groupBy('course_id')
            ->each(function ($enrolments) {
                if ($enrolments->first()->course?->lessonCount() !== 0) {
                    return;
                }

                $enrolments->each(fn (Enrollment $enrolment) => $this->fromClasses($enrolment));
            });
    }

    /** One enrolment that joined or moved batch, if its course has no lessons. */
    public function recalculateTaughtLive(Enrollment $enrolment): void
    {
        if ($enrolment->batch_id && Course::query()->find($enrolment->course_id)?->lessonCount() === 0) {
            $this->fromClasses($enrolment);
        }
    }

    /**
     * A course taught only in live classes (every course brought from v2 is one)
     * has no lessons to count, so how far through it somebody is reads as the
     * batch's classes held out of those still scheduled. A finished enrolment
     * never goes back down: a class added after it finished does not unfinish it.
     */
    protected function fromClasses(Enrollment $enrolment): int
    {
        // Somebody who left, or paused, did not sit the classes held since.
        if (! $enrolment->batch_id || in_array($enrolment->status, ['dropped', 'on_hold'], true)) {
            return $enrolment->progress_percent;
        }

        $classes = LiveSession::query()->where('batch_id', $enrolment->batch_id)->whereNot('status', 'cancelled');
        $scheduled = (clone $classes)->count();

        if ($scheduled === 0) {
            return $enrolment->progress_percent;
        }

        $percent = (int) round((clone $classes)->where('status', 'held')->count() / $scheduled * 100);

        if ($enrolment->status === 'completed') {
            $percent = max($percent, (int) $enrolment->progress_percent);
        }

        if ($percent !== (int) $enrolment->progress_percent) {
            $enrolment->forceFill(['progress_percent' => $percent])->save();
        }

        return $percent;
    }
}
