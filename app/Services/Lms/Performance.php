<?php

namespace App\Services\Lms;

use App\Models\ActivityLog;
use App\Models\Assignment;
use App\Models\Attendance;
use App\Models\Batch;
use App\Models\Enrollment;
use App\Models\LeaderboardPoint;
use App\Models\LessonCompletion;
use App\Models\LiveSession;
use App\Models\QuizAttempt;
use App\Models\Submission;
use Illuminate\Database\Eloquent\Builder;

/**
 * How a student is doing on a course, from what was recorded, never typed in.
 *
 * Attendance against classes held, quiz results over time, work handed in on
 * time, lessons finished and their place on the batch board. The same figures
 * feed the student's own page, their record for staff, and the batch view a
 * trainer reads to see who is slipping before a warning is needed.
 *
 * The signals are prompts for a person, not verdicts: nothing here issues a
 * warning or marks anybody down.
 */
class Performance
{
    /** Below this share of classes attended, attendance is a signal. */
    public const ATTENDANCE_FLOOR = 75;

    /** Below this quiz average, results are a signal. */
    public const QUIZ_FLOOR = 50;

    /** This many days without any recorded activity is a signal. */
    public const QUIET_DAYS = 7;

    /** This much work past its date and not handed in is a signal. */
    public const MISSING_LIMIT = 2;

    public const SIGNALS = [
        'attendance' => 'Attendance below '.self::ATTENDANCE_FLOOR.'%',
        'missing' => self::MISSING_LIMIT.'+ pieces of work not handed in',
        'quiz' => 'Quiz average below '.self::QUIZ_FLOOR.'%',
        'quiet' => 'Nothing recorded for '.self::QUIET_DAYS.' days',
    ];

    public function __construct(protected Leaderboard $leaderboard) {}

    /** @return array<string, mixed> */
    public function forEnrolment(Enrollment $enrolment): array
    {
        $enrolment->loadMissing('course', 'batch', 'user');
        $student = $enrolment->user;
        $course = $enrolment->course;
        $batch = $enrolment->batch;

        /* ---------------------------------------------------- attendance */
        $held = $batch ? LiveSession::query()->where('batch_id', $batch->id)->where('status', 'held')->pluck('id') : collect();
        $attended = $held->isEmpty() ? 0 : Attendance::query()
            ->where('user_id', $student->id)
            ->whereIn('live_session_id', $held)
            ->whereIn('status', ['present', 'late', 'excused'])
            ->count();

        /* ------------------------------------------------------- quizzes */
        $attempts = QuizAttempt::query()
            ->where('user_id', $student->id)
            ->whereNotNull('submitted_at')
            ->whereHas('quiz', fn (Builder $query) => $query->where('course_id', $course->id)
                ->where(fn (Builder $inner) => $inner->whereNull('batch_id')->orWhere('batch_id', $enrolment->batch_id)))
            ->with('quiz:id,title')
            ->orderBy('submitted_at')
            ->get();

        $percents = $attempts->map(fn (QuizAttempt $attempt) => (float) $attempt->percent)->values();

        /* --------------------------------------------------- assignments */
        $assignments = Assignment::query()
            ->published()
            ->where('course_id', $course->id)
            ->where(fn (Builder $query) => $query->whereNull('batch_id')->orWhere('batch_id', $enrolment->batch_id))
            ->whereNotNull('due_at')
            ->get(['id', 'due_at']);

        $submitted = Submission::query()
            ->where('user_id', $student->id)
            ->whereIn('assignment_id', $assignments->pluck('id'))
            ->whereNotNull('submitted_at')
            ->pluck('submitted_at', 'assignment_id');

        $onTime = 0;
        $late = 0;
        $missing = 0;
        $due = 0;

        foreach ($assignments as $assignment) {
            $at = $submitted[$assignment->id] ?? null;

            if ($at === null) {
                if ($assignment->due_at->isPast()) {
                    $due++;
                    $missing++;
                }

                continue;
            }

            $due++;
            $at <= $assignment->due_at ? $onTime++ : $late++;
        }

        /* ------------------------------------------------------- lessons */
        $total = $course->lessonCount();
        $done = LessonCompletion::query()
            ->where('user_id', $student->id)
            ->whereIn('lesson_id', $course->lessons()->where('is_published', true)->select('id'))
            ->count();

        $standing = $batch ? $this->leaderboard->standingFor($student, $batch) : null;
        $lastActive = ActivityLog::query()->where('user_id', $student->id)->max('occurred_at');

        $attendancePercent = $held->isEmpty() ? null : (int) round($attended / $held->count() * 100);
        $quizAverage = $percents->isEmpty() ? null : (int) round($percents->avg());

        $signals = collect([
            'attendance' => $held->count() >= 2 && $attendancePercent < self::ATTENDANCE_FLOOR,
            'missing' => $missing >= self::MISSING_LIMIT,
            'quiz' => $quizAverage !== null && $quizAverage < self::QUIZ_FLOOR,
            'quiet' => $lastActive === null || now()->parse($lastActive)->lt(now()->subDays(self::QUIET_DAYS)),
        ])->filter()->keys()->map(fn (string $key) => ['key' => $key, 'label' => self::SIGNALS[$key]])->values();

        return [
            'courseId' => $course->id,
            'course' => $course->title,
            'batch' => $batch?->name,
            'attendance' => ['held' => $held->count(), 'attended' => $attended, 'percent' => $attendancePercent],
            'quizzes' => [
                'attempts' => $attempts->map(fn (QuizAttempt $attempt) => [
                    'quiz' => $attempt->quiz?->title,
                    'date' => $attempt->submitted_at->format('j M'),
                    'percent' => (int) round((float) $attempt->percent),
                ])->values(),
                'average' => $quizAverage,
                'trend' => $this->trend($percents->all()),
            ],
            'assignments' => [
                'due' => $due,
                'onTime' => $onTime,
                'late' => $late,
                'missing' => $missing,
                'percent' => $due === 0 ? null : (int) round($onTime / $due * 100),
            ],
            'lessons' => ['done' => $done, 'total' => $total, 'percent' => $total === 0 ? null : (int) round($done / $total * 100)],
            'rank' => $standing ? ['rank' => $standing['rank'], 'outOf' => $standing['outOf'], 'points' => $standing['points']] : null,
            'lastActive' => $lastActive ? now()->parse($lastActive)->diffForHumans() : null,
            'signals' => $signals,
        ];
    }

