<?php

namespace App\Http\Controllers\Student;

use App\Models\Question;
use App\Models\Quiz;
use App\Models\QuizAnswer;
use App\Models\QuizAttempt;
use App\Services\Lms\AttemptEvents;
use App\Services\Lms\IntegrityDecisions;
use App\Services\Lms\Proctoring;
use App\Services\Lms\QuizGrader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Sitting a quiz.
 *
 * The clock is the server's. The page shows a countdown, but the deadline that
 * counts is the one written onto the attempt when it started, because a timer
 * living only in the browser is a timer the browser can stop.
 *
 * The correct answers never reach the client while an attempt is open.
 *
 * A proctored quiz (§9.1a) adds its own rules through `Proctoring`: a sitting
 * open for the student's batch, their agreement, a laptop or computer, and Safe
 * Exam Browser on every request where the quiz requires it.
 */
class QuizController extends StudentController
{
    protected const DEVICE_COOKIE = 'quiz_device';

    protected const ELSEWHERE = 'This attempt carried on in another browser.';

    protected const NEEDS_SEB = 'This quiz is taken in Safe Exam Browser. Open it from there.';

    public function __construct(protected Proctoring $proctoring) {}

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
                'questionCount' => $record->pool_size && $record->pool_size < $record->questions()->count() ? $record->pool_size : $record->questions()->count(),
                'totalMarks' => $record->totalMarks(),
                'timeLimit' => $record->time_limit_minutes,
                'passPercent' => $record->pass_percent,
                'attemptsAllowed' => $record->attemptsAllowedFor($this->student($request)),
                'attemptsUsed' => $attempts->count(),
                'canAttempt' => $record->canBeAttemptedBy($student),
                'closedReason' => $record->closedReason(),
                'showAnswers' => $record->show_answers,
                'rules' => $record->rulesForStudents(),
            ],
            'proctoring' => $record->isProctored() ? $this->proctoringFor($request, $record) : null,
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
                'voided' => $attempt->isVoided(),
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

        if ($record->isProctored()) {
            if ($reason = $this->proctoring->refusal($record, $enrolment, $request)) {
                return back()->withErrors(['quiz' => $reason]);
            }

            // What they are agreeing to was on the page above the button.
            $this->validatedInput($request, ['agree' => ['accepted']], [
                'agree.accepted' => 'Tick that you have read how this quiz is proctored, to start.',
            ]);
        }

        try {
            $attempt = $record->isProctored()
                ? $this->proctoring->begin($record, $this->student($request), $enrolment, $this->device($request), $request->ip())
                : $grader->start($record, $this->student($request), $enrolment->batch_id, $this->device($request), $request->ip());
        } catch (RuntimeException $e) {
            return back()->withErrors(['quiz' => $e->getMessage()]);
        }

        return redirect()->route('student.quizzes.attempt', [$quiz, $attempt->id]);
    }

    public function attempt(Request $request, int $quiz, int $attempt, AttemptEvents $events): Response|RedirectResponse
    {
        $record = $this->readable($request, $quiz);
        $open = $this->ownAttempt($request, $record, $attempt);

        if (! $open->isOpen()) {
            return redirect()->route('student.quizzes.review', [$quiz, $open->id]);
        }

        if ($this->sebMissing($request, $record, $open)) {
            return redirect()->route('student.quizzes.show', $quiz)->with('error', self::NEEDS_SEB);
        }

        $paper = $open->paper();

        // An attempt from before papers were fixed: shuffled on its id, as it was.
        if (empty($open->question_ids) && $record->shuffle_questions) {
            $paper = $paper->shuffle($open->id)->values();
        }

        // Open in another browser: say so, rather than run two at once (§9.1).
        $elsewhere = ! $this->inThisBrowser($request, $open);

        if (! $elsewhere) {
            $events->notice($open, $request->ip(), null);
        }

        $locked = $record->one_at_a_time && ! $record->allow_back;
        $shown = $locked ? $paper->slice($open->current_index, 1) : $paper;
        $saved = $open->answers()->whereIn('question_id', $shown->pluck('id'))->get()
            ->mapWithKeys(fn (QuizAnswer $answer) => [$answer->question_id => $this->forPage($answer)]);

        return Inertia::render('student/quizzes/Attempt', [
            'quiz' => [
                'id' => $record->id,
                'title' => $record->title,
                'instructions' => $record->instructions,
                'passPercent' => $record->pass_percent,
                'oneAtATime' => $record->one_at_a_time,
                'allowBack' => $record->allow_back,
            ],
            'attempt' => [
                'id' => $open->id,
                'number' => $open->attempt_number,
                'secondsRemaining' => $open->secondsRemaining(),
                'expiresAt' => $open->expires_at?->toIso8601String(),
                'currentIndex' => $open->current_index,
                'total' => $paper->count(),
                'elsewhere' => $elsewhere,
            ],
            // How this attempt is watched, fixed when it started (§9.1a).
            'proctoring' => $open->isProctored() ? [
                'mode' => $open->proctoring,
                'maxExits' => $record->max_exits,
                // Safe Exam Browser keeps the window full screen by itself.
                'inSeb' => (bool) $record->require_seb,
                'exits' => $open->events()->where('type', 'focus_lost')->count(),
                'messages' => $elsewhere ? [] : $this->proctoring->unreadMessages($open),
            ] : null,
            'questions' => $elsewhere ? [] : $shown->map(fn (Question $question) => $question->forAttempt($open->optionOrderFor($question)))->values(),
            'saved' => $elsewhere ? (object) [] : (object) $saved->all(),
        ]);
    }

    /** Carry on in this browser: noted on the attempt, then the questions again. */
    public function here(Request $request, int $quiz, int $attempt, AttemptEvents $events): RedirectResponse
    {
        $record = $this->readable($request, $quiz);
        $open = $this->ownAttempt($request, $record, $attempt);

        if ($this->sebMissing($request, $record, $open)) {
            return redirect()->route('student.quizzes.show', $quiz)->with('error', self::NEEDS_SEB);
        }

        // A proctored quiz moves to another computer, never to a phone.
        if ($open->isProctored() && $this->proctoring->looksLikePhone($request)) {
            return back()->with('error', 'This quiz is sat on a laptop or computer, not on a phone or tablet.');
        }

        if ($open->isOpen()) {
            $events->notice($open, $request->ip(), $this->device($request));
            $this->proctoring->announce($open);
        }

        return redirect()->route('student.quizzes.attempt', [$quiz, $open->id]);
    }

    /** Keep one answer as it is given, with the seconds spent on it since the last save. */
    public function saveAnswer(Request $request, int $quiz, int $attempt, QuizGrader $grader): JsonResponse
    {
        $record = $this->readable($request, $quiz);
        $open = $this->ownAttempt($request, $record, $attempt);
        $validated = $this->answerInput($request);

        if ($this->sebMissing($request, $record, $open)) {
            return response()->json(['message' => self::NEEDS_SEB], 403);
        }

        if (! $this->inThisBrowser($request, $open)) {
            return response()->json(['message' => self::ELSEWHERE], 409);
        }

        try {
            $grader->saveAnswer($open, (int) $validated['question_id'], $validated['response'], (int) ($validated['seconds'] ?? 0), $request->ip());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $this->proctoring->announce($open);

        return response()->json(['saved' => true]);
    }

    /** On a one-at-a-time quiz: keep this answer and show the next question. */
    public function next(Request $request, int $quiz, int $attempt, QuizGrader $grader): RedirectResponse
    {
        $record = $this->readable($request, $quiz);
        $open = $this->ownAttempt($request, $record, $attempt);
        $validated = $this->answerInput($request);

        if ($this->sebMissing($request, $record, $open)) {
            return redirect()->route('student.quizzes.show', $quiz)->with('error', self::NEEDS_SEB);
        }

        if (! $this->inThisBrowser($request, $open)) {
            return redirect()->route('student.quizzes.attempt', [$quiz, $open->id])->with('error', self::ELSEWHERE);
        }

        try {
            $grader->saveAnswer($open, (int) $validated['question_id'], $validated['response'], (int) ($validated['seconds'] ?? 0), $request->ip());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        $grader->advance($open);
        $this->proctoring->announce($open);

        return redirect()->route('student.quizzes.attempt', [$quiz, $open->id]);
    }

    /**
     * What the browser saw: leaving the quiz, pasting, copying (§9.1, layer 2).
     * On a proctored attempt this is also its heartbeat: it says whether the
     * page is in full screen and in focus, and hears back the proctor's
     * messages, the clock, and whether the attempt has been ended.
     */
    public function events(Request $request, int $quiz, int $attempt, AttemptEvents $events): JsonResponse
    {
        $record = $this->readable($request, $quiz);
        $open = $this->ownAttempt($request, $record, $attempt);

        $validated = $this->validatedInput($request, [
            'events' => ['present', 'array', 'max:'.AttemptEvents::PER_REPORT],
            'state' => ['nullable', 'array'],
        ]);

        if ($this->sebMissing($request, $record, $open)) {
            return response()->json(['message' => self::NEEDS_SEB], 403);
        }

        $here = $open->isOpen() && $this->inThisBrowser($request, $open);
        $kept = $here ? $events->fromBrowser($open, $validated['events'] ?? [], $request->ip()) : 0;

        if ($here && $open->isProctored()) {
            $changed = $this->proctoring->heard($open, $validated['state'] ?? [], $kept);

            if (! $this->proctoring->enforceExits($open) && $changed) {
                $this->proctoring->announce($open);
            }
        }

        $open->refresh();

        return response()->json([
            'kept' => $kept,
            'ended' => ! $open->isOpen(),
            'review' => route('student.quizzes.review', [$quiz, $open->id], false),
            'secondsRemaining' => $open->secondsRemaining(),
            'exits' => $open->isProctored() ? $open->events()->where('type', 'focus_lost')->count() : null,
            'messages' => $here && $open->isProctored() ? $this->proctoring->unreadMessages($open) : [],
        ]);
    }

    public function submit(Request $request, int $quiz, int $attempt, QuizGrader $grader): RedirectResponse
    {
        $record = $this->readable($request, $quiz);
        $open = $this->ownAttempt($request, $record, $attempt);

        if ($open->isOpen() && $this->sebMissing($request, $record, $open)) {
            return redirect()->route('student.quizzes.show', $quiz)->with('error', self::NEEDS_SEB);
        }

        // Carried on in another browser: submitted from there, not from here.
        if ($open->isOpen() && ! $this->inThisBrowser($request, $open)) {
            return redirect()->route('student.quizzes.attempt', [$quiz, $open->id])->with('error', self::ELSEWHERE);
        }

        $validated = $this->validatedInput($request, [
            'answers' => ['array'],
        ]);

        $grader->submit($open, $validated['answers'] ?? []);
        $this->proctoring->announce($open->fresh());

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

        // What a trainer decided, once the student has been told: the outcome
        // and the reason, never the note (§9.1, layer 4).
        $decision = $done->decision()->whereNotNull('told_at')->first();

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
                // Ended by the proctor, or for leaving too often: said plainly.
                'endedReason' => $done->ended_reason,
                'endedByProctor' => $done->ended_by !== null,
                'voided' => $done->isVoided(),
            ],
            'integrity' => $decision ? [
                'outcome' => $decision->outcome,
                'reason' => $decision->reason,
                'resit' => $decision->resit,
                'at' => $decision->decided_at->format('j M Y'),
                'awaitsReply' => $decision->awaitsReply(),
                'reply' => $decision->reply,
                'final' => $decision->isFinal(),
            ] : null,
            // Answers, and the key, only once the attempt is closed, only if the
            // quiz was set to show them, and, where it waits for everybody, only
            // once it has closed for everybody (§9.1).
            'answersWaitForClose' => $record->show_answers && ! $record->answersVisible(),
            'closesAt' => $record->closes_at?->format('j M, g:i a'),
            'answers' => $record->answersVisible()
                ? $this->inPaperOrder($done)->map(fn ($answer) => [
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

    /** The one reply a student may make to what a trainer decided, before it is final. */
    public function reply(Request $request, int $quiz, int $attempt, IntegrityDecisions $decisions): RedirectResponse
    {
        $record = $this->readable($request, $quiz);
        $done = $this->ownAttempt($request, $record, $attempt);
        $decision = $done->decision()->whereNotNull('told_at')->first();

        abort_unless($decision !== null, 404);

        $validated = $this->validatedInput($request, ['reply' => ['required', 'string', 'max:2000']], [
            'reply.required' => 'Write what you would like your trainer to know.',
        ]);

        try {
            $decisions->reply($decision, $this->student($request), trim($validated['reply']));
        } catch (RuntimeException $e) {
            return back()->withErrors(['reply' => $e->getMessage()]);
        }

        return back()->with('success', 'Sent. Your trainer reads it before the decision is final.');
    }

    /**
     * The settings file that opens this quiz in Safe Exam Browser (X10). The
     * browser fetches it on its own, signed out, so the link is signed instead
     * and lasts a few hours; the file holds settings, never anybody's details.
     */
    public function safeExamBrowserFile(int $quiz): StreamedResponse
    {
        $record = Quiz::query()->published()->where('require_seb', true)->whereNotNull('seb_config_path')->findOrFail($quiz);

        abort_unless(Storage::disk('local')->exists($record->seb_config_path), 404);

        return Storage::disk('local')->download($record->seb_config_path, Str::slug($record->title).'.seb', [
            'Content-Type' => 'application/seb',
        ]);
    }

    /**
     * What the quiz page says about a proctored quiz: the sitting, whether it
     * can be started from here and why not, and Safe Exam Browser.
     *
     * @return array<string, mixed>
     */
    protected function proctoringFor(Request $request, Quiz $quiz): array
    {
        $enrolment = $this->enrolment($request, $quiz->course_id);
        $slot = $quiz->nextSlotFor($enrolment->batch_id);
        $launch = null;

        if ($quiz->require_seb && $quiz->seb_config_path) {
            $signed = URL::temporarySignedRoute('quizzes.safe-exam-browser', now()->addHours(6), ['quiz' => $quiz->id]);
            $launch = preg_replace('#^http(s?)://#', 'seb$1://', $signed);
        }

        return [
            'mode' => $quiz->proctoring,
            'maxExits' => $quiz->max_exits,
            'slot' => $slot ? ['window' => $slot->window(), 'open' => $slot->isOpen(), 'note' => $slot->note] : null,
            'refusal' => $this->proctoring->refusal($quiz, $enrolment, $request),
            'requireSeb' => $quiz->require_seb,
            'inSeb' => $quiz->require_seb && $this->proctoring->inSafeExamBrowser($quiz, $request),
            'sebLaunchUrl' => $launch,
        ];
    }

    /** Safe Exam Browser is required for this attempt's quiz and this request is not from it. */
    protected function sebMissing(Request $request, Quiz $quiz, QuizAttempt $attempt): bool
    {
        return $attempt->isProctored() && $quiz->require_seb && ! $this->proctoring->inSafeExamBrowser($quiz, $request);
    }

    /** The answers in the order the paper was set, which is the order the student saw. */
    protected function inPaperOrder(QuizAttempt $attempt): Collection
    {
        $position = array_flip(array_map('intval', $attempt->question_ids ?? []));

        return $attempt->answers
            ->filter(fn (QuizAnswer $answer) => $answer->question !== null)
            ->sortBy(fn (QuizAnswer $answer) => [$position[$answer->question_id] ?? PHP_INT_MAX, $answer->question->sort_order, $answer->question_id])
            ->values();
    }

    protected function ownAttempt(Request $request, Quiz $quiz, int $attempt): QuizAttempt
    {
        return QuizAttempt::query()
            ->where('quiz_id', $quiz->id)
            ->where('user_id', $this->student($request)->id)
            ->findOrFail($attempt);
    }

    /** @return array<string, mixed> */
    protected function answerInput(Request $request): array
    {
        return $this->validatedInput($request, [
            'question_id' => ['required', 'integer'],
            'response' => ['nullable'],
            'seconds' => ['nullable', 'integer', 'min:0'],
        ]);
    }

    /** A kept answer, in the shape the page holds it. */
    protected function forPage(QuizAnswer $answer): mixed
    {
        $response = $answer->response;

        if (! is_array($response)) {
            return $response;
        }

        return $answer->question?->type === 'multi' ? array_values(array_map('strval', $response)) : (string) ($response[0] ?? '');
    }

    /**
     * Which browser this is, as far as an attempt is concerned: a random token
     * in a cookie of its own, so signing out and in again in the same browser
     * is still the same browser. Only its hash is kept on the attempt.
     */
    protected function device(Request $request): string
    {
        $usable = fn ($token) => is_string($token) && strlen($token) === 40;
        $token = $request->attributes->get(self::DEVICE_COOKIE);

        if (! $usable($token)) {
            // The cookie outlives signing out; the session covers a browser
            // that refuses the cookie.
            $cookie = $request->cookie(self::DEVICE_COOKIE);
            $token = $usable($cookie) ? $cookie : $request->session()->get(self::DEVICE_COOKIE);
            $token = $usable($token) ? $token : Str::random(40);

            if ($cookie !== $token) {
                Cookie::queue(Cookie::make(self::DEVICE_COOKIE, $token, 60 * 24 * 365, null, null, null, true, false, 'lax'));
            }

            $request->session()->put(self::DEVICE_COOKIE, $token);
            $request->attributes->set(self::DEVICE_COOKIE, $token);
        }

        return hash('sha256', $token);
    }

    /** Whether this is the browser the attempt is in. Attempts from before browsers were noted have none. */
    protected function inThisBrowser(Request $request, QuizAttempt $attempt): bool
    {
        return $attempt->device_hash === null || hash_equals($attempt->device_hash, $this->device($request));
    }

    /** A quiz on a course this student is on, or nothing. */
    protected function readable(Request $request, int $quiz): Quiz
    {
        return Quiz::query()
            ->visibleTo($this->student($request))
            ->published()
            ->with('course:id,title')
            ->findOrFail($quiz);
    }
}
