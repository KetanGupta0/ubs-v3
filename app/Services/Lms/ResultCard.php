<?php

namespace App\Services\Lms;

use App\Models\Assignment;
use App\Models\Attendance;
use App\Models\Enrollment;
use App\Models\LiveSession;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\Submission;

/**
 * One student's standing on one course, in one shape.
 *
 * Quizzes, assignments and attendance are weighted into a single figure,
 * because three separate numbers cannot answer "am I passing". The weights are
 * stated here rather than hidden, and shown to the student, so the figure can
 * be argued with rather than just received.
 */
class ResultCard
{
    public const WEIGHTS = [
        'quizzes' => 30,
        'assignments' => 50,
        'attendance' => 20,
    ];

    /** @return array<string, mixed> */
    public function for(Enrollment $enrolment): array
    {
        $quizzes = $this->quizzes($enrolment);
        $assignments = $this->assignments($enrolment);
        $attendance = $this->attendance($enrolment);

        $parts = [
            'quizzes' => $quizzes['percent'],
            'assignments' => $assignments['percent'],
            'attendance' => $attendance['percent'],
        ];

        // A component with nothing in it is dropped and its weight shared out,
        // so a course with no quizzes does not cap everybody at seventy.
        $counted = collect($parts)->filter(fn ($value) => $value !== null);
        $weight = collect(self::WEIGHTS)->only($counted->keys())->sum();

        // sum() hands the callback only the value, so the keys are mapped
        // first: weighting by the wrong component is a silent wrong mark.
        $weighted = $counted->map(fn ($value, $key) => $value * self::WEIGHTS[$key])->sum();

        $overall = $weight === 0 ? null : round($weighted / $weight, 1);

        $course = $enrolment->course;
        $passMark = $course->pass_percent ?? 50;
        $attendanceFloor = $course->minimum_attendance ?? 0;

        $shortOnAttendance = $attendance['percent'] !== null
            && $attendanceFloor > 0
            && $attendance['percent'] < $attendanceFloor;

        return [
            'overall' => $overall,
            'grade' => $this->grade($overall),
            'passMark' => $passMark,
            'passing' => $overall !== null && $overall >= $passMark && ! $shortOnAttendance,
            'shortOnAttendance' => $shortOnAttendance,
            'attendanceFloor' => $attendanceFloor,
            'weights' => self::WEIGHTS,
            'quizzes' => $quizzes,
            'assignments' => $assignments,
            'attendance' => $attendance,
            'progress' => $enrolment->progress_percent,
            'complete' => $enrolment->completed_at !== null,
        ];
    }

    /** @return array<string, mixed> */
    protected function quizzes(Enrollment $enrolment): array
    {
        $quizzes = Quiz::query()
            ->where('course_id', $enrolment->course_id)
            ->published()
            ->get();

        if ($quizzes->isEmpty()) {
            return ['percent' => null, 'rows' => [], 'taken' => 0, 'total' => 0];
        }

        $best = QuizAttempt::query()
            ->where('user_id', $enrolment->user_id)
            ->whereIn('quiz_id', $quizzes->pluck('id'))
            ->whereNotNull('submitted_at')
            ->get()
            ->groupBy('quiz_id')
            ->map(fn ($attempts) => $attempts->sortByDesc('percent')->first());

        $rows = $quizzes->map(function (Quiz $quiz) use ($best) {
            $attempt = $best[$quiz->id] ?? null;

            return [
                'id' => $quiz->id,
                'title' => $quiz->title,
                'percent' => $attempt ? (float) $attempt->percent : null,
                'passed' => (bool) $attempt?->passed,
                'taken' => $attempt !== null,
                'awaitingReview' => (bool) $attempt?->needs_review,
            ];
        });

        $taken = $rows->whereNotNull('percent');

        return [
            // Untaken quizzes count as zero: a course average that improves by
            // skipping the hard quiz is not an average of anything.
            'percent' => round($rows->sum(fn (array $row) => $row['percent'] ?? 0) / $rows->count(), 1),
            'rows' => $rows->values(),
            'taken' => $taken->count(),
            'total' => $rows->count(),
        ];
    }

    /** @return array<string, mixed> */
    protected function assignments(Enrollment $enrolment): array
    {
        $assignments = Assignment::query()
            ->where('course_id', $enrolment->course_id)
            ->where(fn ($query) => $query->whereNull('batch_id')->orWhere('batch_id', $enrolment->batch_id))
            ->published()
            ->get();

        if ($assignments->isEmpty()) {
            return ['percent' => null, 'rows' => [], 'submitted' => 0, 'total' => 0];
        }

        $submissions = Submission::query()
            ->where('user_id', $enrolment->user_id)
            ->whereIn('assignment_id', $assignments->pluck('id'))
            ->get()
            ->keyBy('assignment_id');

        $rows = $assignments->map(function (Assignment $assignment) use ($submissions) {
            $submission = $submissions[$assignment->id] ?? null;

            return [
                'id' => $assignment->id,
                'title' => $assignment->title,
                'maxMarks' => $assignment->max_marks,
                'marks' => $submission?->marks,
                'percent' => $submission?->percent(),
                'submitted' => $submission?->submitted_at !== null,
                'late' => (bool) $submission?->is_late,
                'awaitingMarking' => $submission !== null && $submission->marks === null,
                'dueAt' => $assignment->due_at?->format('j M Y'),
                'overdue' => $assignment->isOverdue() && $submission === null,
            ];
        });

        return [
            'percent' => round($rows->sum(fn (array $row) => $row['percent'] ?? 0) / $rows->count(), 1),
            'rows' => $rows->values(),
            'submitted' => $rows->where('submitted', true)->count(),
            'total' => $rows->count(),
        ];
    }

    /** @return array<string, mixed> */
    protected function attendance(Enrollment $enrolment): array
    {
        if (! $enrolment->batch_id) {
            return ['percent' => null, 'attended' => 0, 'held' => 0, 'missed' => []];
        }

        $held = LiveSession::query()
            ->where('batch_id', $enrolment->batch_id)
            ->where('scheduled_at', '<', now())
            ->whereNot('status', 'cancelled')
            ->get();

        if ($held->isEmpty()) {
            return ['percent' => null, 'attended' => 0, 'held' => 0, 'missed' => []];
        }

        $records = Attendance::query()
            ->where('user_id', $enrolment->user_id)
            ->whereIn('live_session_id', $held->pluck('id'))
            ->get()
            ->keyBy('live_session_id');

        $attended = $held->filter(fn (LiveSession $session) => ($records[$session->id] ?? null)?->counts() ?? false);

        return [
            'percent' => round($attended->count() / $held->count() * 100, 1),
            'attended' => $attended->count(),
            'held' => $held->count(),
            'missed' => $held
                ->reject(fn (LiveSession $session) => ($records[$session->id] ?? null)?->counts() ?? false)
                ->map(fn (LiveSession $session) => [
                    'id' => $session->id,
                    'title' => $session->title,
                    'on' => $session->scheduled_at->format('j M Y'),
                ])
                ->values(),
        ];
    }

    protected function grade(?float $percent): ?string
    {
        return match (true) {
            $percent === null => null,
            $percent >= 90 => 'Outstanding',
            $percent >= 75 => 'Distinction',
            $percent >= 60 => 'Merit',
            $percent >= 50 => 'Pass',
            default => 'Not yet passing',
        };
    }
}
