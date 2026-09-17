<?php

namespace App\Http\Controllers\Api\V1\Student;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\Assignment;
use App\Models\Certificate;
use App\Models\CourseModule;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\LiveSession;
use App\Models\PaymentRequest;
use App\Models\StudentWarning;
use App\Services\Lms\ContentGate;
use App\Services\Lms\Leaderboard;
use App\Services\Lms\Progress;
use App\Services\Lms\ResultCard;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The learning management system, for the mobile application.
 *
 * Shapes mirror what the web screens receive, so a change to what a lesson
 * looks like is made once. Every query starts from this student's own
 * enrolments, exactly as the web controllers do: a token is not a reason to
 * relax that, and a lock is enforced here as well as on the page.
 */
class StudentApiController extends Controller
{
    public function overview(Request $request): JsonResponse
    {
        $student = $request->user();

        $enrolments = Enrollment::query()
            ->forStudent($student)
            ->with(['course', 'batch'])
            ->get();

        $next = LiveSession::query()
            ->whereIn('batch_id', $enrolments->pluck('batch_id')->filter())
            ->upcoming()
            ->with('batch:id,name,course_id')
            ->first();

        return response()->json([
            'totals' => [
                'courses' => $enrolments->where('status', 'active')->count(),
                'unreadNotices' => StudentWarning::query()
                    ->where('user_id', $student->id)
                    ->whereNull('acknowledged_at')
                    ->whereNull('resolved_at')
                    ->count(),
                'outstanding' => Money::display(
                    (int) PaymentRequest::query()->forUser($student)->pending()->sum('total'),
                ),
                'points' => app(Leaderboard::class)->totalFor($student),
            ],
            'nextClass' => $next ? $this->sessionArray($next) : null,
            'courses' => $enrolments->map(fn (Enrollment $enrolment) => $this->courseArray($enrolment)),
        ]);
    }

    public function courses(Request $request): JsonResponse
    {
        return response()->json([
            'data' => Enrollment::query()
                ->forStudent($request->user())
                ->with(['course', 'batch'])
                ->get()
                ->map(fn (Enrollment $enrolment) => $this->courseArray($enrolment)),
        ]);
    }

    /** One course, with every lesson and why each is open or not. */
    public function course(Request $request, int $course): JsonResponse
    {
        $enrolment = $this->enrolment($request, $course);
        $gate = ContentGate::for($request->user(), $enrolment);

        $modules = CourseModule::query()
            ->where('course_id', $course)
            ->published()
            ->with(['lessons' => fn ($query) => $query->where('is_published', true)->orderBy('sort_order')])
            ->orderBy('sort_order')
            ->get();

        return response()->json([
            'data' => [
                ...$this->courseArray($enrolment),
                'modules' => $modules->map(fn (CourseModule $module) => [
                    'id' => $module->id,
                    'title' => $module->title,
                    'summary' => $module->summary,
                    'lessons' => $module->lessons->map(fn (Lesson $lesson) => [
                        'id' => $lesson->id,
                        'title' => $lesson->title,
                        'summary' => $lesson->summary,
                        'duration' => $lesson->durationLabel(),
                        'completed' => $gate->hasCompleted($lesson),
                        'lock' => $gate->check($lesson)->toArray(),
                    ]),
                ]),
            ],
        ]);
    }

    /**
     * The content of one lesson.
     *
     * A sealed lesson answers with its lock rather than its content, in the
     * same words the page uses.
     */
    public function lesson(Request $request, int $course, int $lesson): JsonResponse
    {
        $enrolment = $this->enrolment($request, $course);
        $gate = ContentGate::for($request->user(), $enrolment);

        $record = Lesson::query()
            ->where('course_id', $course)
            ->where('is_published', true)
            ->with('materials')
            ->findOrFail($lesson);

        $lock = $gate->check($record);

        if (! $lock->open) {
            return response()->json(['data' => [
                'id' => $record->id,
                'title' => $record->title,
                'summary' => $record->summary,
                'lock' => $lock->toArray(),
            ]], 423);
        }

        return response()->json(['data' => [
            'id' => $record->id,
            'title' => $record->title,
            'summary' => $record->summary,
            'content' => $record->content,
            'videoUrl' => $record->video_url,
            'duration' => $record->durationLabel(),
            'completed' => $gate->hasCompleted($record),
            'lock' => $lock->toArray(),
            'materials' => $record->materials->map(fn ($material) => [
                'id' => $material->id,
                'title' => $material->title,
                'kind' => $material->kind(),
                'size' => $material->sizeLabel(),
                'isLink' => $material->isLink(),
            ]),
        ]]);
    }

    public function completeLesson(Request $request, int $course, int $lesson, Progress $progress): JsonResponse
    {
        $enrolment = $this->enrolment($request, $course);
        $gate = ContentGate::for($request->user(), $enrolment);

        $record = Lesson::query()->where('course_id', $course)->findOrFail($lesson);

        // Marking a sealed lesson finished would be a way around the gate.
        abort_unless($gate->isOpen($record), 403);

        $progress->markComplete($request->user(), $record, $enrolment);

        return response()->json(['data' => ['progress' => $enrolment->fresh()->progress_percent]]);
    }

