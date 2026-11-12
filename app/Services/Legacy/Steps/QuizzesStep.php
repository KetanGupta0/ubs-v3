<?php

namespace App\Services\Legacy\Steps;

use App\Models\Batch;
use App\Models\Question;
use App\Models\Quiz;
use App\Models\QuizAnswer;
use App\Models\QuizAttempt;
use App\Models\QuizAttemptGrant;
use App\Services\Legacy\Context;
use App\Services\Legacy\Ledger;
use Illuminate\Support\Carbon;

/**
 * v2 quizzes, questions, attempts, answers and extra-attempt grants.
 *
 * A quiz stays with the one batch it was set for. Scores are copied, never
 * regraded: a student's result must not change because the grader did. An
 * attempt v2 had not finished marking waits for a person in v3 too. A
 * question asking for a file upload has no v3 equivalent and is listed.
 */
class QuizzesStep extends Step
{
    public const TYPES = ['mcq' => 'mcq', 'multiple' => 'multi', 'truefalse' => 'truefalse', 'short' => 'short'];

    public function name(): string
    {
        return 'quizzes';
    }

    public function sourceTables(): array
    {
        return ['quizzes', 'quiz_questions', 'quiz_attempts', 'quiz_answers', 'quiz_attempt_overrides'];
    }

    public function run(Context $context): void
    {
        $source = $context->source();

        $source->table('quizzes')->orderBy('id')->each(fn (object $row) => $this->quiz($context, $row));
        $source->table('quiz_questions')->orderBy('id')->each(fn (object $row) => $this->question($context, $row));
        $source->table('quiz_attempts')->orderBy('id')->each(fn (object $row) => $this->attempt($context, $row));
        $source->table('quiz_attempt_overrides')->orderBy('id')->each(fn (object $row) => $this->grant($context, $row));
    }

    protected function quiz(Context $context, object $row): void
    {
        $report = $context->report;
        $report->count('quizzes', 'read');
        $batch = Batch::query()->find($context->ledger->targetId('batches', (int) $row->batch_id));

        if (! $batch) {
            $report->count('quizzes', 'skipped');
            $report->warn('quizzes', 'batch_missing', "quizzes#{$row->id}", 'Its batch was not imported; not imported, nor its questions and attempts.');

            return;
        }

        $this->upsert($context, 'quizzes', $row, Quiz::class, fn () => [
            'course_id' => $batch->course_id,
            'batch_id' => $batch->id,
            'title' => $row->title,
            'instructions' => trim(implode("\n\n", array_filter([$row->description, $row->instructions]))) ?: null,
            'time_limit_minutes' => $row->time_limit_minutes,
            'pass_percent' => (int) $row->passing_score,
            'attempts_allowed' => max(1, (int) $row->max_attempts),
            'closes_at' => $row->due_at,
            'show_answers' => (bool) $row->show_answers_after,
            'shuffle_questions' => false,
            'is_published' => (bool) $row->is_published,
        ]);
    }

    protected function question(Context $context, object $row): void
    {
        $report = $context->report;
        $quizId = $context->ledger->targetId('quizzes', (int) $row->quiz_id);

        if (! $quizId) {
            return;
        }

        $report->count('quizzes', 'read');
        $type = self::TYPES[$row->type] ?? null;

        if (! $type) {
            $report->count('quizzes', 'skipped');
            $report->warn('quizzes', 'question_type_unsupported', "quiz_questions#{$row->id}", "A \"{$row->type}\" question has no v3 equivalent; not imported. Set it as an assignment if it is still needed.");

            return;
        }

        $options = collect($this->json($row->options))->map(fn ($option) => (array) $option);
        $expected = (array) ($this->json($row->correct_answer) ?? []);

        [$texts, $correct] = match ($type) {
            'mcq', 'multi' => [
                $options->pluck('text')->map(fn ($text) => (string) $text)->all(),
                $options->filter(fn (array $option) => (bool) ($option['is_correct'] ?? false))->pluck('text')->map(fn ($text) => (string) $text)->values()->all(),
            ],
            'truefalse' => [['True', 'False'], [filter_var($expected[0] ?? null, FILTER_VALIDATE_BOOL) ? 'True' : 'False']],
            default => [null, null],
        };

        $explanation = $row->explanation;

        if ($type === 'short' && $expected) {
            // A written answer is marked by a person; v2's model answer is
            // kept for them, in the explanation.
            $explanation = trim(($explanation ? "{$explanation}\n\n" : '').'Expected answer: '.implode(' / ', array_map('strval', $expected)));
        }

        $this->upsert($context, 'quiz_questions', $row, Question::class, fn () => [
            'quiz_id' => $quizId,
            'type' => $type,
            'body' => $row->question,
            'options' => $texts,
            'correct' => $correct,
            'explanation' => $explanation,
            'marks' => (int) $row->marks,
            'sort_order' => (int) $row->sort_order,
        ]);
    }

