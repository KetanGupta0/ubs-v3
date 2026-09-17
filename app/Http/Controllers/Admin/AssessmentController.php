<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Assignment;
use App\Models\Batch;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\Question;
use App\Models\Quiz;
use App\Models\QuizAnswer;
use App\Models\QuizAttempt;
use App\Models\Submission;
use App\Services\Admin\Auditor;
use App\Services\Lms\Activity;
use App\Services\Lms\QuizGrader;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Quizzes, assignments, and marking what came back.
 *
 * Written answers and submissions land in one marking queue rather than being
 * scattered across each quiz and each assignment, because the person doing the
 * marking thinks "what is waiting for me", not "which quiz shall I open".
 */
class AssessmentController extends Controller
{
    /* ----------------------------------------------------------- quizzes */

    public function quizzes(Request $request, Course $course): Response
    {
        return Inertia::render('admin/assessment/Quizzes', [
            'course' => ['id' => $course->id, 'title' => $course->title],
            'modules' => $course->modules()->get(['id', 'title'])
                ->map(fn (CourseModule $module) => ['value' => $module->id, 'label' => $module->title]),
            'quizzes' => Quiz::query()
                ->where('course_id', $course->id)
                ->withCount(['questions', 'attempts'])
                ->get()
                ->map(fn (Quiz $quiz) => [
                    'id' => $quiz->id,
                    'title' => $quiz->title,
                    'instructions' => $quiz->instructions,
                    'moduleId' => $quiz->course_module_id,
                    'questions' => $quiz->questions_count,
                    'attempts' => $quiz->attempts_count,
                    'totalMarks' => $quiz->totalMarks(),
                    'timeLimit' => $quiz->time_limit_minutes,
                    'attemptsAllowed' => $quiz->attempts_allowed,
                    'passPercent' => $quiz->pass_percent,
                    'shuffle' => $quiz->shuffle_questions,
                    'showAnswers' => $quiz->show_answers,
                    'opensAt' => $quiz->opens_at?->format('Y-m-d\TH:i'),
                    'closesAt' => $quiz->closes_at?->format('Y-m-d\TH:i'),
                    'isPublished' => $quiz->is_published,
                    'awaitingMarking' => $quiz->attempts()->where('needs_review', true)->count(),
                ]),
        ]);
    }

    public function storeQuiz(Request $request, Course $course): RedirectResponse
    {
        $quiz = Quiz::query()->create($this->quizRules($request) + ['course_id' => $course->id]);

        return redirect()
            ->route('admin.quizzes.edit', [$course->id, $quiz->id])
            ->with('success', 'Quiz created. Add the questions next.');
    }

    public function editQuiz(Course $course, Quiz $quiz): Response
    {
        abort_unless($quiz->course_id === $course->id, 404);

        return Inertia::render('admin/assessment/QuizEditor', [
            'course' => ['id' => $course->id, 'title' => $course->title],
            'quiz' => [
                'id' => $quiz->id,
                'title' => $quiz->title,
                'instructions' => $quiz->instructions,
                'totalMarks' => $quiz->totalMarks(),
                'passPercent' => $quiz->pass_percent,
                'isPublished' => $quiz->is_published,
                'hasAttempts' => $quiz->attempts()->exists(),
            ],
            'questions' => $quiz->questions()->get()->map(fn (Question $question) => [
                'id' => $question->id,
                'type' => $question->type,
                'typeLabel' => $question->typeLabel(),
                'body' => $question->body,
                'options' => $question->options ?? [],
                'correct' => $question->correct ?? [],
                'explanation' => $question->explanation,
                'marks' => $question->marks,
                'sortOrder' => $question->sort_order,
            ]),
            'types' => collect(Question::TYPES)->map(fn (string $type) => [
                'value' => $type,
                'label' => (new Question(['type' => $type]))->typeLabel(),
            ]),
        ]);
    }

    public function updateQuiz(Request $request, Course $course, Quiz $quiz): RedirectResponse
    {
        abort_unless($quiz->course_id === $course->id, 404);

        $quiz->fill($this->quizRules($request))->save();

        return back()->with('success', 'Quiz saved.');
    }

