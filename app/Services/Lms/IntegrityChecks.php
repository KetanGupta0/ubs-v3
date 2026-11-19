<?php

namespace App\Services\Lms;

use App\Models\Question;
use App\Models\Quiz;
use App\Models\QuizAnswer;
use App\Models\QuizAttempt;
use App\Models\QuizAttemptEvent;
use Illuminate\Support\Collection;

/**
 * Checks that need no AI, run once a quiz has closed for everybody (§9.1,
 * layer 2), so answers can be compared across the batch.
 *
 * Each one finds something a person should look at, with the evidence, and
 * nothing more: a notification steals focus, a family shares a router, two
 * good students write the same right answer. A flag never changes a score,
 * fails anybody or reaches the student.
 */
class IntegrityChecks
{
    /** An answer quicker than this, in seconds, counts as fast. */
    public const FAST_SECONDS = 4;

    /** Flag when at least this share of the answered questions were fast, right and the score high. */
    public const FAST_SHARE = 0.6;

    /** Away from the quiz at least this long, in seconds, then a right answer soon after. */
    public const AWAY_SECONDS = 30;

    public const QUICK_AFTER_RETURN = 20;

    /** A pasted block at least this long in a written answer. */
    public const LONG_PASTE = 100;

    /** Written answers this alike (word triples in common), and at least this long. */
    public const TEXT_SIMILARITY = 0.85;

    public const MIN_TEXT_LENGTH = 40;

    /** A wrong choice at most this share of the batch made is rare; this many in common is notable. */
    public const RARE_WRONG_SHARE = 0.2;

    public const SHARED_RARE_WRONG = 3;

    /** Submitted from one address within these minutes, with this share of answers alike. */
    public const NETWORK_MINUTES = 10;

    public const NETWORK_MATCH = 0.8;

    /** Run every check on a quiz's submitted attempts. Returns how many were flagged. */
    public function run(Quiz $quiz): int
    {
        $attempts = $quiz->attempts()
            ->whereNotNull('submitted_at')
            ->with(['answers', 'events'])
            ->get();

        $questions = $quiz->questions()->get()->keyBy('id');
        $flags = $attempts->mapWithKeys(fn (QuizAttempt $attempt) => [$attempt->id => []])->all();

        foreach ($attempts as $attempt) {
            foreach ($this->onItsOwn($attempt, $quiz, $questions, $attempts) as $flag) {
                $flags[$attempt->id][] = $flag;
            }
        }

        foreach ($attempts->groupBy(fn (QuizAttempt $attempt) => $attempt->batch_id ?? 0) as $batch) {
            foreach ($this->acrossTheBatch($batch->values(), $questions) as [$attemptId, $flag]) {
                $flags[$attemptId][] = $flag;
            }
        }

        $flagged = 0;

        foreach ($attempts as $attempt) {
            $found = $flags[$attempt->id];
            $attempt->forceFill([
                'flags' => $found ?: null,
                'flagged_at' => $found ? ($attempt->flagged_at ?? now()) : null,
            ])->save();
            $flagged += $found ? 1 : 0;
        }

        $quiz->forceFill(['integrity_checked_at' => now()])->save();

        return $flagged;
    }

    /** @return array<int, array<string, mixed>> */
    protected function onItsOwn(QuizAttempt $attempt, Quiz $quiz, Collection $questions, Collection $everybody): array
    {
        $flags = [];
        $answers = $attempt->answers->keyBy('question_id');
        $speed = $quiz->one_at_a_time ? $this->fastOneByOne($attempt, $answers, $questions) : $this->fastOverall($attempt, $answers, $questions);

        if ($speed !== null) {
            $flags[] = $speed;
        }

        // Away for a while, then a hard question right straight after coming back.
        $hard = $this->hardQuestions($questions, $everybody);
        $returns = $attempt->events->where('type', 'focus_back')->filter(fn (QuizAttemptEvent $event) => (int) $event->duration_seconds >= self::AWAY_SECONDS);
        $afterAway = [];

        foreach ($returns as $return) {
            $next = $attempt->events->first(fn (QuizAttemptEvent $event) => $event->type === 'answered'
                && $event->occurred_at->greaterThanOrEqualTo($return->occurred_at)
                && $event->occurred_at->diffInSeconds($return->occurred_at, true) <= self::QUICK_AFTER_RETURN);

            if ($next && $answers->get($next->question_id)?->is_correct && ($hard === null || $hard->contains($next->question_id))) {
                $afterAway[] = ['question' => $next->question_id, 'away_seconds' => (int) $return->duration_seconds, 'answered_after_seconds' => (int) $next->occurred_at->diffInSeconds($return->occurred_at, true)];
            }
        }

        if ($afterAway !== []) {
            $flags[] = [
                'type' => 'answered_after_away',
                'summary' => count($afterAway) === 1
                    ? 'Away from the quiz for '.$afterAway[0]['away_seconds'].' seconds, then a hard question answered right '.$afterAway[0]['answered_after_seconds'].' seconds after coming back.'
                    : count($afterAway).' times away from the quiz, each followed within seconds by a hard question answered right.',
                'evidence' => ['returns' => $afterAway],
            ];
        }

        // A long block pasted into a written answer.
        $pastes = $attempt->events->where('type', 'paste')->filter(fn (QuizAttemptEvent $event) => (int) ($event->detail['length'] ?? 0) >= self::LONG_PASTE);

        if ($pastes->isNotEmpty()) {
            $flags[] = [
                'type' => 'paste',
                'summary' => $pastes->count() === 1
                    ? 'Pasted '.$pastes->first()->detail['length'].' characters into an answer.'
                    : "Pasted long text into answers {$pastes->count()} times.",
                'evidence' => ['pastes' => $pastes->map(fn (QuizAttemptEvent $event) => ['question' => $event->question_id, 'length' => (int) $event->detail['length']])->values()->all()],
            ];
        }

        // Carried on in another browser.
        $switches = $attempt->events->where('type', 'device_changed')->count();

        if ($switches > 0) {
            $flags[] = [
                'type' => 'device',
                'summary' => $switches === 1 ? 'Carried on in another browser once.' : "Carried on in another browser {$switches} times.",
                'evidence' => ['switches' => $switches],
            ];
        }

        return $flags;
    }

