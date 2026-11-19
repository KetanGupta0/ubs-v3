<?php

namespace App\Services\Lms;

use App\Models\Batch;
use App\Models\LeaderboardPoint;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\QuizAttemptGrant;
use App\Models\QuizIntegrityDecision;
use App\Models\User;
use App\Notifications\IntegrityDecisionMade;
use App\Notifications\IntegrityReplyReceived;
use App\Services\Admin\Auditor;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * A person's decision on a flagged attempt (§9.1, layer 4), and what follows.
 *
 * Cleared, to discuss, voided (with a resit if the trainer allows one), or a
 * warning on the usual ladder. The student is told the outcome and the
 * reason, never the note, and can reply once before it is final. A first
 * decision to clear it is final at once and is not sent: nothing was ever
 * said to them. A voided attempt stays on the record and counts for nothing,
 * points included; changing the decision gives back what voiding took.
 */
class IntegrityDecisions
{
    public function __construct(
        protected Warnings $warnings,
        protected Activity $activity,
        protected Auditor $auditor,
    ) {}

    public function decide(QuizAttempt $attempt, User $by, string $outcome, ?string $reason, ?string $note, bool $resit = false): QuizIntegrityDecision
    {
        if (! array_key_exists($outcome, QuizIntegrityDecision::OUTCOMES)) {
            throw new RuntimeException('That is not a decision this can record.');
        }

        if ($attempt->isOpen()) {
            throw new RuntimeException('That attempt has not finished yet.');
        }

        $decision = DB::transaction(function () use ($attempt, $by, $outcome, $reason, $note, $resit) {
            $decision = $attempt->decision()->lockForUpdate()->first() ?? new QuizIntegrityDecision(['quiz_attempt_id' => $attempt->id]);

            if ($decision->isFinal()) {
                throw new RuntimeException('That decision is final.');
            }

            $before = $decision->exists ? $decision->only(['outcome', 'reason', 'resit']) : null;
            $told = $decision->told_at !== null;

            // Void it, or undo a void, and put the points right either way.
            $attempt->forceFill(['voided_at' => $outcome === 'voided' ? ($attempt->voided_at ?? now()) : null])->save();
            $this->recountPoints($attempt);

            // One more attempt, once, where a voided attempt may be sat again.
            if ($outcome === 'voided' && $resit && ! $decision->resit) {
                QuizAttemptGrant::query()->create([
                    'quiz_id' => $attempt->quiz_id,
                    'user_id' => $attempt->user_id,
                    'extra_attempts' => 1,
                    'reason' => 'A resit after attempt '.$attempt->attempt_number.' was voided in an integrity review.',
                    'granted_by' => $by->id,
                ]);
            }

            // A warning goes on the ladder, which tells the student itself.
            if ($outcome === 'warned' && ! $decision->warning_id) {
                $warning = $this->warnings->issue(
                    $attempt->user,
                    "About your attempt at {$attempt->quiz->title}: {$reason}",
                    batch: $attempt->batch_id ? Batch::query()->find($attempt->batch_id) : null,
                    issuedBy: $by,
                );
                $decision->warning_id = $warning->id;
            }

            $tell = $outcome !== 'cleared' || $told;

            $decision->fill([
                'outcome' => $outcome,
                'reason' => $reason,
                'note' => $note,
                'resit' => $decision->resit || ($outcome === 'voided' && $resit),
                'decided_by' => $by->id,
                'decided_at' => now(),
            ]);
            $decision->told_at = $tell ? now() : null;
            $decision->final_at = $tell ? null : now();
            $decision->save();

            $this->auditor->actingAs($by)->action('quiz.integrity_decided', $attempt, array_filter([
                'outcome' => $outcome,
                'reason' => $reason,
                'resit' => $outcome === 'voided' ? $resit : null,
                'before' => $before,
            ], fn ($value) => $value !== null), $this->label($attempt));

            return $decision;
        });

        // A warning already reached them from the ladder; everything else is said here.
        if ($decision->told_at && $outcome !== 'warned') {
            $attempt->user->notify(new IntegrityDecisionMade($decision->fresh(['attempt.quiz'])));
        }

        return $decision;
    }

    /** The student's one reply, before it is final. */
    public function reply(QuizIntegrityDecision $decision, User $student, string $text): void
    {
        if ($decision->attempt->user_id !== $student->id || ! $decision->awaitsReply()) {
            throw new RuntimeException('There is nothing to reply to here.');
        }

        $decision->forceFill(['reply' => $text, 'replied_at' => now()])->save();
        $this->auditor->actingAs($student)->action('quiz.integrity_replied', $decision->attempt, [], $this->label($decision->attempt));

        $decision->decider?->notify(new IntegrityReplyReceived($decision->fresh(['attempt.quiz', 'attempt.user'])));
    }

    /** Made final: no reply and no change after this. */
    public function finalise(QuizIntegrityDecision $decision, User $by): void
    {
        if ($decision->isFinal()) {
            return;
        }

        $decision->forceFill(['final_at' => now()])->save();
        $this->auditor->actingAs($by)->action('quiz.integrity_final', $decision->attempt, ['outcome' => $decision->outcome], $this->label($decision->attempt));

        // They replied and are waiting to hear.
        if ($decision->reply !== null) {
            $decision->attempt->user->notify(new IntegrityDecisionMade($decision->fresh(['attempt.quiz']), final: true));
        }
    }

    /**
     * A quiz's points follow the attempts that count: passing and distinction
     * stand only while a counted attempt earns them.
     */
    public function recountPoints(QuizAttempt $attempt): void
    {
        $quiz = $attempt->quiz;
        $student = $attempt->user;
        $best = $quiz->bestAttemptFor($student);
        $earned = [
            'quiz.passed' => $best !== null && $best->passed,
            'quiz.distinction' => $best !== null && $best->passed && (float) $best->percent >= 90,
        ];

        foreach ($earned as $source => $deserved) {
            $held = LeaderboardPoint::query()
                ->where('user_id', $student->id)
                ->where('source', $source)
                ->where('reason_type', Quiz::class)
                ->where('reason_id', $quiz->id);

            if (! $deserved) {
                $held->delete();
            } elseif (! $held->exists()) {
                $this->activity->award($student, $source, $quiz, courseId: $quiz->course_id, batchId: $best->batch_id);
            }
        }
    }

    protected function label(QuizAttempt $attempt): string
    {
        return ($attempt->user?->name ?? 'A student').' — '.$attempt->quiz->title.', attempt '.$attempt->attempt_number;
    }
}