    public function destroyQuiz(Course $course, Quiz $quiz, Auditor $auditor): RedirectResponse
    {
        abort_unless($quiz->course_id === $course->id, 404);

        if ($quiz->attempts()->exists()) {
            return back()->withErrors([
                'quiz' => 'Students have sat this quiz. Unpublish it instead; deleting it would delete their results.',
            ]);
        }

        $auditor->deleted($quiz, $quiz->title);
        $quiz->delete();

        return redirect()
            ->route('admin.quizzes.index', $course->id)
            ->with('success', 'Quiz removed.');
    }

    public function storeQuestion(Request $request, Course $course, Quiz $quiz): RedirectResponse
    {
        abort_unless($quiz->course_id === $course->id, 404);

        $quiz->questions()->create($this->questionRules($request) + [
            'sort_order' => ($quiz->questions()->max('sort_order') ?? -1) + 1,
        ]);

        return back()->with('success', 'Question added.');
    }

    public function updateQuestion(Request $request, Course $course, Quiz $quiz, Question $question): RedirectResponse
    {
        abort_unless($quiz->course_id === $course->id && $question->quiz_id === $quiz->id, 404);

        $question->fill($this->questionRules($request))->save();

        return back()->with('success', 'Question saved.');
    }

    public function destroyQuestion(Course $course, Quiz $quiz, Question $question): RedirectResponse
    {
        abort_unless($quiz->course_id === $course->id && $question->quiz_id === $quiz->id, 404);

        $question->delete();

        return back()->with('success', 'Question removed.');
    }

    /* ------------------------------------------------------- assignments */

    public function assignments(Request $request, Course $course): Response
    {
        return Inertia::render('admin/assessment/Assignments', [
            'course' => ['id' => $course->id, 'title' => $course->title],
            'modules' => $course->modules()->get(['id', 'title'])
                ->map(fn (CourseModule $module) => ['value' => $module->id, 'label' => $module->title]),
            'batches' => $course->batches()->get(['id', 'name'])
                ->map(fn (Batch $batch) => ['value' => $batch->id, 'label' => $batch->name]),
            'assignments' => Assignment::query()
                ->where('course_id', $course->id)
                ->withCount([
                    'submissions',
                    'submissions as awaiting_count' => fn ($query) => $query->awaitingMarking(),
                ])
                ->get()
                ->map(fn (Assignment $assignment) => [
                    'id' => $assignment->id,
                    'title' => $assignment->title,
                    'brief' => $assignment->brief,
                    'checklist' => $assignment->checklist ?? [],
                    'moduleId' => $assignment->course_module_id,
                    'batchId' => $assignment->batch_id,
                    'dueAt' => $assignment->due_at?->format('Y-m-d\TH:i'),
                    'dueAtLabel' => $assignment->due_at?->format('j M Y, g:i a'),
                    'maxMarks' => $assignment->max_marks,
                    'allowLate' => $assignment->allow_late,
                    'isProject' => $assignment->is_project,
                    'isPublished' => $assignment->is_published,
                    'submissions' => $assignment->submissions_count,
                    'awaiting' => $assignment->awaiting_count,
                ]),
        ]);
    }

    public function storeAssignment(Request $request, Course $course): RedirectResponse
    {
        Assignment::query()->create($this->assignmentRules($request) + ['course_id' => $course->id]);

        return back()->with('success', 'Assignment added.');
    }

    public function updateAssignment(Request $request, Course $course, Assignment $assignment): RedirectResponse
    {
        abort_unless($assignment->course_id === $course->id, 404);

        $assignment->fill($this->assignmentRules($request))->save();

        return back()->with('success', 'Assignment saved.');
    }

    public function destroyAssignment(Course $course, Assignment $assignment): RedirectResponse
    {
        abort_unless($assignment->course_id === $course->id, 404);

        if ($assignment->submissions()->exists()) {
            return back()->withErrors([
                'assignment' => 'Work has been submitted against this. Unpublish it instead.',
            ]);
        }

        $assignment->delete();

        return back()->with('success', 'Assignment removed.');
    }

    /* ----------------------------------------------------------- marking */

