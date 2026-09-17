<?php

namespace App\Http\Controllers\Student;

use App\Models\Announcement;
use App\Models\Assignment;
use App\Models\Enrollment;
use App\Models\LiveSession;
use App\Models\PaymentRequest;
use App\Models\Quiz;
use App\Models\StudentWarning;
use App\Models\Submission;
use App\Services\Lms\Activity;
use App\Services\Lms\Leaderboard;
use App\Support\Money;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The student's landing screen.
 *
 * Opens with the next class and what is due, because that is what somebody
 * signing in at nine in the evening actually wants. Progress bars and points
 * come after.
 */
class DashboardController extends StudentController
{
    public function __invoke(Request $request, Activity $activity, Leaderboard $leaderboard): Response
    {
        $student = $this->student($request);
        $batchIds = $this->enrolledBatchIds($request);
        $courseIds = $this->enrolledCourseIds($request);

        $enrolments = Enrollment::query()
            ->forStudent($student)
            ->with(['course:id,title,slug,type,accent', 'batch:id,name,starts_on,ends_on'])
            ->whereIn('status', ['active', 'completed'])
            ->latest('enrolled_at')
            ->get();

        $nextSession = LiveSession::query()
            ->whereIn('batch_id', $batchIds)
            ->upcoming()
            ->with('batch.course:id,title')
            ->first();

        return Inertia::render('student/Dashboard', [
            'courses' => $enrolments->map(fn (Enrollment $enrolment) => [
                'id' => $enrolment->course_id,
                'title' => $enrolment->course->title,
                'type' => $enrolment->course->type,
                'accent' => $enrolment->course->accent,
                'batch' => $enrolment->batch?->name,
                'progress' => $enrolment->progress_percent,
                'status' => $enrolment->status,
                'hasPaid' => $enrolment->has_paid,
            ]),

            'nextClass' => $nextSession ? [
                'id' => $nextSession->id,
                'title' => $nextSession->title,
                'course' => $nextSession->batch?->course?->title,
                'at' => $nextSession->scheduled_at->format('D j M, g:i a'),
                'startsIn' => $nextSession->scheduled_at->diffForHumans(),
                'joinable' => $nextSession->isJoinable(),
                'live' => $nextSession->isLive(),
                'link' => $nextSession->isJoinable() ? $nextSession->link() : null,
            ] : null,

            'dueSoon' => $this->dueSoon($request),

            'announcements' => Announcement::query()
                ->for($batchIds, $courseIds)
                ->with('batch:id,name')
                ->orderByDesc('is_pinned')
                ->orderByDesc('published_at')
                ->take(4)
                ->get()
                ->map(fn (Announcement $announcement) => [
                    'id' => $announcement->id,
                    'title' => $announcement->title,
                    'body' => str($announcement->body)->limit(160)->toString(),
                    'pinned' => $announcement->is_pinned,
                    'at' => $announcement->published_at->diffForHumans(),
                ]),

            'stats' => [
                'streak' => $activity->streak($student),
                'points' => $leaderboard->totalFor($student),
                'certificates' => $student->certificates()->whereNull('revoked_at')->count(),
                'outstanding' => Money::display(
                    (int) PaymentRequest::query()->forUser($student)->pending()->sum('total'),
                ),
            ],

            'unreadWarnings' => StudentWarning::query()
                ->where('user_id', $student->id)
                ->unacknowledged()
                ->count(),

            'recentActivity' => $student->activity()
                ->latest('occurred_at')
                ->take(8)
                ->get()
                ->map(fn ($log) => [
                    'id' => $log->id,
                    'text' => $log->describe(),
                    'at' => $log->occurred_at->diffForHumans(),
                ]),
        ]);
    }

    /**
     * Quizzes and assignments with a deadline in view.
     *
     * Sorted by when they are due rather than grouped by kind, because a
     * student does not think "my quizzes, then my assignments", they think
     * "what is due first".
     *
     * @return array<int, array<string, mixed>>
     */
    protected function dueSoon(Request $request): array
    {
        $student = $this->student($request);
        $courseIds = $this->enrolledCourseIds($request);
        $batchIds = $this->enrolledBatchIds($request);
        $items = [];

        $submitted = Submission::query()
            ->where('user_id', $student->id)
            ->pluck('assignment_id')
            ->flip();

        Assignment::query()
            ->whereIn('course_id', $courseIds)
            ->where(fn ($query) => $query->whereNull('batch_id')->orWhereIn('batch_id', $batchIds))
            ->published()
            ->whereNotNull('due_at')
            ->where('due_at', '>=', now()->subWeek())
            ->with('course:id,title')
            ->get()
            ->each(function (Assignment $assignment) use (&$items, $submitted) {
                if ($submitted->has($assignment->id)) {
                    return;
                }

                $items[] = [
                    'kind' => 'assignment',
                    'id' => $assignment->id,
                    'title' => $assignment->title,
                    'course' => $assignment->course->title,
                    'courseId' => $assignment->course_id,
                    'dueAt' => $assignment->due_at->format('j M, g:i a'),
                    'dueIn' => $assignment->due_at->diffForHumans(),
                    'overdue' => $assignment->isOverdue(),
                    'href' => "/student/assignments/{$assignment->id}",
                    'sortAt' => $assignment->due_at->timestamp,
                ];
            });

        Quiz::query()
            ->whereIn('course_id', $courseIds)
            ->published()
            ->whereNotNull('closes_at')
            ->where('closes_at', '>=', now())
            ->with('course:id,title')
            ->get()
            ->each(function (Quiz $quiz) use (&$items, $student) {
                if (! $quiz->canBeAttemptedBy($student)) {
                    return;
                }

                $items[] = [
                    'kind' => 'quiz',
                    'id' => $quiz->id,
                    'title' => $quiz->title,
                    'course' => $quiz->course->title,
                    'courseId' => $quiz->course_id,
                    'dueAt' => $quiz->closes_at->format('j M, g:i a'),
                    'dueIn' => $quiz->closes_at->diffForHumans(),
                    'overdue' => false,
                    'href' => "/student/quizzes/{$quiz->id}",
                    'sortAt' => $quiz->closes_at->timestamp,
                ];
            });

        usort($items, fn ($a, $b) => $a['sortAt'] <=> $b['sortAt']);

        return array_slice($items, 0, 6);
    }
}
