<?php

namespace App\Http\Controllers\Student;

use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Services\Lms\QuizGrader;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Sitting a quiz.
 *
 * The clock is the server's. The page shows a countdown, but the deadline that
 * counts is the one written onto the attempt when it started, because a timer
 * living only in the browser is a timer the browser can stop.
 *
 * The correct answers never reach the client while an attempt is open.
 */
class QuizController extends StudentController
{
    public function show(Request $request, int $quiz): Response
    {
        $student = $this->student($request);
        $record = $this->readable($request, $quiz);

        $attempts = $record->attempts()
            ->where('user_id', $student->id)
            ->orderByDesc('attempt_number')
            ->get();

        $open = $attempts->firstWhere('submitted_at', null);

        return Inertia::render('student/quizzes/Show', [
            'quiz' => [
                'id' => $record->id,
                'courseId' => $record->course_id,
                'course' => $record->course->title,
                'title' => $record->title,
                'instructions' => $record->instructions,
                'questionCount' => $record->questions()->count(),
                'totalMarks' => $record->totalMarks(),
                'timeLimit' => $record->time_limit_minutes,
                'passPercent' => $record->pass_percent,
                'attemptsAllowed' => $record->attempts_allowed,
                'attemptsUsed' => $attempts->count(),
                'canAttempt' => $record->canBeAttemptedBy($student),
                'closedReason' => $record->closedReason(),
                'showAnswers' => $record->show_answers,
            ],
            'openAttemptId' => $open?->id,
            'attempts' => $attempts->whereNotNull('submitted_at')->values()->map(fn (QuizAttempt $attempt) => [
                'id' => $attempt->id,
                'number' => $attempt->attempt_number,
                'score' => $attempt->score,
                'totalMarks' => $attempt->total_marks,
                'percent' => (float) $attempt->percent,
                'passed' => $attempt->passed,
                'needsReview' => $attempt->needs_review,
                'at' => $attempt->submitted_at->format('j M Y, g:i a'),
            ]),
        ]);
    }

    public function start(Request $request, int $quiz, QuizGrader $grader): RedirectResponse
    {
        $record = $this->readable($request, $quiz);
        $enrolment = $this->enrolment($request, $record->course_id);

        $open = $record->attempts()
            ->where('user_id', $this->student($request)->id)
            ->whereNull('submitted_at')
            ->first();

        if ($open) {
            return redirect()->route('student.quizzes.attempt', [$quiz, $open->id]);
        }

        try {
            $attempt = $grader->start($record, $this->student($request), $enrolment->batch_id);
        } catch (RuntimeException $e) {
            return back()->withErrors(['quiz' => $e->getMessage()]);
        }

        return redirect()->route('student.quizzes.attempt', [$quiz, $attempt->id]);
    }

    public function attempt(Request $request, int $quiz, int $attempt): Response|RedirectResponse
    {
        $record = $this->readable($request, $quiz);

        $open = QuizAttempt::query()
            ->where('quiz_id', $record->id)
            ->where('user_id', $this->student($request)->id)
            ->findOrFail($attempt);

        if (! $open->isOpen()) {
            return redirect()->route('student.quizzes.review', [$quiz, $open->id]);
        }

        $questions = $record->questions()->get();

        if ($record->shuffle_questions) {
            // Seeded on the attempt, so a refresh does not reshuffle and lose
            // somebody's place halfway through.
            $questions = $questions->shuffle($open->id);
        }

        return Inertia::render('student/quizzes/Attempt', [
            'quiz' => [
                'id' => $record->id,
                'title' => $record->title,
                'instructions' => $record->instructions,
                'passPercent' => $record->pass_percent,
            ],
            'attempt' => [
                'id' => $open->id,
                'number' => $open->attempt_number,
                'secondsRemaining' => $open->secondsRemaining(),
                'expiresAt' => $open->expires_at?->toIso8601String(),
            ],
            'questions' => $questions->map(fn ($question) => $question->forAttempt())->values(),
        ]);
    }

    public function submit(Request $request, int $quiz, int $attempt, QuizGrader $grader): RedirectResponse
    {
        $record = $this->readable($request, $quiz);

        $open = QuizAttempt::query()
            ->where('quiz_id', $record->id)
            ->where('user_id', $this->student($request)->id)
            ->findOrFail($attempt);

        $validated = $this->validatedInput($request, [
            'answers' => ['array'],
        ]);

        $grader->submit($open, $validated['answers'] ?? []);

        return redirect()
            ->route('student.quizzes.review', [$quiz, $open->id])
            ->with('success', 'Submitted.');
    }

    public function review(Request $request, int $quiz, int $attempt): Response
    {
        $record = $this->readable($request, $quiz);

        $done = QuizAttempt::query()
            ->where('quiz_id', $record->id)
            ->where('user_id', $this->student($request)->id)
            ->with('answers.question')
            ->findOrFail($attempt);

        abort_if($done->isOpen(), 404);

        return Inertia::render('student/quizzes/Review', [
            'quiz' => [
                'id' => $record->id,
                'courseId' => $record->course_id,
                'title' => $record->title,
                'passPercent' => $record->pass_percent,
                'showAnswers' => $record->show_answers,
            ],
            'attempt' => [
                'id' => $done->id,
                'number' => $done->attempt_number,
                'score' => $done->score,
                'totalMarks' => $done->total_marks,
                'percent' => (float) $done->percent,
                'passed' => $done->passed,
                'needsReview' => $done->needs_review,
                'at' => $done->submitted_at->format('j M Y, g:i a'),
            ],
            // Answers, and the key, only once the attempt is closed and only if
            // the quiz was set to show them.
            'answers' => $record->show_answers
                ? $done->answers->map(fn ($answer) => [
                    'id' => $answer->id,
                    'question' => $answer->question->body,
                    'type' => $answer->question->type,
                    'options' => $answer->question->options ?? [],
                    'response' => $answer->response,
                    'correct' => $answer->question->isAutoMarked() ? $answer->question->correct : null,
                    'isCorrect' => $answer->is_correct,
                    'marks' => (float) $answer->marks_awarded,
                    'outOf' => $answer->question->marks,
                    'explanation' => $answer->question->explanation,
                    'feedback' => $answer->feedback,
                    'awaitingMarking' => ! $answer->question->isAutoMarked() && $answer->feedback === null,
                ])
                : [],
        ]);
    }

    /** A quiz on a course this student is on, or nothing. */
    protected function readable(Request $request, int $quiz): Quiz
    {
        return Quiz::query()
            ->whereIn('course_id', $this->enrolledCourseIds($request))
            ->published()
            ->with('course:id,title')
            ->findOrFail($quiz);
    }
}