    /**
     * Whether results are going up, down or holding: the later half of the
     * attempts against the earlier half, with five points either way counted
     * as holding. Null until there are two to compare.
     *
     * @param  array<int, float>  $percents
     */
    public function trend(array $percents): ?string
    {
        if (count($percents) < 2) {
            return null;
        }

        $half = intdiv(count($percents), 2);
        $earlier = array_sum(array_slice($percents, 0, $half)) / $half;
        $later = array_sum(array_slice($percents, -$half)) / $half;

        return match (true) {
            $later - $earlier > 5 => 'up',
            $earlier - $later > 5 => 'down',
            default => 'steady',
        };
    }

    /* ------------------------------------------------------ the batch view */

    /**
     * Everybody active on a batch, with each figure as a column the shared
     * table can sort and filter on.
     */
    public function batchQuery(Batch $batch): Builder
    {
        $subs = $this->subqueries($batch);

        return Enrollment::query()
            ->where('enrollments.batch_id', $batch->id)
            ->where('enrollments.status', 'active')
            ->with('user:id,name,email')
            ->select('enrollments.*')
            ->selectSub($subs['attended'], 'attended')
            ->selectSub($subs['on_time'], 'on_time')
            ->selectSub($subs['missing'], 'missing')
            ->selectSub($subs['quiz_average'], 'quiz_average')
            ->selectSub($subs['points'], 'points')
            ->selectSub($subs['last_active'], 'last_active');
    }