    /**
     * Everything waiting to be marked, in one queue.
     *
     * Written quiz answers and assignment submissions together, because the
     * person marking thinks "what is waiting", not "which quiz shall I open".
     */
    public function marking(Request $request): Response
    {
        $submissions = Submission::query()
            ->awaitingMarking()
            ->with(['assignment.course:id,title', 'user:id,name'])
            ->orderBy('submitted_at')
            ->take(100)
            ->get();

        $attempts = QuizAttempt::query()
            ->where('needs_review', true)
            ->with(['quiz.course:id,title', 'user:id,name'])
            ->orderBy('submitted_at')
            ->take(100)
            ->get();

        return Inertia::render('admin/assessment/Marking', [
            'submissions' => $submissions->map(fn (Submission $submission) => [
                'id' => $submission->id,
                'student' => $submission->user->name,
                'assignment' => $submission->assignment->title,
                'course' => $submission->assignment->course->title,
                'maxMarks' => $submission->assignment->max_marks,
                'submittedAt' => $submission->submitted_at?->format('j M Y'),
                'late' => $submission->is_late,
                'waitingDays' => (int) $submission->submitted_at?->diffInDays(now()),
            ]),
            'attempts' => $attempts->map(fn (QuizAttempt $attempt) => [
                'id' => $attempt->id,
                'quizId' => $attempt->quiz_id,
                'student' => $attempt->user->name,
                'quiz' => $attempt->quiz->title,
                'course' => $attempt->quiz->course->title,
                'submittedAt' => $attempt->submitted_at?->format('j M Y'),
                'waitingDays' => (int) $attempt->submitted_at?->diffInDays(now()),
            ]),
        ]);
    }

    public function submission(Submission $submission): Response
    {
        $submission->load(['assignment.course:id,title', 'user:id,name,email', 'evaluator:id,name']);

        return Inertia::render('admin/assessment/Submission', [
            'submission' => [
                'id' => $submission->id,
                'student' => $submission->user->name,
                'email' => $submission->user->email,
                'notes' => $submission->notes,
                'repositoryUrl' => $submission->repository_url,
                'demoUrl' => $submission->demo_url,
                'files' => collect($submission->files ?? [])->map(fn (array $file, int $index) => [
                    'index' => $index,
                    'name' => $file['name'] ?? 'Attachment',
                ])->values(),
                'submittedAt' => $submission->submitted_at?->format('j M Y, g:i a'),
                'late' => $submission->is_late,
                'status' => $submission->status,
                'marks' => $submission->marks,
                'feedback' => $submission->feedback,
                'evaluatedBy' => $submission->evaluator?->name,
            ],
            'assignment' => [
                'id' => $submission->assignment_id,
                'title' => $submission->assignment->title,
                'brief' => $submission->assignment->brief,
                'checklist' => $submission->assignment->checklist ?? [],
                'maxMarks' => $submission->assignment->max_marks,
                'course' => $submission->assignment->course->title,
            ],
        ]);
    }

    public function evaluate(Request $request, Submission $submission, Activity $activity): RedirectResponse
    {
        $validated = $this->validatedInput($request, [
            'marks' => ['required', 'integer', 'min:0', 'max:'.$submission->assignment->max_marks],
            'feedback' => ['required', 'string', 'max:5000'],
            'status' => ['required', Rule::in(['evaluated', 'returned'])],
        ], [
            'feedback.required' => 'A mark with no feedback teaches nobody anything.',
        ]);

        $submission->forceFill([
            'marks' => $validated['status'] === 'returned' ? null : $validated['marks'],
            'feedback' => $validated['feedback'],
            'status' => $validated['status'],
            'evaluated_by' => $request->user()->id,
            'evaluated_at' => now(),
        ])->save();

        $activity->record($submission->user, 'assignment.marked', $submission->assignment, [
            'title' => $submission->assignment->title,
            'marks' => $submission->marks,
        ], $submission->assignment->course_id, $submission->assignment->batch_id);

        return redirect()
            ->route('admin.marking')
            ->with('success', $validated['status'] === 'returned'
                ? 'Sent back for another go.'
                : 'Marked.');
    }

    public function downloadSubmissionFile(Submission $submission, int $index): StreamedResponse
    {
        $file = ($submission->files ?? [])[$index] ?? null;

        abort_unless($file && Storage::disk('private')->exists($file['path']), 404);

        return Storage::disk('private')->download($file['path'], $file['name']);
    }

