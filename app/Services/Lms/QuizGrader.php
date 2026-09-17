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

    public function __construct(protected Activity $activity) {}

    public function start(Quiz $quiz, User $student, ?int $batchId = null): QuizAttempt
    {
        if (! $quiz->canBeAttemptedBy($student)) {
            throw new RuntimeException($quiz->closedReason() ?? 'No attempts left on this quiz.');
        }

        $number = $quiz->attemptsUsedBy($student) + 1;

        return QuizAttempt::query()->create([
            'quiz_id' => $quiz->id,
            'user_id' => $student->id,
            'batch_id' => $batchId,
            'attempt_number' => $number,
            'started_at' => now(),
            'expires_at' => $quiz->time_limit_minutes
                ? now()->addMinutes($quiz->time_limit_minutes)
                : null,
            'total_marks' => $quiz->totalMarks(),
        ]);
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
        $questions = $quiz->questions()->get()->keyBy('id');

        /*
         * The deadline is the server's. Answers that arrive well past it are
         * dropped and the attempt is marked on what was already stored, which
         * is what makes the time limit a limit rather than a suggestion the
         * page is free to ignore.
         */
        if ($attempt->expires_at
            && now()->greaterThan($attempt->expires_at->copy()->addSeconds(self::GRACE_SECONDS))) {
            $responses = [];
        }

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

            return $attempt->fresh();
        });
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