    public function classes(Request $request): JsonResponse
    {
        $batchIds = Enrollment::query()
            ->forStudent($request->user())
            ->whereNotNull('batch_id')
            ->pluck('batch_id');

        return response()->json([
            'upcoming' => LiveSession::query()
                ->whereIn('batch_id', $batchIds)
                ->upcoming()
                ->with('batch:id,name,course_id')
                ->get()
                ->map(fn (LiveSession $session) => $this->sessionArray($session)),
            'past' => LiveSession::query()
                ->whereIn('batch_id', $batchIds)
                ->where('scheduled_at', '<', now())
                ->with('batch:id,name,course_id')
                ->latest('scheduled_at')
                ->take(20)
                ->get()
                ->map(fn (LiveSession $session) => $this->sessionArray($session)),
        ]);
    }

    public function assignments(Request $request): JsonResponse
    {
        $enrolments = Enrollment::query()->forStudent($request->user())->get();

        return response()->json([
            'data' => Assignment::query()
                ->whereIn('course_id', $enrolments->pluck('course_id'))
                ->where(fn ($query) => $query
                    ->whereNull('batch_id')
                    ->orWhereIn('batch_id', $enrolments->pluck('batch_id')->filter()))
                ->published()
                ->with('course:id,title')
                ->get()
                ->map(function (Assignment $assignment) use ($request) {
                    $submission = $assignment->submissionFor($request->user());

                    return [
                        'id' => $assignment->id,
                        'title' => $assignment->title,
                        'course' => $assignment->course->title,
                        'isProject' => $assignment->is_project,
                        'dueAt' => $assignment->due_at?->toIso8601String(),
                        'overdue' => $assignment->isOverdue() && $submission === null,
                        'submitted' => $submission?->submitted_at !== null,
                        'marks' => $submission?->marks,
                        'maxMarks' => $assignment->max_marks,
                        'feedback' => $submission?->feedback,
                    ];
                }),
        ]);
    }

    public function results(Request $request, ResultCard $card): JsonResponse
    {
        return response()->json([
            'data' => Enrollment::query()
                ->forStudent($request->user())
                ->with(['course', 'batch'])
                ->get()
                ->map(fn (Enrollment $enrolment) => [
                    'courseId' => $enrolment->course_id,
                    'course' => $enrolment->course->title,
                    'batch' => $enrolment->batch?->name,
                    'result' => $card->for($enrolment),
                ]),
        ]);
    }

    public function announcements(Request $request): JsonResponse
    {
        $enrolments = Enrollment::query()->forStudent($request->user())->get();

        return response()->json([
            'data' => Announcement::query()
                ->for($enrolments->pluck('batch_id')->filter()->all(), $enrolments->pluck('course_id')->all())
                ->with(['author:id,name'])
                ->orderByDesc('is_pinned')
                ->orderByDesc('published_at')
                ->take(40)
                ->get()
                ->map(fn (Announcement $announcement) => [
                    'id' => $announcement->id,
                    'title' => $announcement->title,
                    'body' => $announcement->body,
                    'author' => $announcement->author?->name ?? 'Unboundbyte',
                    'pinned' => $announcement->is_pinned,
                    'publishedAt' => $announcement->published_at->toIso8601String(),
                ]),
        ]);
    }

    public function certificates(Request $request): JsonResponse
    {
        return response()->json([
            'data' => Certificate::query()
                ->where('user_id', $request->user()->id)
                ->with('course:id,title')
                ->latest('issued_at')
                ->get()
                ->map(fn (Certificate $certificate) => [
                    'id' => $certificate->id,
                    'number' => $certificate->number,
                    'title' => $certificate->title,
                    'issuedAt' => $certificate->issued_at->toIso8601String(),
                    'grade' => $certificate->grade,
                    'valid' => $certificate->isValid(),
                    'verificationUrl' => $certificate->verificationUrl(),
                ]),
        ]);
    }

    public function payments(Request $request): JsonResponse
    {
        return response()->json([
            'data' => PaymentRequest::query()
                ->forUser($request->user())
                ->latest('id')
                ->get()
                ->map(fn (PaymentRequest $payment) => [
                    'id' => $payment->id,
                    'reference' => $payment->reference,
                    'title' => $payment->title,
                    'total' => Money::display($payment->total),
                    'status' => $payment->status,
                    'dueOn' => $payment->due_on?->toDateString(),
                    'overdue' => $payment->isOverdue(),
                ]),
        ]);
    }

    /* ----------------------------------------------------------- helpers */

    /** This student's enrolment, or a 404 that says nothing. */
    protected function enrolment(Request $request, int $courseId): Enrollment
    {
        return Enrollment::query()
            ->forStudent($request->user())
            ->where('course_id', $courseId)
            ->with(['course', 'batch'])
            ->firstOrFail();
    }

    /** @return array<string, mixed> */
    protected function courseArray(Enrollment $enrolment): array
    {
        return [
            'id' => $enrolment->course_id,
            'title' => $enrolment->course->title,
            'type' => $enrolment->course->type,
            'batch' => $enrolment->batch?->name,
            'status' => $enrolment->status,
            'progress' => $enrolment->progress_percent,
            'hasPaid' => $enrolment->has_paid,
            'paymentId' => $enrolment->payment_request_id,
        ];
    }

    /** @return array<string, mixed> */
    protected function sessionArray(LiveSession $session): array
    {
        return [
            'id' => $session->id,
            'title' => $session->title,
            'agenda' => $session->agenda,
            'batch' => $session->batch?->name,
            'scheduledAt' => $session->scheduled_at->toIso8601String(),
            'duration' => $session->duration_minutes,
            'status' => $session->status,
            'live' => $session->isLive(),
            // The link itself only travels once the join window is open.
            'joinable' => $session->isJoinable(),
            'joinUrl' => $session->isJoinable() ? $session->link() : null,
            'recordingUrl' => $session->recording_url,
        ];
    }
}
