<?php

namespace App\Services\Lms;

use App\Models\Question;
use App\Models\Quiz;
use App\Models\QuizAnswer;
use App\Models\QuizAttempt;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Starting, grading and closing a quiz attempt.
 *
 * The clock is the server's. An attempt carries an `expires_at` set when it
 * starts, and a submission past that is marked on what was answered in time,
 * because a timer held only in the browser is a timer the browser can stop.
 */
class QuizGrader
{
    /**
     * How late a submission may arrive and still be marked.
     *
     * Latency, a frozen tab and a clock a few seconds out are not cheating,
     * and zeroing somebody for one of them would be. Wide enough to absorb
     * those, narrow enough to be no use to anybody who wants extra time.
     */
    public const GRACE_SECONDS = 60;

    /** The most a single answer adds to the time on a question: a beat, not a sitting. */
    public const MAX_SECONDS_PER_SAVE = 3600;

    public function __construct(
        protected Activity $activity,
        protected AttemptEvents $events,
    ) {}

    /**
     * Start an attempt, and fix its paper: the questions drawn from the pool,
     * their order and each one's option order (§9.1). Fixed on the attempt, so
     * a refresh changes nothing and the marking uses the same paper.
     */
    public function start(Quiz $quiz, User $student, ?int $batchId = null, ?string $deviceHash = null, ?string $ip = null): QuizAttempt
    {
        if (! $quiz->canBeAttemptedBy($student)) {
            throw new RuntimeException($quiz->closedReason() ?? 'No attempts left on this quiz.');
        }

        $number = $quiz->attemptsUsedBy($student) + 1;
        $questions = $quiz->questions()->get();

        if ($quiz->pool_size && $quiz->pool_size < $questions->count()) {
            $questions = $questions->shuffle()->take($quiz->pool_size)->sortBy('sort_order')->values();
        }

        if ($quiz->shuffle_questions) {
            $questions = $questions->shuffle()->values();
        }

        $orders = $quiz->shuffle_options
            ? $questions->filter(fn (Question $question) => in_array($question->type, ['mcq', 'multi'], true) && count($question->options ?? []) > 1)
                ->mapWithKeys(fn (Question $question) => [(string) $question->id => collect(array_keys($question->options))->shuffle()->values()->all()])
                ->all()
            : null;

        $attempt = QuizAttempt::query()->create([
            'quiz_id' => $quiz->id,
            'user_id' => $student->id,
            'batch_id' => $batchId,
            'attempt_number' => $number,
            'question_ids' => $questions->pluck('id')->all(),
            'option_orders' => $orders ?: null,
            'device_hash' => $deviceHash,
            'last_ip' => $ip,
            'started_at' => now(),
            'expires_at' => $quiz->time_limit_minutes
                ? now()->addMinutes($quiz->time_limit_minutes)
                : null,
            'total_marks' => (int) $questions->sum('marks'),
        ]);

        $this->events->record($attempt, 'started', ip: $ip);

        return $attempt;
    }

    /** Whether the deadline, with its grace, has gone. */
    public function pastDeadline(QuizAttempt $attempt): bool
    {
        return $attempt->expires_at !== null
            && now()->greaterThan($attempt->expires_at->copy()->addSeconds(self::GRACE_SECONDS));
    }

    /**
     * Keep one answer as it is given, with the time spent on it (§9.1).
     *
     * Refused after the deadline, and, on a quiz with no going back, for a
     * question the student has already moved past.
     */
    public function saveAnswer(QuizAttempt $attempt, int $questionId, mixed $response, int $seconds, ?string $ip = null): void
    {
        if (! $attempt->isOpen() || $this->pastDeadline($attempt)) {
            throw new RuntimeException('This attempt is closed.');
        }

        $paper = $attempt->paper();
        $index = $paper->search(fn (Question $question) => $question->id === $questionId);

        if ($index === false) {
            throw new RuntimeException('That question is not on this paper.');
        }

        if ($attempt->quiz->one_at_a_time && ! $attempt->quiz->allow_back && $index !== $attempt->current_index) {
            throw new RuntimeException('That question is no longer open.');
        }

        $seconds = max(0, min($seconds, self::MAX_SECONDS_PER_SAVE));
        $answer = QuizAnswer::query()->firstOrNew(['quiz_attempt_id' => $attempt->id, 'question_id' => $questionId]);

        $answer->forceFill([
            'response' => $response === null || $response === '' ? null : (array) $response,
            'seconds_spent' => (int) $answer->seconds_spent + $seconds,
            'answered_at' => now(),
        ])->save();

        $this->events->record($attempt, 'answered', $questionId, $seconds, ip: $ip);
    }

    /** On a one-at-a-time quiz, move on to the next question. */
    public function advance(QuizAttempt $attempt): void
    {
        $last = max(0, count($attempt->question_ids ?? []) - 1);

        if ($attempt->current_index < $last) {
            $attempt->forceFill(['current_index' => $attempt->current_index + 1])->save();
        }
    }

