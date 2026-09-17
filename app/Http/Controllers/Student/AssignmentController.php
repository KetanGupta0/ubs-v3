<?php

namespace App\Http\Controllers\Student;

use App\Models\Assignment;
use App\Models\Submission;
use App\Services\Lms\Activity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Assignments and projects, from the student's side.
 *
 * A late submission is accepted where the assignment allows it and marked late
 * rather than refused. Refusing it means the work never gets done at all, which
 * helps nobody, and the trainer can still see it came in late.
 */
class AssignmentController extends StudentController
{
    public function index(Request $request): Response
    {
        $student = $this->student($request);

        $assignments = Assignment::query()
            ->whereIn('course_id', $this->enrolledCourseIds($request))
            ->where(fn ($query) => $query->whereNull('batch_id')->orWhereIn('batch_id', $this->enrolledBatchIds($request)))
            ->published()
            ->with('course:id,title')
            ->orderByRaw('due_at is null, due_at')
            ->get();

        $submissions = Submission::query()
            ->where('user_id', $student->id)
            ->whereIn('assignment_id', $assignments->pluck('id'))
            ->get()
            ->keyBy('assignment_id');

        return Inertia::render('student/assignments/Index', [
            'assignments' => $assignments->map(function (Assignment $assignment) use ($submissions) {
                $submission = $submissions[$assignment->id] ?? null;

                return [
                    'id' => $assignment->id,
                    'title' => $assignment->title,
                    'course' => $assignment->course->title,
                    'courseId' => $assignment->course_id,
                    'isProject' => $assignment->is_project,
                    'dueAt' => $assignment->due_at?->format('j M Y, g:i a'),
                    'dueIn' => $assignment->due_at?->diffForHumans(),
                    'overdue' => $assignment->isOverdue(),
                    'maxMarks' => $assignment->max_marks,
                    'submitted' => $submission?->submitted_at !== null,
                    'submittedAt' => $submission?->submitted_at?->format('j M Y'),
                    'late' => (bool) $submission?->is_late,
                    'marks' => $submission?->marks,
                    'status' => $submission?->status,
                    'acceptsSubmissions' => $assignment->acceptsSubmissions(),
                ];
            }),
        ]);
    }

    public function show(Request $request, int $assignment): Response
    {
        $record = $this->readable($request, $assignment);
        $submission = $record->submissionFor($this->student($request));

        return Inertia::render('student/assignments/Show', [
            'assignment' => [
                'id' => $record->id,
                'courseId' => $record->course_id,
                'course' => $record->course->title,
                'title' => $record->title,
                'brief' => $record->brief,
                'checklist' => $record->checklist ?? [],
                'isProject' => $record->is_project,
                'dueAt' => $record->due_at?->format('j M Y, g:i a'),
                'dueIn' => $record->due_at?->diffForHumans(),
                'overdue' => $record->isOverdue(),
                'allowLate' => $record->allow_late,
                'maxMarks' => $record->max_marks,
                'acceptsSubmissions' => $record->acceptsSubmissions(),
            ],
            'submission' => $submission ? [
                'id' => $submission->id,
                'notes' => $submission->notes,
                'repositoryUrl' => $submission->repository_url,
                'demoUrl' => $submission->demo_url,
                'files' => collect($submission->files ?? [])->map(fn (array $file, int $index) => [
                    'index' => $index,
                    'name' => $file['name'] ?? 'Attachment',
                    'size' => $file['size'] ?? null,
                ])->values(),
                'submittedAt' => $submission->submitted_at?->format('j M Y, g:i a'),
                'late' => $submission->is_late,
                'status' => $submission->status,
                'marks' => $submission->marks,
                'percent' => $submission->percent(),
                'feedback' => $submission->feedback,
                'evaluatedBy' => $submission->evaluator?->name,
                'evaluatedAt' => $submission->evaluated_at?->format('j M Y'),
            ] : null,
        ]);
    }

    public function submit(Request $request, int $assignment, Activity $activity): RedirectResponse
    {
        $record = $this->readable($request, $assignment);
        $student = $this->student($request);

        abort_unless($record->acceptsSubmissions(), 422, 'This one is closed for submissions.');

        $validated = $this->validatedInput($request, [
            'notes' => ['nullable', 'string', 'max:5000'],
            'repository_url' => ['nullable', 'url', 'max:255'],
            'demo_url' => ['nullable', 'url', 'max:255'],
            'files' => ['array', 'max:5'],
            'files.*' => ['file', 'max:10240'],
        ], [
            'files.*.max' => 'Each file has to be under 10 MB. Put anything larger in the repository.',
        ]);

        $existing = $record->submissionFor($student);
        $stored = $existing?->files ?? [];

        foreach ($request->file('files') ?? [] as $file) {
            $path = $file->store("submissions/{$record->id}/{$student->id}", 'private');

            $stored[] = [
                'name' => $file->getClientOriginalName(),
                'path' => $path,
                'mime' => $file->getClientMimeType(),
                'size' => $file->getSize(),
            ];
        }

        $late = $record->isOverdue();

        $submission = Submission::query()->updateOrCreate(
            ['assignment_id' => $record->id, 'user_id' => $student->id],
            [
                'notes' => $validated['notes'],
                'repository_url' => $validated['repository_url'],
                'demo_url' => $validated['demo_url'],
                'files' => $stored ?: null,
                'submitted_at' => now(),
                'is_late' => $late,
                'status' => 'submitted',
                // Resubmitting after feedback clears the old mark: a mark that
                // belongs to work that has since changed is worse than none.
                'marks' => null,
                'feedback' => $existing?->status === 'returned' ? null : $existing?->feedback,
            ],
        );

        $activity->did($student, 'assignment.submitted', $record, [
            'title' => $record->title,
        ], $record->course_id, $record->batch_id);

        if (! $late) {
            $activity->award($student, 'assignment.onTime', $record, courseId: $record->course_id, batchId: $record->batch_id);
        }

        return back()->with('success', $late
            ? 'Submitted, and marked as late. Better in than not.'
            : 'Submitted. Your trainer will mark it.');
    }

    public function downloadFile(Request $request, int $assignment, int $index): StreamedResponse
    {
        $record = $this->readable($request, $assignment);
        $submission = $record->submissionFor($this->student($request));

        abort_unless($submission, 404);

        $file = ($submission->files ?? [])[$index] ?? null;

        abort_unless($file && Storage::disk('private')->exists($file['path']), 404);

        return Storage::disk('private')->download($file['path'], $file['name']);
    }

    protected function readable(Request $request, int $assignment): Assignment
    {
        return Assignment::query()
            ->whereIn('course_id', $this->enrolledCourseIds($request))
            ->published()
            ->with('course:id,title')
            ->findOrFail($assignment);
    }
}