    /**
     * Speed, one question at a time: most of the paper answered right in a few
     * seconds each. The time on a question is only meaningful when it was the
     * only one on the screen.
     *
     * @return array<string, mixed>|null
     */
    protected function fastOneByOne(QuizAttempt $attempt, Collection $answers, Collection $questions): ?array
    {
        $answered = $answers->filter(fn (QuizAnswer $answer) => $answer->answered_at !== null && $answer->response !== null && $questions->get($answer->question_id)?->isAutoMarked());
        $fast = $answered->filter(fn (QuizAnswer $answer) => $answer->is_correct && $answer->seconds_spent < self::FAST_SECONDS);

        if ($answered->count() < 3 || $fast->count() < max(3, (int) ceil($answered->count() * self::FAST_SHARE)) || (float) $attempt->percent < 70) {
            return null;
        }

        return [
            'type' => 'speed',
            'summary' => "{$fast->count()} of {$answered->count()} questions answered right in under ".self::FAST_SECONDS.' seconds each.',
            'evidence' => ['questions' => $fast->pluck('question_id')->values()->all(), 'median_seconds' => (int) $answered->pluck('seconds_spent')->median()],
        ];
    }

    /**
     * Speed, every question on one page: the whole paper answered, mostly
     * right, in less time than reading it takes. Time between answers on one
     * page says nothing, because people read ahead and then answer in a run.
     *
     * @return array<string, mixed>|null
     */
    protected function fastOverall(QuizAttempt $attempt, Collection $answers, Collection $questions): ?array
    {
        $answered = $answers->filter(fn (QuizAnswer $answer) => $answer->response !== null && $questions->get($answer->question_id)?->isAutoMarked())->count();

        if ($answered < 3 || $attempt->started_at === null || (float) $attempt->percent < 70) {
            return null;
        }

        $elapsed = (int) $attempt->started_at->diffInSeconds($attempt->submitted_at, true);

        if ($elapsed >= $answered * self::FAST_SECONDS) {
            return null;
        }

        return [
            'type' => 'speed',
            'summary' => "{$answered} questions answered in {$elapsed} seconds in all, with ".round((float) $attempt->percent).'% right.',
            'evidence' => ['answered' => $answered, 'elapsed_seconds' => $elapsed],
        ];
    }

