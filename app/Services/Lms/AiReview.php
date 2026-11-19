<?php

namespace App\Services\Lms;

use App\Models\Question;
use App\Models\Quiz;
use App\Models\QuizAiReview;
use App\Models\QuizAnswer;
use App\Models\QuizAttempt;
use App\Models\QuizAttemptEvent;
use App\Models\User;
use App\Services\Ai\ClaudeBatches;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * An AI's reading of a flagged quiz attempt (§9.1, layer 3).
 *
 * Only attempts the checks flagged, or that a trainer asks about, are sent,
 * after the quiz has closed, through the Message Batches API at half price,
 * because nothing waits on it. What is sent names nobody: the student under
 * review is "Student A", others in the batch "Student B" and on, and no
 * name, address, email or number goes. What comes back is advice for a
 * person: a risk level, evidence pointing at something specific, and the
 * innocent explanations considered. It never changes a score, decides
 * anything or reaches the student, and it never says an answer was written
 * by an AI.
 */
class AiReview
{
    public const SYSTEM = <<<'PROMPT'
You help a trainer at a training institute review one quiz attempt that automatic checks flagged, to decide whether it deserves a closer look for cheating. You decide nothing: a person does, and the student hears from them, not from you.

You are given, as JSON: the quiz and how it was set; the paper with each answer, whether it was marked right, the seconds spent on each question and when it was answered; a timeline of the attempt in seconds from the start; the flags the automatic checks raised, with their own summaries; and, where they bear on the flags, answers other students in the same batch gave. Students are labelled: Student A is the one under review. You are never told who anybody is, and you must not guess.

How to judge:
- Point at specific things: a question number, a moment in the timeline, a comparison with a labelled student. Something that cannot point at the data is not evidence.
- Weigh the innocent explanations properly, and list the ones you considered: a phone notification or somebody at home interrupting; a slow, dropped or shared connection; a family or hostel sharing one network; a student who prepared well answering quickly; two students who learned from the same notes giving the same right answer; a page reloaded by mistake.
- Compare written answers by meaning as well as wording. The same idea in the same order with the words changed is worth noting. The same correct idea in different words is normal.
- Never say or suggest that an answer was written by an AI or a tool. Nobody can tell that reliably, and saying it would accuse honest students. A pasted block and its timing are evidence of pasting, nothing more.
- Leaving the page, pasting and copying are reported by the student's browser and can be missing; their absence proves nothing.
- Risk: "none" when the flags have an ordinary reading and nothing else stands out; "low" when something is unusual but most likely innocent; "medium" when there is a pattern a trainer should ask the student about; "high" only when several independent pieces of evidence point the same way.
- Write for the trainer: plain, short and specific. The summary is two or three sentences.
PROMPT;