    public function attempt(QuizAttempt $attempt): Response
    {
        $attempt->load(['quiz.course:id,title', 'user:id,name', 'answers.question']);

        return Inertia::render('admin/assessment/Attempt', [
            'attempt' => [
                'id' => $attempt->id,
                'student' => $attempt->user->name,
                'quiz' => $attempt->quiz->title,
                'course' => $attempt->quiz->course->title,
                'submittedAt' => $attempt->submitted_at?->format('j M Y, g:i a'),
                'score' => $attempt->score,
                'totalMarks' => $attempt->total_marks,
                'percent' => (float) $attempt->percent,
                'needsReview' => $attempt->needs_review,
            ],
            'answers' => $attempt->answers->map(fn (QuizAnswer $answer) => [
                'id' => $answer->id,
                'question' => $answer->question->body,
                'type' => $answer->question->type,
                'options' => $answer->question->options ?? [],
                'correct' => $answer->question->correct ?? [],
                'response' => $answer->response,
                'isCorrect' => $answer->is_correct,
                'marks' => (float) $answer->marks_awarded,
                'outOf' => $answer->question->marks,
                'feedback' => $answer->feedback,
                'needsMarking' => ! $answer->question->isAutoMarked(),
            ]),
        ]);
    }

    public function markAnswer(Request $request, QuizAttempt $attempt, QuizAnswer $answer, QuizGrader $grader): RedirectResponse
    {
        abort_unless($answer->quiz_attempt_id === $attempt->id, 404);

        $validated = $this->validatedInput($request, [
            'marks' => ['required', 'numeric', 'min:0', 'max:'.$answer->question->marks],
            'feedback' => ['nullable', 'string', 'max:2000'],
        ]);

        $grader->mark($answer, (float) $validated['marks'], $validated['feedback']);

        return back()->with('success', 'Marked.');
    }

    /* ----------------------------------------------------------- helpers */

    /** @return array<string, mixed> */
    protected function quizRules(Request $request): array
    {
        return $this->validatedInput($request, [
            'title' => ['required', 'string', 'max:160'],
            'instructions' => ['nullable', 'string', 'max:2000'],
            'course_module_id' => ['nullable', 'integer'],
            'time_limit_minutes' => ['nullable', 'integer', 'min:1', 'max:600'],
            'attempts_allowed' => ['required', 'integer', 'min:1', 'max:20'],
            'pass_percent' => ['required', 'integer', 'min:1', 'max:100'],
            'shuffle_questions' => ['boolean'],
            'show_answers' => ['boolean'],
            'opens_at' => ['nullable', 'date'],
            'closes_at' => ['nullable', 'date', 'after:opens_at'],
            'is_published' => ['boolean'],
        ], [
            'closes_at.after' => 'A quiz cannot close before it opens.',
        ]);
    }

    /** @return array<string, mixed> */
    protected function questionRules(Request $request): array
    {
        $validated = $this->validatedInput($request, [
            'type' => ['required', Rule::in(Question::TYPES)],
            'body' => ['required', 'string', 'max:2000'],
            'options' => ['array', 'max:10'],
            'options.*' => ['string', 'max:500'],
            'correct' => ['array'],
            'explanation' => ['nullable', 'string', 'max:1000'],
            'marks' => ['required', 'integer', 'min:1', 'max:100'],
        ]);

        // A written answer has no key, and a choice question with no key would
        // mark everybody wrong forever.
        if ($validated['type'] === 'short') {
            $validated['correct'] = null;
            $validated['options'] = null;
        }

        return $validated;
    }

    /** @return array<string, mixed> */
    protected function assignmentRules(Request $request): array
    {
        return $this->validatedInput($request, [
            'title' => ['required', 'string', 'max:160'],
            'brief' => ['required', 'string', 'max:20000'],
            'checklist' => ['array', 'max:20'],
            'checklist.*' => ['string', 'max:200'],
            'course_module_id' => ['nullable', 'integer'],
            'batch_id' => ['nullable', 'integer'],
            'due_at' => ['nullable', 'date'],
            'max_marks' => ['required', 'integer', 'min:1', 'max:1000'],
            'allow_late' => ['boolean'],
            'is_project' => ['boolean'],
            'is_published' => ['boolean'],
        ]);
    }
}