    /**
     * Mark what was answered.
     *
     * @param  array<int, mixed>  $responses  question id => answer
     */
    public function submit(QuizAttempt $attempt, array $responses): QuizAttempt
    {
        if (! $attempt->isOpen()) {
            return $attempt;
        }

        $quiz = $attempt->quiz;
        $questions = $attempt->paper()->keyBy('id');

        /*
         * The deadline is the server's. Answers that arrive well past it are
         * dropped and the attempt is marked on what was already stored, which
         * is what makes the time limit a limit rather than a suggestion the
         * page is free to ignore.
         */
        if ($this->pastDeadline($attempt)) {
            $responses = [];
        }

        // What was kept as the student went, overridden by what arrived now,
        // except where a quiz with no going back has already closed a question.
        $stored = $attempt->answers()->get()->mapWithKeys(fn (QuizAnswer $answer) => [$answer->question_id => $this->unwrap($answer->response)]);
        $locked = $quiz->one_at_a_time && ! $quiz->allow_back;
        $current = $attempt->paper()->get($attempt->current_index)?->id;

        foreach ($responses as $questionId => $response) {
            if (! $locked || (int) $questionId === $current) {
                $stored[(int) $questionId] = $response;
            }
        }

        $responses = $stored->all();

        return DB::transaction(function () use ($attempt, $quiz, $questions, $responses) {
            $score = 0.0;
            $needsReview = false;

            foreach ($questions as $question) {
                $response = $responses[$question->id] ?? null;

                if ($question->isAutoMarked()) {
                    $correct = $this->isCorrect($question, $response);
                    $awarded = $correct ? $question->marks : 0;
                    $score += $awarded;
                } else {
                    // A written answer waits for a person. Awarding zero now
                    // and correcting later would show the student a fail they
                    // did not earn.
                    $correct = false;
                    $awarded = 0;
                    $needsReview = $needsReview || filled($response);
                }

                QuizAnswer::query()->updateOrCreate(
                    ['quiz_attempt_id' => $attempt->id, 'question_id' => $question->id],
                    [
                        'response' => $response === null ? null : (array) $response,
                        'is_correct' => $correct,
                        'marks_awarded' => $awarded,
                    ],
                );
            }

            $total = max(1, (int) $questions->sum('marks'));
            $percent = round($score / $total * 100, 2);

            $attempt->forceFill([
                'submitted_at' => now(),
                'score' => (int) round($score),
                'total_marks' => $total,
                'percent' => $percent,
                'passed' => ! $needsReview && $percent >= $quiz->pass_percent,
                'needs_review' => $needsReview,
            ])->save();

            if (! $needsReview) {
                $this->awardFor($attempt->fresh());
            }

            $this->activity->record($attempt->user, 'quiz.submitted', $quiz, [
                'title' => $quiz->title,
                'percent' => (int) round($percent),
            ], $quiz->course_id, $attempt->batch_id);

            $this->events->record($attempt, $this->pastDeadline($attempt) ? 'auto_submitted' : 'submitted', ip: request()?->ip());

            return $attempt->fresh();
        });
    }

    /** A stored response as the page sent it: a single choice is kept as a one item list. */
    protected function unwrap(mixed $response): mixed
    {
        return is_array($response) && count($response) === 1 && array_is_list($response) ? $response[0] : $response;
    }

    /** A written answer, marked by a person. */
    public function mark(QuizAnswer $answer, float $marks, ?string $feedback = null): QuizAttempt
    {
        $answer->forceFill([
            'marks_awarded' => $marks,
            'is_correct' => $marks > 0,
            'feedback' => $feedback,
        ])->save();

        return $this->retotal($answer->attempt);
    }

    /** Recompute an attempt from its answers, after a person has marked one. */
    public function retotal(QuizAttempt $attempt): QuizAttempt
    {
        $score = (float) $attempt->answers()->sum('marks_awarded');
        $total = max(1, $attempt->total_marks);
        $percent = round($score / $total * 100, 2);

        $stillWaiting = $attempt->answers()
            ->whereHas('question', fn ($query) => $query->where('type', 'short'))
            ->where('marks_awarded', 0)
            ->whereNull('feedback')
            ->exists();

        $attempt->forceFill([
            'score' => (int) round($score),
            'percent' => $percent,
            'passed' => ! $stillWaiting && $percent >= $attempt->quiz->pass_percent,
            'needs_review' => $stillWaiting,
        ])->save();

        if (! $stillWaiting) {
            $this->awardFor($attempt->fresh());
        }

        return $attempt->fresh();
    }

    /** Close anything whose time ran out and that nobody submitted. */
    public function closeExpired(): int
    {
        $closed = 0;

        QuizAttempt::query()
            ->whereNull('submitted_at')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->with('quiz.questions')
            ->each(function (QuizAttempt $attempt) use (&$closed) {
                $this->submit($attempt, []);
                $closed++;
            });

        return $closed;
    }

    protected function awardFor(QuizAttempt $attempt): void
    {
        if (! $attempt->passed) {
            return;
        }

        $this->activity->award(
            $attempt->user,
            'quiz.passed',
            $attempt->quiz,
            courseId: $attempt->quiz->course_id,
            batchId: $attempt->batch_id,
        );

        if ($attempt->percent >= 90) {
            $this->activity->award(
                $attempt->user,
                'quiz.distinction',
                $attempt->quiz,
                courseId: $attempt->quiz->course_id,
                batchId: $attempt->batch_id,
            );
        }
    }

    /**
     * Compare an answer against the stored key.
     *
     * Multiple choice is order independent and exact: picking three of four
     * right answers is not partly right, it is wrong, and saying otherwise
     * teaches somebody to tick everything.
     */
    protected function isCorrect(Question $question, mixed $response): bool
    {
        $correct = collect($question->correct ?? []);

        if ($correct->isEmpty() || $response === null) {
            return false;
        }

        return match ($question->type) {
            'multi' => collect((array) $response)->map(fn ($v) => (string) $v)->sort()->values()->all()
                === $correct->map(fn ($v) => (string) $v)->sort()->values()->all(),

            'truefalse' => filter_var(is_array($response) ? ($response[0] ?? null) : $response, FILTER_VALIDATE_BOOL)
                === filter_var($correct->first(), FILTER_VALIDATE_BOOL),

            default => (string) (is_array($response) ? ($response[0] ?? '') : $response)
                === (string) $correct->first(),
        };
    }
}