    /** Keep only students showing a signal, or one signal in particular. */
    public function applySignal(Builder $query, Batch $batch, string $signal): void
    {
        $subs = $this->subqueries($batch);
        $held = $this->heldCount($batch);

        $clauses = [
            'attendance' => fn (Builder $q) => $held >= 2
                ? $q->where($subs['attended'], '<', $held * self::ATTENDANCE_FLOOR / 100)
                : $q->whereRaw('1 = 0'),
            'missing' => fn (Builder $q) => $q->where($subs['missing'], '>=', self::MISSING_LIMIT),
            'quiz' => fn (Builder $q) => $q->where($subs['quiz_average'], '<', self::QUIZ_FLOOR),
            'quiet' => fn (Builder $q) => $q->where(fn (Builder $inner) => $inner
                ->where($subs['last_active'], '<', now()->subDays(self::QUIET_DAYS))
                ->orWhereNotExists(fn ($none) => $none->from('activity_logs')->whereColumn('activity_logs.user_id', 'enrollments.user_id'))),
        ];

        if ($signal === 'any') {
            $query->where(function (Builder $any) use ($clauses) {
                foreach ($clauses as $clause) {
                    $any->orWhere(fn (Builder $one) => $clause($one));
                }
            });

            return;
        }

        if (isset($clauses[$signal])) {
            $clauses[$signal]($query);
        }
    }

    public function heldCount(Batch $batch): int
    {
        return LiveSession::query()->where('batch_id', $batch->id)->where('status', 'held')->count();
    }

    public function dueCount(Batch $batch): int
    {
        return Assignment::query()
            ->published()
            ->where('course_id', $batch->course_id)
            ->where(fn (Builder $query) => $query->whereNull('batch_id')->orWhere('batch_id', $batch->id))
            ->whereNotNull('due_at')
            ->where('due_at', '<', now())
            ->count();
    }

    /** @return array<string, \Illuminate\Database\Query\Builder|Builder> */
    protected function subqueries(Batch $batch): array
    {
        $courseWork = fn ($query) => $query->whereNull('assignments.batch_id')->orWhere('assignments.batch_id', $batch->id);

        return [
            'attended' => Attendance::query()
                ->selectRaw('count(*)')
                ->join('live_sessions', 'live_sessions.id', '=', 'attendances.live_session_id')
                ->whereColumn('attendances.user_id', 'enrollments.user_id')
                ->where('live_sessions.batch_id', $batch->id)
                ->where('live_sessions.status', 'held')
                ->whereIn('attendances.status', ['present', 'late', 'excused']),
            'on_time' => Submission::query()
                ->selectRaw('count(*)')
                ->join('assignments', 'assignments.id', '=', 'submissions.assignment_id')
                ->whereColumn('submissions.user_id', 'enrollments.user_id')
                ->where('assignments.course_id', $batch->course_id)
                ->where('assignments.is_published', true)
                ->where($courseWork)
                ->whereNotNull('submissions.submitted_at')
                ->whereNotNull('assignments.due_at')
                ->whereColumn('submissions.submitted_at', '<=', 'assignments.due_at'),
            'missing' => Assignment::query()
                ->selectRaw('count(*)')
                ->where('assignments.course_id', $batch->course_id)
                ->where('assignments.is_published', true)
                ->where($courseWork)
                ->where('assignments.due_at', '<', now())
                ->whereNotExists(fn ($query) => $query->from('submissions')
                    ->whereColumn('submissions.assignment_id', 'assignments.id')
                    ->whereColumn('submissions.user_id', 'enrollments.user_id')
                    ->whereNotNull('submissions.submitted_at')),
            'quiz_average' => QuizAttempt::query()
                ->selectRaw('avg(quiz_attempts.percent)')
                ->join('quizzes', 'quizzes.id', '=', 'quiz_attempts.quiz_id')
                ->whereColumn('quiz_attempts.user_id', 'enrollments.user_id')
                ->where('quizzes.course_id', $batch->course_id)
                ->where(fn ($query) => $query->whereNull('quizzes.batch_id')->orWhere('quizzes.batch_id', $batch->id))
                ->whereNotNull('quiz_attempts.submitted_at'),
            'points' => LeaderboardPoint::query()
                ->selectRaw('coalesce(sum(points), 0)')
                ->whereColumn('leaderboard_points.user_id', 'enrollments.user_id')
                ->where('leaderboard_points.batch_id', $batch->id),
            'last_active' => ActivityLog::query()
                ->selectRaw('max(occurred_at)')
                ->whereColumn('activity_logs.user_id', 'enrollments.user_id'),
        ];
    }
}
