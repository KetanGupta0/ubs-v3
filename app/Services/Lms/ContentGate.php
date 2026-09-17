<?php

namespace App\Services\Lms;

use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\LessonCompletion;
use App\Models\QuizAttempt;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * May this student open this lesson right now?
 *
 * Four independent rules, all of which must pass:
 *
 *   drip         opens a fixed number of days after the student enrolled, or
 *                after the batch started, so a cohort moves together
 *   prerequisite the lesson before it has to be finished
 *   payment      the fee has to be settled
 *   quiz score   a pass mark on a named quiz
 *
 * A locked lesson is still listed, with the reason. Hiding it entirely removes
 * the only thing that makes somebody finish the lesson they are on, and hiding
 * the reason turns a schedule into a mystery.
 *
 * One instance answers many lessons for one student, so the enrolment and the
 * set of finished lessons are looked up once rather than per lesson.
 */
class ContentGate
{
    /** @var array<int, bool> */
    protected array $completed = [];

    /** @var array<int, float> */
    protected array $bestScores = [];

    protected bool $loaded = false;

    public function __construct(
        protected User $student,
        protected Enrollment $enrollment,
    ) {}

    public static function for(User $student, Enrollment $enrollment): self
    {
        return new self($student, $enrollment);
    }

    public function check(Lesson $lesson): LockState
    {
        $this->load();

        // A preview lesson is the sample; it opens for anybody on the course.
        if ($lesson->is_preview) {
            return LockState::open();
        }

        if ($lesson->requires_payment && ! $this->enrollment->has_paid) {
            return LockState::locked('payment', 'Opens once the fee is paid.');
        }

        if ($dripped = $this->dripCheck($lesson)) {
            return $dripped;
        }

        if ($lesson->prerequisite_lesson_id && ! ($this->completed[$lesson->prerequisite_lesson_id] ?? false)) {
            return LockState::locked(
                'prerequisite',
                'Finish “'.($lesson->prerequisite?->title ?? 'the previous lesson').'” first.',
            );
        }

        if ($lesson->required_quiz_id && $lesson->min_quiz_score) {
            $best = $this->bestScores[$lesson->required_quiz_id] ?? null;

            if ($best === null) {
                return LockState::locked(
                    'quiz',
                    'Sit “'.($lesson->requiredQuiz?->title ?? 'the quiz').'” first.',
                );
            }

            if ($best < $lesson->min_quiz_score) {
                return LockState::locked(
                    'quiz',
                    'Needs '.$lesson->min_quiz_score.'% on “'.($lesson->requiredQuiz?->title ?? 'the quiz')
                        .'”. Your best so far is '.round($best).'%.',
                    ['best' => round($best)],
                );
            }
        }

        return LockState::open();
    }

    public function isOpen(Lesson $lesson): bool
    {
        return $this->check($lesson)->open;
    }

    public function hasCompleted(Lesson $lesson): bool
    {
        $this->load();

        return $this->completed[$lesson->id] ?? false;
    }

    /**
     * The drip rules, lesson first and then its module.
     *
     * An explicit date beats a relative one: a fixed `unlock_at` is a decision
     * somebody made about the calendar, and it should not move because a
     * student enrolled late.
     */
    protected function dripCheck(Lesson $lesson): ?LockState
    {
        if ($lesson->unlock_at && $lesson->unlock_at->isFuture()) {
            return LockState::locked(
                'schedule',
                'Opens on '.$lesson->unlock_at->format('j M Y').'.',
                ['opensAt' => $lesson->unlock_at->toIso8601String()],
            );
        }

        foreach ([$lesson->unlock_after_days, $lesson->module?->unlock_after_days] as $days) {
            if (! $days) {
                continue;
            }

            $opensOn = $this->startedOn()->copy()->addDays($days);

            if ($opensOn->isFuture()) {
                return LockState::locked(
                    'schedule',
                    'Opens on '.$opensOn->format('j M Y').'.',
                    ['opensAt' => $opensOn->toIso8601String()],
                );
            }
        }

        return null;
    }

    /**
     * Day zero for the drip.
     *
     * The batch start date where there is one, so a cohort moves together and
     * somebody who enrolled a week late is not a week behind everybody else.
     */
    protected function startedOn(): Carbon
    {
        $batchStart = $this->enrollment->batch?->starts_on;

        return $batchStart?->copy()->startOfDay()
            ?? $this->enrollment->enrolled_at->copy()->startOfDay();
    }

    protected function load(): void
    {
        if ($this->loaded) {
            return;
        }

        $this->completed = LessonCompletion::query()
            ->where('user_id', $this->student->id)
            ->pluck('lesson_id')
            ->flip()
            ->map(fn () => true)
            ->all();

        $this->bestScores = QuizAttempt::query()
            ->where('user_id', $this->student->id)
            ->whereNotNull('submitted_at')
            ->selectRaw('quiz_id, max(percent) as best')
            ->groupBy('quiz_id')
            ->pluck('best', 'quiz_id')
            ->map(fn ($value) => (float) $value)
            ->all();

        $this->loaded = true;
    }
}