    protected function attempt(Context $context, object $row): void
    {
        $report = $context->report;
        $quiz = Quiz::query()->find($context->ledger->targetId('quizzes', (int) $row->quiz_id));
        $userId = $context->ledger->targetId('users', (int) $row->student_id);

        if (! $quiz) {
            return;
        }

        $report->count('quizzes', 'read');

        if (! $userId) {
            $report->count('quizzes', 'skipped');
            $report->warn('quizzes', 'student_missing', "quiz_attempts#{$row->id}", 'Its student was not imported; not imported.');

            return;
        }

        $started = Carbon::parse($row->started_at);
        $percent = (float) $row->percentage;

        $attempt = $this->upsert($context, 'quiz_attempts', $row, QuizAttempt::class, fn () => [
            'quiz_id' => $quiz->id,
            'user_id' => $userId,
            'batch_id' => $quiz->batch_id,
            'attempt_number' => (int) $row->attempt_number,
            'started_at' => $started,
            'expires_at' => $quiz->time_limit_minutes ? $started->copy()->addMinutes($quiz->time_limit_minutes) : null,
            'submitted_at' => $row->submitted_at,
            'score' => (int) round((float) $row->score),
            'total_marks' => (int) round((float) $row->total_marks),
            'percent' => $percent,
            'passed' => (bool) $row->is_graded && $percent >= $quiz->pass_percent,
            'needs_review' => $row->submitted_at !== null && ! $row->is_graded,
        ], fn ($query) => $query->where('quiz_id', $quiz->id)->where('user_id', $userId)->where('attempt_number', (int) $row->attempt_number));

        if (! $attempt) {
            return;
        }

        $context->source()->table('quiz_answers')->where('attempt_id', $row->id)->orderBy('id')->each(function (object $answer) use ($context, $attempt) {
            $question = Question::query()->find($context->ledger->targetId('quiz_questions', (int) $answer->question_id));

            if (! $question) {
                return;
            }

            QuizAnswer::query()->updateOrCreate(
                ['quiz_attempt_id' => $attempt->id, 'question_id' => $question->id],
                [
                    'response' => $this->response($question, $answer->answer),
                    'is_correct' => (bool) $answer->is_correct,
                    'marks_awarded' => (float) $answer->marks_awarded,
                    'feedback' => $answer->grader_note,
                ],
            );
        });
    }

    protected function grant(Context $context, object $row): void
    {
        $quizId = $context->ledger->targetId('quizzes', (int) $row->quiz_id);
        $userId = $context->ledger->targetId('users', (int) $row->student_id);

        if (! $quizId || ! $userId) {
            return;
        }

        $context->report->count('quizzes', 'read');

        $this->upsert($context, 'quiz_attempt_overrides', $row, QuizAttemptGrant::class, fn () => [
            'quiz_id' => $quizId,
            'user_id' => $userId,
            'extra_attempts' => max(1, (int) $row->extra_attempts),
            'reason' => $row->reason ?: 'Granted on the old site; no reason recorded.',
            'granted_by' => $context->ledger->targetId('users', $row->granted_by ? (int) $row->granted_by : null),
            'created_at' => $row->created_at ?? now(),
        ]);
    }

    /**
     * v2 kept an answer as option positions (or "true"/"false"); v3 keeps the
     * option text, so a review shows what the student actually chose.
     */
    protected function response(Question $question, mixed $raw): ?array
    {
        $answer = (array) ($this->json($raw) ?? []);

        return match ($question->type) {
            'mcq', 'multi' => collect($answer)
                ->map(fn ($index) => $question->options[(int) $index] ?? null)
                ->filter()
                ->values()
                ->all(),
            'truefalse' => [filter_var($answer[0] ?? null, FILTER_VALIDATE_BOOL) ? 'True' : 'False'],
            default => array_values(array_map('strval', $answer)),
        };
    }

    /**
     * Create or update the v3 row a v2 row became, skipping it when unchanged.
     *
     * @param  class-string  $model
     */
    protected function upsert(Context $context, string $table, object $row, string $model, \Closure $attributes, ?\Closure $natural = null): mixed
    {
        $report = $context->report;
        $checksum = Ledger::checksum($row);
        $entry = $context->ledger->find($table, (int) $row->id);

        if ($entry && $entry->source_checksum === $checksum) {
            $report->count('quizzes', 'unchanged');

            return null;
        }

        $record = $entry ? $model::query()->find($entry->target_id) : null;
        $record ??= $natural ? $natural($model::query())->first() : null;
        $isNew = $record === null;
        $record ??= new $model;

        $record->forceFill($attributes())->save();
        $context->ledger->record($table, (int) $row->id, $record, $checksum);
        $report->count('quizzes', $isNew ? 'created' : 'updated');

        return $record;
    }

    protected function json(mixed $value): mixed
    {
        return is_string($value) ? json_decode($value, true) : $value;
    }
}
