<?php

namespace App\Http\Controllers\Student;

use App\Models\Assignment;
use App\Models\CourseModule;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\Material;
use App\Models\Quiz;
use App\Services\Lms\Activity;
use App\Services\Lms\ContentGate;
use App\Services\Lms\Progress;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The course player: modules, lessons and materials.
 *
 * Locked lessons are listed with the reason they are locked. Hiding them
 * removes the thing that makes somebody finish the lesson they are on, and
 * hiding the reason turns a schedule into a mystery.
 */
class CourseController extends StudentController
{
    public function index(Request $request): Response
    {
        $enrolments = Enrollment::query()
            ->forStudent($this->student($request))
            ->with(['course', 'batch'])
            ->latest('enrolled_at')
            ->get();

        return Inertia::render('student/courses/Index', [
            'courses' => $enrolments->map(fn (Enrollment $enrolment) => [
                'id' => $enrolment->course_id,
                'title' => $enrolment->course->title,
                'tagline' => $enrolment->course->tagline,
                'type' => $enrolment->course->type,
                'accent' => $enrolment->course->accent,
                'batch' => $enrolment->batch?->name,
                'startsOn' => $enrolment->batch?->starts_on?->format('j M Y'),
                'endsOn' => $enrolment->batch?->ends_on?->format('j M Y'),
                'progress' => $enrolment->progress_percent,
                'status' => $enrolment->status,
                'statusLabel' => $enrolment->statusLabel(),
                'hasPaid' => $enrolment->has_paid,
                'feeDue' => $enrolment->has_paid
                    ? null
                    : Money::display($enrolment->course->effectivePrice()),
                'paymentId' => $enrolment->payment_request_id,
                'lessons' => $enrolment->course->lessonCount(),
            ]),
        ]);
    }

    public function show(Request $request, int $course, Progress $progress): Response
    {
        $enrolment = $this->enrolment($request, $course);
        $gate = ContentGate::for($this->student($request), $enrolment);

        $modules = CourseModule::query()
            ->where('course_id', $course)
            ->published()
            ->with(['lessons' => fn ($query) => $query->where('is_published', true)])
            ->orderBy('sort_order')
            ->get();

        return Inertia::render('student/courses/Show', [
            'course' => [
                'id' => $enrolment->course_id,
                'title' => $enrolment->course->title,
                'tagline' => $enrolment->course->tagline,
                'type' => $enrolment->course->type,
                'summary' => $enrolment->course->summary,
                'accent' => $enrolment->course->accent,
            ],
            'enrolment' => [
                'progress' => $enrolment->progress_percent,
                'status' => $enrolment->status,
                'hasPaid' => $enrolment->has_paid,
                'feeDue' => $enrolment->has_paid ? null : Money::display($enrolment->course->effectivePrice()),
                'paymentId' => $enrolment->payment_request_id,
                'batch' => $enrolment->batch?->name,
            ],
            'modules' => $modules->map(fn (CourseModule $module) => [
                'id' => $module->id,
                'title' => $module->title,
                'summary' => $module->summary,
                'lessons' => $module->lessons->map(function (Lesson $lesson) use ($gate) {
                    $lock = $gate->check($lesson);

                    return [
                        'id' => $lesson->id,
                        'title' => $lesson->title,
                        'summary' => $lesson->summary,
                        'duration' => $lesson->durationLabel(),
                        'isPreview' => $lesson->is_preview,
                        'completed' => $gate->hasCompleted($lesson),
                        'lock' => $lock->toArray(),
                    ];
                }),
            ]),
            'quizzes' => Quiz::query()
                ->where('course_id', $course)
                ->published()
                ->get()
                ->map(fn (Quiz $quiz) => [
                    'id' => $quiz->id,
                    'title' => $quiz->title,
                    'questions' => $quiz->questions()->count(),
                    'attemptsLeft' => max(0, $quiz->attempts_allowed - $quiz->attemptsUsedBy($this->student($request))),
                    'best' => $quiz->bestAttemptFor($this->student($request))?->percent,
                    'open' => $quiz->isOpen(),
                    'closedReason' => $quiz->closedReason(),
                ]),
            'assignments' => Assignment::query()
                ->where('course_id', $course)
                ->where(fn ($query) => $query->whereNull('batch_id')->orWhere('batch_id', $enrolment->batch_id))
                ->published()
                ->get()
                ->map(function (Assignment $assignment) use ($request) {
                    $submission = $assignment->submissionFor($this->student($request));

                    return [
                        'id' => $assignment->id,
                        'title' => $assignment->title,
                        'isProject' => $assignment->is_project,
                        'dueAt' => $assignment->due_at?->format('j M Y, g:i a'),
                        'overdue' => $assignment->isOverdue(),
                        'submitted' => $submission?->submitted_at !== null,
                        'marks' => $submission?->marks,
                        'maxMarks' => $assignment->max_marks,
                    ];
                }),
        ]);
    }