    /**
     * Pairs in one batch: written answers alike, the same rare wrong choices,
     * the same answers sent from one address at the same time.
     *
     * @return array<int, array{0: int, 1: array<string, mixed>}>
     */
    protected function acrossTheBatch(Collection $batch, Collection $questions): array
    {
        if ($batch->count() < 2) {
            return [];
        }

        $found = [];
        $responses = $batch->mapWithKeys(fn (QuizAttempt $attempt) => [$attempt->id => $attempt->answers->filter(fn (QuizAnswer $answer) => $answer->response !== null)
            ->mapWithKeys(fn (QuizAnswer $answer) => [$answer->question_id => $this->normalise($answer->response)])]);
        $rare = $this->rareWrongChoices($batch, $questions);
        $submittedFrom = $batch->mapWithKeys(fn (QuizAttempt $attempt) => [$attempt->id => $attempt->events->firstWhere('type', 'submitted')?->ip ?? $attempt->last_ip]);
        $list = $batch->values();

        for ($i = 0; $i < $list->count(); $i++) {
            for ($j = $i + 1; $j < $list->count(); $j++) {
                [$a, $b] = [$list[$i], $list[$j]];
                $mine = $responses[$a->id];
                $theirs = $responses[$b->id];
                $common = $mine->keys()->intersect($theirs->keys());

                // Written answers that say the same thing in nearly the same words.
                $alike = $common->filter(function (int $questionId) use ($questions, $mine, $theirs) {
                    if ($questions->get($questionId)?->type !== 'short') {
                        return false;
                    }

                    [$x, $y] = [(string) $mine[$questionId], (string) $theirs[$questionId]];

                    return mb_strlen($x) >= self::MIN_TEXT_LENGTH && mb_strlen($y) >= self::MIN_TEXT_LENGTH && $this->similarity($x, $y) >= self::TEXT_SIMILARITY;
                })->values();

                if ($alike->isNotEmpty()) {
                    foreach ([[$a, $b], [$b, $a]] as [$one, $other]) {
                        $found[] = [$one->id, ['type' => 'overlap', 'summary' => 'Written answers nearly word for word the same as another student\'s in the batch.', 'evidence' => ['with' => $other->id, 'questions' => $alike->all()]]];
                    }
                }

                // The same wrong choices that hardly anybody else made.
                $sharedRare = $common->filter(fn (int $questionId) => $mine[$questionId] === $theirs[$questionId]
                    && isset($rare[$questionId]) && in_array($mine[$questionId], $rare[$questionId], true))->values();

                if ($sharedRare->count() >= self::SHARED_RARE_WRONG) {
                    foreach ([[$a, $b], [$b, $a]] as [$one, $other]) {
                        $found[] = [$one->id, ['type' => 'shared_wrong', 'summary' => "{$sharedRare->count()} of the same wrong answers as another student, which few others gave.", 'evidence' => ['with' => $other->id, 'questions' => $sharedRare->all()]]];
                    }
                }

                // Sent from one address within minutes, with the answers alike.
                $sameAddress = $submittedFrom[$a->id] && $submittedFrom[$a->id] === $submittedFrom[$b->id]
                    && $a->submitted_at->diffInMinutes($b->submitted_at, true) <= self::NETWORK_MINUTES;
                $autoCommon = $common->filter(fn (int $questionId) => $questions->get($questionId)?->isAutoMarked())->values();
                $same = $autoCommon->filter(fn (int $questionId) => $mine[$questionId] === $theirs[$questionId])->count();

                if ($sameAddress && $autoCommon->count() >= 5 && $same / $autoCommon->count() >= self::NETWORK_MATCH) {
                    foreach ([[$a, $b], [$b, $a]] as [$one, $other]) {
                        $found[] = [$one->id, ['type' => 'network', 'summary' => 'Submitted from the same address as another student within '.self::NETWORK_MINUTES.' minutes, with '.round($same / $autoCommon->count() * 100).'% of answers the same.', 'evidence' => ['with' => $other->id, 'ip' => $submittedFrom[$a->id]]]];
                    }
                }
            }
        }

        return $found;
    }

    /**
     * Questions few in the batch got right, where there are enough people to
     * say so; null when there are too few to judge, so every question counts.
     */
    protected function hardQuestions(Collection $questions, Collection $everybody): ?Collection
    {
        if ($everybody->count() < 5) {
            return null;
        }

        return $questions->keys()->filter(function (int $questionId) use ($everybody) {
            $answers = $everybody->map(fn (QuizAttempt $attempt) => $attempt->answers->firstWhere('question_id', $questionId))->filter();

            return $answers->isNotEmpty() && $answers->where('is_correct', true)->count() / $answers->count() <= 0.5;
        })->values();
    }

    /** @return array<int, array<int, string>> question id => wrong choices few made */
    protected function rareWrongChoices(Collection $batch, Collection $questions): array
    {
        $rare = [];

        foreach ($questions->filter(fn (Question $question) => $question->isAutoMarked()) as $question) {
            $wrong = $batch->map(fn (QuizAttempt $attempt) => $attempt->answers->firstWhere('question_id', $question->id))
                ->filter(fn (?QuizAnswer $answer) => $answer && $answer->response !== null && ! $answer->is_correct)
                ->map(fn (QuizAnswer $answer) => $this->normalise($answer->response));

            $counts = $wrong->countBy();

            $rare[$question->id] = $counts->filter(fn (int $count) => $count >= 2 && $count / $batch->count() <= self::RARE_WRONG_SHARE)->keys()->all();
        }

        return $rare;
    }

    protected function normalise(mixed $response): string
    {
        $values = collect(is_array($response) ? $response : [$response])->map(fn ($value) => (string) $value)->sort()->values()->all();

        return mb_strtolower(trim(preg_replace('/[^\pL\pN]+/u', ' ', implode(' ', $values)) ?? ''));
    }

    /** How much of two texts' word triples they share (Jaccard). */
    public function similarity(string $a, string $b): float
    {
        $shingles = function (string $text): array {
            $words = preg_split('/\s+/', $this->normalise($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

            if (count($words) < 3) {
                return [implode(' ', $words) => true];
            }

            $set = [];

            for ($i = 0; $i <= count($words) - 3; $i++) {
                $set[$words[$i].' '.$words[$i + 1].' '.$words[$i + 2]] = true;
            }

            return $set;
        };

        [$x, $y] = [$shingles($a), $shingles($b)];
        $union = count($x + $y);

        return $union === 0 ? 0.0 : count(array_intersect_key($x, $y)) / $union;
    }
}