    /** What the answer must look like; the API holds the model to it. */
    public const SCHEMA = [
        'type' => 'object',
        'properties' => [
            'risk' => ['type' => 'string', 'enum' => QuizAiReview::RISKS],
            'summary' => ['type' => 'string'],
            'evidence' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'points_at' => ['type' => 'string'],
                        'what' => ['type' => 'string'],
                    ],
                    'required' => ['points_at', 'what'],
                    'additionalProperties' => false,
                ],
            ],
            'innocent_explanations' => ['type' => 'array', 'items' => ['type' => 'string']],
        ],
        'required' => ['risk', 'summary', 'evidence', 'innocent_explanations'],
        'additionalProperties' => false,
    ];

    /** The longest written answer sent, in characters. */
    protected const MAX_TEXT = 2000;

    /** Other students' answers sent for each written question, at most. */
    protected const OTHERS_PER_QUESTION = 5;

    public function __construct(
        protected ClaudeBatches $batches,
        protected IntegrityChecks $checks,
    ) {}

    public function available(): bool
    {
        return $this->batches->configured();
    }

    /** Ask about one attempt, unless it is already waiting for an answer. */
    public function queue(QuizAttempt $attempt, ?User $by = null): ?QuizAiReview
    {
        if (! $this->available() || $attempt->submitted_at === null) {
            return null;
        }

        if ($attempt->aiReviews()->whereIn('status', ['queued', 'sent'])->exists()) {
            return null;
        }

        return $attempt->aiReviews()->create(['status' => 'queued', 'requested_by' => $by?->id]);
    }

    /** Every flagged attempt of a quiz that has not been read yet. */
    public function queueFlagged(Quiz $quiz): int
    {
        if (! $this->available()) {
            return 0;
        }

        $queued = 0;

        $quiz->attempts()
            ->whereNotNull('submitted_at')
            ->whereNotNull('flagged_at')
            ->whereDoesntHave('aiReviews', fn ($query) => $query->whereIn('status', ['queued', 'sent', 'done']))
            ->each(function (QuizAttempt $attempt) use (&$queued) {
                $queued += $this->queue($attempt) ? 1 : 0;
            });

        return $queued;
    }

    /** Send what is waiting, as one batch. Returns how many went. */
    public function send(): int
    {
        if (! $this->available()) {
            return 0;
        }

        $reviews = QuizAiReview::query()
            ->where('status', 'queued')
            ->with(['attempt.quiz', 'attempt.answers', 'attempt.events', 'attempt.user:id,name'])
            ->orderBy('id')
            ->limit((int) config('company.ai.review_batch_size', 100))
            ->get();

        if ($reviews->isEmpty()) {
            return 0;
        }

        $model = config('company.ai.review_model');
        $requests = $reviews->map(fn (QuizAiReview $review) => [
            'customID' => 'review-'.$review->id,
            'params' => [
                'model' => $model,
                'maxTokens' => 16000,
                'system' => self::SYSTEM,
                'messages' => [[
                    'role' => 'user',
                    'content' => json_encode($this->payload($review->attempt), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]],
                'outputConfig' => [
                    'effort' => config('company.ai.review_effort', 'high'),
                    'format' => ['type' => 'json_schema', 'schema' => self::SCHEMA],
                ],
            ],
        ])->values()->all();

        try {
            $batchId = $this->batches->create($requests);
        } catch (Throwable $e) {
            // Left queued: the next run tries again.
            Log::warning('Sending quiz attempts for AI review failed; they stay queued.', ['error' => $e->getMessage()]);
            QuizAiReview::query()->whereKey($reviews->modelKeys())->update(['error' => Str::limit($e->getMessage(), 290)]);

            return 0;
        }

        QuizAiReview::query()->whereKey($reviews->modelKeys())->update([
            'status' => 'sent',
            'batch_id' => $batchId,
            'model' => $model,
            'sent_at' => now(),
            'error' => null,
        ]);

        return $reviews->count();
    }

    /** Read back every batch that has ended. Returns how many reviews finished. */
    public function collect(): int
    {
        if (! $this->available()) {
            return 0;
        }

        $finished = 0;

        foreach (QuizAiReview::query()->where('status', 'sent')->distinct()->pluck('batch_id')->filter() as $batchId) {
            try {
                if (! $this->batches->ended($batchId)) {
                    continue;
                }

                $waiting = QuizAiReview::query()->where('batch_id', $batchId)->where('status', 'sent')->get()->keyBy(fn (QuizAiReview $review) => 'review-'.$review->id);

                foreach ($this->batches->results($batchId) as $customId => $outcome) {
                    if ($review = $waiting->pull($customId)) {
                        $this->record($review, $outcome);
                        $finished++;
                    }
                }

                // Anything the batch did not answer for is asked again.
                $waiting->each(fn (QuizAiReview $review) => $review->forceFill(['status' => 'queued', 'batch_id' => null, 'error' => 'No answer came back; asked again.'])->save());
            } catch (Throwable $e) {
                Log::warning('Reading an AI review batch failed; it is read again next time.', ['batch' => $batchId, 'error' => $e->getMessage()]);
            }
        }

        return $finished;
    }

    /**
     * Everything the AI is told about one attempt, with nobody named.
     *
     * @return array<string, mixed>
     */
    public function payload(QuizAttempt $attempt): array
    {
        $quiz = $attempt->quiz;
        $paper = $attempt->paper()->values();
        $answers = $attempt->answers->keyBy('question_id');
        $number = $paper->mapWithKeys(fn (Question $question, int $index) => [$question->id => $index + 1]);
        $since = fn ($time) => $time && $attempt->started_at ? (int) round($attempt->started_at->diffInSeconds($time, false)) : null;
        $scrub = $this->scrubber($attempt->user);

        // Others in the batch, by label, in the order the checks name them.
        $others = $this->others($attempt);
        $label = fn (?int $attemptId) => $attemptId && isset($others[$attemptId]) ? $others[$attemptId]['label'] : 'another student';

        return [
            'quiz' => array_filter([
                'title' => $quiz->title,
                'time_limit_minutes' => $quiz->time_limit_minutes,
                'questions_on_this_paper' => $paper->count(),
                'one_question_at_a_time' => $quiz->one_at_a_time,
                'going_back_allowed' => $quiz->one_at_a_time ? $quiz->allow_back : null,
                'proctored' => $attempt->isProctored() ? 'lockdown: full screen, staff watching live' : null,
                'pass_percent' => $quiz->pass_percent,
            ], fn ($value) => $value !== null),
            'student' => 'Student A',
            'result' => [
                'percent' => (float) $attempt->percent,
                'took_seconds' => $since($attempt->submitted_at),
                'written_answers_awaiting_marking' => (bool) $attempt->needs_review,
            ],
            'paper' => $paper->map(function (Question $question) use ($answers, $number, $since, $scrub) {
                /** @var QuizAnswer|null $answer */
                $answer = $answers->get($question->id);

                return array_filter([
                    'number' => $number[$question->id],
                    'type' => $question->typeLabel(),
                    'question' => $question->body,
                    'options' => $question->options,
                    'correct_answer' => $question->isAutoMarked() ? $question->correct : null,
                    'marks' => $question->marks,
                    'answer' => $answer?->response === null ? null : $this->text($answer->response, $scrub),
                    'marked_right' => $question->isAutoMarked() && $answer ? (bool) $answer->is_correct : null,
                    'seconds_on_question' => $answer?->seconds_spent,
                    'answered_at_seconds' => $since($answer?->answered_at),
                ], fn ($value) => $value !== null);
            })->all(),
            'timeline' => $attempt->events->map(fn (QuizAttemptEvent $event) => array_filter([
                'at_seconds' => $since($event->occurred_at),
                'event' => $event->label(),
                'question' => $event->question_id ? ($number[$event->question_id] ?? null) : null,
                'seconds' => $event->duration_seconds,
                'detail' => $this->eventDetail($event, $scrub),
            ], fn ($value) => $value !== null && $value !== []))->values()->all(),
            'flags' => collect($attempt->flags ?? [])->map(fn (array $flag) => [
                'type' => $flag['type'],
                'summary' => $flag['summary'],
                'with' => isset($flag['evidence']['with']) ? $label((int) $flag['evidence']['with']) : null,
                'questions' => collect($flag['evidence']['questions'] ?? [])->map(fn ($id) => $number[(int) $id] ?? null)->filter()->values()->all() ?: null,
            ])->map(fn (array $flag) => array_filter($flag, fn ($value) => $value !== null))->all(),
            'others_in_the_batch' => $this->comparison($attempt, $paper, $answers, $number, $others),
        ];
    }

    /**
     * Store what came back, or why nothing usable did.
     *
     * @param  array{type: string, text: ?string, stop: ?string, input_tokens: ?int, output_tokens: ?int, error: ?string}  $outcome
     */
    protected function record(QuizAiReview $review, array $outcome): void
    {
        $base = ['finished_at' => now(), 'input_tokens' => $outcome['input_tokens'], 'output_tokens' => $outcome['output_tokens']];

        if (in_array($outcome['type'], ['expired', 'canceled'], true)) {
            $review->forceFill(['status' => 'queued', 'batch_id' => null, 'error' => 'The batch '.$outcome['type'].' before this was read; asked again.'])->save();

            return;
        }

        if ($outcome['type'] !== 'succeeded') {
            $review->forceFill([...$base, 'status' => 'failed', 'error' => Str::limit($outcome['error'] ?? 'The request failed.', 290)])->save();

            return;
        }

        $data = json_decode((string) $outcome['text'], true);
        $usable = $outcome['stop'] !== 'refusal' && $outcome['stop'] !== 'max_tokens'
            && is_array($data) && in_array($data['risk'] ?? null, QuizAiReview::RISKS, true) && is_string($data['summary'] ?? null);

        if (! $usable) {
            $review->forceFill([...$base, 'status' => 'failed', 'error' => match ($outcome['stop']) {
                'refusal' => 'The model declined to review this attempt.',
                'max_tokens' => 'The answer was cut short.',
                default => 'The answer could not be read.',
            }])->save();

            return;
        }

        $review->forceFill([
            ...$base,
            'status' => 'done',
            'risk' => $data['risk'],
            'summary' => Str::limit(trim($data['summary']), 2000),
            'evidence' => collect($data['evidence'] ?? [])
                ->filter(fn ($item) => is_array($item) && is_string($item['what'] ?? null))
                ->map(fn (array $item) => ['points_at' => Str::limit((string) ($item['points_at'] ?? ''), 200), 'what' => Str::limit($item['what'], 1000)])
                ->take(20)->values()->all(),
            'explanations' => collect($data['innocent_explanations'] ?? [])->filter(fn ($item) => is_string($item))
                ->map(fn (string $item) => Str::limit($item, 500))->take(12)->values()->all(),
            'error' => null,
        ])->save();
    }

    /**
     * Others in the same batch who submitted this quiz, labelled B, C, … in a
     * fixed order, with their answers.
     *
     * @return array<int, array{label: string, answers: Collection<int, QuizAnswer>}>
     */
    protected function others(QuizAttempt $attempt): array
    {
        $letters = range('B', 'Z');

        return $attempt->quiz->attempts()
            ->whereNotNull('submitted_at')
            ->where('id', '!=', $attempt->id)
            ->where('user_id', '!=', $attempt->user_id)
            ->when($attempt->batch_id, fn ($query) => $query->where('batch_id', $attempt->batch_id))
            ->with('answers')
            ->orderBy('id')
            ->limit(count($letters))
            ->get()
            ->values()
            ->mapWithKeys(fn (QuizAttempt $other, int $index) => [$other->id => ['label' => 'Student '.$letters[$index], 'answers' => $other->answers->keyBy('question_id')]])
            ->all();
    }

    /**
     * What others in the batch wrote or chose, where it bears on this
     * attempt: the closest written answers to each of theirs, and the
     * choices of whoever a flag names.
     *
     * @param  array<int, array{label: string, answers: Collection<int, QuizAnswer>}>  $others
     * @return array<int, array<string, mixed>>
     */
    protected function comparison(QuizAttempt $attempt, Collection $paper, Collection $answers, Collection $number, array $others): array
    {
        $named = collect($attempt->flags ?? [])->pluck('evidence.with')->filter()->map(fn ($id) => (int) $id)->unique();
        $rows = [];

        foreach ($paper as $question) {
            $mine = $answers->get($question->id)?->response;

            if ($mine === null) {
                continue;
            }

            if ($question->type === 'short') {
                $text = $this->text($mine, fn ($value) => $value);
                $closest = collect($others)
                    ->map(fn (array $other) => ['label' => $other['label'], 'answer' => $other['answers']->get($question->id)?->response])
                    ->filter(fn (array $other) => $other['answer'] !== null)
                    ->map(fn (array $other) => [...$other, 'answer' => $this->text($other['answer'], fn ($value) => $value)])
                    ->sortByDesc(fn (array $other) => $this->checks->similarity(is_string($text) ? $text : '', is_string($other['answer']) ? $other['answer'] : ''))
                    ->take(self::OTHERS_PER_QUESTION)
                    ->values()
                    ->all();

                if ($closest !== []) {
                    $rows[] = ['question' => $number[$question->id], 'written_answers' => $closest];
                }

                continue;
            }

            // Choice questions: only for the students a flag names.
            $theirs = $named->filter(fn (int $id) => isset($others[$id]))
                ->map(fn (int $id) => ['label' => $others[$id]['label'], 'answer' => $others[$id]['answers']->get($question->id)?->response])
                ->filter(fn (array $other) => $other['answer'] !== null)
                ->values()
                ->all();

            if ($theirs !== []) {
                $rows[] = ['question' => $number[$question->id], 'choices' => $theirs];
            }
        }

        return $rows;
    }

    /** An event's detail, without anything that could say who or where. */
    protected function eventDetail(QuizAttemptEvent $event, callable $scrub): ?array
    {
        $detail = $event->detail ?? [];

        return match ($event->type) {
            'paste', 'copy' => isset($detail['length']) ? ['characters' => (int) $detail['length']] : null,
            'blocked' => array_filter(['tried' => $detail['what'] ?? null, 'key' => $detail['key'] ?? null]),
            'focus_lost' => isset($detail['why']) ? ['left' => $detail['why']] : null,
            'time_added' => array_filter(['minutes' => $detail['minutes'] ?? null, 'reason' => isset($detail['reason']) ? $scrub($detail['reason']) : null]),
            'ended_by_proctor' => isset($detail['reason']) ? ['reason' => $scrub($detail['reason'])] : null,
            'ended_for_exits' => array_filter(['exits' => $detail['exits'] ?? null, 'limit' => $detail['limit'] ?? null]),
            // A network change says only that it changed; a message only that one came.
            default => null,
        };
    }

    /** An answer as text, trimmed, with the student's own details taken out. */
    protected function text(mixed $response, callable $scrub): mixed
    {
        $values = is_array($response) ? $response : [$response];
        $values = array_map(fn ($value) => Str::limit($scrub((string) $value), self::MAX_TEXT), $values);

        return count($values) === 1 ? $values[0] : $values;
    }

    /**
     * Takes the student's name, email and phone out of free text, and any
     * email address or long number at all.
     */
    protected function scrubber(?User $student): callable
    {
        $parts = collect(preg_split('/\s+/', (string) $student?->name, -1, PREG_SPLIT_NO_EMPTY))
            ->filter(fn (string $part) => mb_strlen($part) >= 3)
            ->map(fn (string $part) => preg_quote($part, '/'));

        return function (string $text) use ($parts): string {
            $text = preg_replace('/[^\s@(<]+@[^\s@]+\.[a-z]{2,}/iu', '[email]', $text) ?? $text;
            $text = preg_replace('/\+?\d[\d\s-]{7,}\d/u', '[number]', $text) ?? $text;

            if ($parts->isNotEmpty()) {
                $text = preg_replace('/\b('.$parts->implode('|').')\b/iu', '[name]', $text) ?? $text;
            }

            return $text;
        };
    }
}