    public function lesson(Request $request, int $course, int $lesson): Response
    {
        $enrolment = $this->enrolment($request, $course);
        $gate = ContentGate::for($this->student($request), $enrolment);

        $record = Lesson::query()
            ->where('course_id', $course)
            ->where('is_published', true)
            ->with(['materials', 'module'])
            ->findOrFail($lesson);

        $lock = $gate->check($record);

        // A locked lesson renders as the reason it is locked, not as a 403: the
        // student is allowed to know it exists and when it opens.
        if (! $lock->open) {
            return Inertia::render('student/courses/Locked', [
                'course' => ['id' => $course, 'title' => $enrolment->course->title],
                'lesson' => ['id' => $record->id, 'title' => $record->title, 'summary' => $record->summary],
                'lock' => $lock->toArray(),
                'paymentId' => $enrolment->payment_request_id,
            ]);
        }

        $siblings = Lesson::query()
            ->where('course_module_id', $record->course_module_id)
            ->where('is_published', true)
            ->orderBy('sort_order')
            ->get(['id', 'title', 'sort_order']);

        $position = $siblings->search(fn ($item) => $item->id === $record->id);

        return Inertia::render('student/courses/Lesson', [
            'course' => ['id' => $course, 'title' => $enrolment->course->title],
            'module' => ['id' => $record->course_module_id, 'title' => $record->module->title],
            'lesson' => [
                'id' => $record->id,
                'title' => $record->title,
                'summary' => $record->summary,
                'content' => $record->content,
                'videoUrl' => $record->video_url,
                'duration' => $record->durationLabel(),
                'completed' => $gate->hasCompleted($record),
            ],
            'materials' => $record->materials->map(fn (Material $material) => $this->materialArray($material)),
            'previous' => $position > 0 ? [
                'id' => $siblings[$position - 1]->id,
                'title' => $siblings[$position - 1]->title,
            ] : null,
            'next' => $position !== false && $position < $siblings->count() - 1 ? [
                'id' => $siblings[$position + 1]->id,
                'title' => $siblings[$position + 1]->title,
            ] : null,
        ]);
    }

    public function complete(Request $request, int $course, int $lesson, Progress $progress): RedirectResponse
    {
        $enrolment = $this->enrolment($request, $course);
        $gate = ContentGate::for($this->student($request), $enrolment);

        $record = Lesson::query()->where('course_id', $course)->findOrFail($lesson);

        // Marking a locked lesson done would be a way around the gate.
        abort_unless($gate->isOpen($record), 403);

        $request->boolean('undo')
            ? $progress->markIncomplete($this->student($request), $record)
            : $progress->markComplete($this->student($request), $record, $enrolment);

        return back();
    }

    /** Everything attached to a course, in one list. */
    public function materials(Request $request, int $course): Response
    {
        $enrolment = $this->enrolment($request, $course);
        $gate = ContentGate::for($this->student($request), $enrolment);

        $materials = Material::query()
            ->where('course_id', $course)
            ->with('lesson')
            ->orderBy('sort_order')
            ->get()
            // A handout attached to a sealed lesson stays sealed with it.
            ->filter(fn (Material $material) => $material->lesson === null || $gate->isOpen($material->lesson));

        return Inertia::render('student/courses/Materials', [
            'course' => ['id' => $course, 'title' => $enrolment->course->title],
            'materials' => $materials->map(fn (Material $material) => [
                ...$this->materialArray($material),
                'lesson' => $material->lesson?->title,
            ])->values(),
        ]);
    }

    public function downloadMaterial(Request $request, int $course, int $material): StreamedResponse
    {
        $enrolment = $this->enrolment($request, $course);
        $gate = ContentGate::for($this->student($request), $enrolment);

        /** @var Material $record */
        $record = Material::query()->where('course_id', $course)->with('lesson')->findOrFail($material);

        abort_if($record->lesson && ! $gate->isOpen($record->lesson), 403);
        abort_unless($record->is_downloadable, 403, 'This one is view only.');
        abort_unless($record->path && Storage::disk('private')->exists($record->path), 404);

        app(Activity::class)->record(
            $this->student($request),
            'material.downloaded',
            $record,
            ['title' => $record->title],
            $course,
            $enrolment->batch_id,
        );

        return Storage::disk('private')->download($record->path, $record->title);
    }

    /** @return array<string, mixed> */
    protected function materialArray(Material $material): array
    {
        return [
            'id' => $material->id,
            'title' => $material->title,
            'description' => $material->description,
            'kind' => $material->kind(),
            'size' => $material->sizeLabel(),
            'isLink' => $material->isLink(),
            'url' => $material->external_url,
            'downloadable' => $material->is_downloadable && filled($material->path),
        ];
    }
}
