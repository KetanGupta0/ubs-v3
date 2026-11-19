<?php

namespace App\Services\Lms;

use App\Events\Lms\ProctorUpdate;
use App\Models\Enrollment;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\QuizAttemptEvent;
use App\Models\User;
use App\Notifications\QuizAttemptEnded;
use App\Services\Admin\Auditor;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Proctored quizzes (§9.1a), in one place: who may start one, where and when,
 * what the page says about itself while it runs, what the proctor sees, and
 * the three things a proctor may do: send a message, give more time, end it.
 *
 * A web page cannot take control of a computer, so most of the lockdown is
 * the page noticing, covering the questions and saying so. What decides
 * anything is here, on the server: the sitting, the clock, the exits counted
 * from the event log. Safe Exam Browser, where a quiz requires it, is checked
 * on every request of the attempt.
 */
class Proctoring
{
    /** Not heard from for this long, the console says the connection was lost. */
    public const QUIET_SECONDS = 30;

    /** The most a proctor gives at once. */
    public const MAX_EXTRA_MINUTES = 60;

    public function __construct(
        protected QuizGrader $grader,
        protected AttemptEvents $events,
        protected Auditor $auditor,
    ) {}

    /**
     * Why this student cannot start this proctored quiz here and now, in words
     * they can act on; null when they can.
     */
    public function refusal(Quiz $quiz, Enrollment $enrolment, Request $request): ?string
    {
        if ($this->looksLikePhone($request)) {
            return 'This quiz is sat on a laptop or computer, not on a phone or tablet.';
        }

        if ($quiz->require_seb && ! $this->inSafeExamBrowser($quiz, $request)) {
            return 'This quiz is taken in Safe Exam Browser. Open it from there.';
        }

        if (! $quiz->openSlotFor($enrolment->batch_id)) {
            $next = $quiz->nextSlotFor($enrolment->batch_id);

            return $next ? 'It can be started during its sitting: '.$next->window().'.' : 'No sitting is scheduled for your batch yet.';
        }

        return null;
    }

    /**
     * Start a proctored attempt in the sitting open now, agreed to, its clock
     * ending with the sitting at the latest, so nobody sits it unwatched.
     */
    public function begin(Quiz $quiz, User $student, Enrollment $enrolment, ?string $device, ?string $ip): QuizAttempt
    {
        $slot = $quiz->openSlotFor($enrolment->batch_id);

        if (! $slot) {
            throw new RuntimeException('This sitting has ended.');
        }

        $attempt = $this->grader->start($quiz, $student, $enrolment->batch_id, $device, $ip);
        $deadline = $attempt->expires_at && $attempt->expires_at->lessThan($slot->ends_at) ? $attempt->expires_at : $slot->ends_at;

        $attempt->forceFill([
            'quiz_slot_id' => $slot->id,
            'proctoring' => $quiz->proctoring,
            'consented_at' => now(),
            'expires_at' => $deadline,
            'seen_at' => now(),
        ])->save();

        $this->events->record($attempt, 'consented', ip: $ip);
        $this->announce($attempt);

        return $attempt;
    }

    /**
     * What the page said about itself on its last report: in full screen, in
     * focus. Returns whether anything a proctor sees has changed.
     *
     * @param  array<string, mixed>  $state
     */
    public function heard(QuizAttempt $attempt, array $state, int $kept): bool
    {
        $now = [
            'fullscreen' => filter_var($state['fullscreen'] ?? false, FILTER_VALIDATE_BOOL),
            'focused' => filter_var($state['focused'] ?? false, FILTER_VALIDATE_BOOL),
        ];

        $wasQuiet = $attempt->seen_at === null || $attempt->seen_at->lt(now()->subSeconds(self::QUIET_SECONDS));
        $changed = $kept > 0 || $wasQuiet || $attempt->live_state !== $now;

        $attempt->forceFill(['seen_at' => now(), 'live_state' => $now])->save();

        return $changed;
    }

    /**
     * Submitted for them once they have left the quiz as often as it allows,
     * if it sets a limit, counted from the log rather than from the page.
     */
    public function enforceExits(QuizAttempt $attempt): bool
    {
        $limit = $attempt->quiz->max_exits;

        if (! $limit || ! $attempt->isOpen()) {
            return false;
        }

        $exits = $attempt->events()->where('type', 'focus_lost')->count();

        if ($exits < $limit) {
            return false;
        }

        $attempt->forceFill(['ended_reason' => "Left the quiz {$exits} times, which this quiz allows {$limit} ".($limit === 1 ? 'time' : 'times').'. It was submitted with what had been answered.'])->save();
        $this->events->record($attempt, 'ended_for_exits', detail: ['exits' => $exits, 'limit' => $limit]);
        $this->grader->submit($attempt, []);
        $this->announce($attempt->fresh());

        return true;
    }

    /** Messages from the proctor the student has not yet said they read. */
    public function unreadMessages(QuizAttempt $attempt): array
    {
        $events = $attempt->events()->whereIn('type', ['proctor_message', 'message_seen'])->get();
        $read = $events->where('type', 'message_seen')->map(fn (QuizAttemptEvent $event) => (int) ($event->detail['message'] ?? 0))->all();

        return $events->where('type', 'proctor_message')
            ->reject(fn (QuizAttemptEvent $event) => in_array($event->id, $read, true))
            ->map(fn (QuizAttemptEvent $event) => [
                'id' => $event->id,
                'text' => (string) ($event->detail['text'] ?? ''),
                'at' => $event->occurred_at->format('g:i a'),
            ])
            ->values()
            ->all();
    }

    /* ------------------------------------------------- the proctor's three */

    public function message(QuizAttempt $attempt, User $proctor, string $text): QuizAttemptEvent
    {
        $this->mustBeRunning($attempt);

        $event = $this->events->record($attempt, 'proctor_message', detail: ['text' => $text, 'by' => $proctor->id]);

        $this->auditor->actingAs($proctor)->action('quiz.proctor_message', $attempt, ['text' => $text], $this->label($attempt));
        $this->announce($attempt);

        return $event;
    }

    /** More time after a genuine problem, from now if the clock has already run out. */
    public function addTime(QuizAttempt $attempt, User $proctor, int $minutes, string $reason): void
    {
        $this->mustBeRunning($attempt);

        $minutes = max(1, min($minutes, self::MAX_EXTRA_MINUTES));
        $from = $attempt->expires_at;
        $base = $from && $from->isFuture() ? $from : now();

        $attempt->forceFill(['expires_at' => $base->copy()->addMinutes($minutes)])->save();

        $this->events->record($attempt, 'time_added', seconds: $minutes * 60, detail: ['minutes' => $minutes, 'reason' => $reason, 'by' => $proctor->id]);
        $this->auditor->actingAs($proctor)->action('quiz.time_added', $attempt, [
            'minutes' => $minutes,
            'reason' => $reason,
            'from' => $from?->toIso8601String(),
            'to' => $attempt->expires_at->toIso8601String(),
        ], $this->label($attempt));
        $this->announce($attempt);
    }

    /**
     * End it with a reason. What was answered is marked as it stands; whether
     * they sit it again is a trainer's decision afterwards, never this one's.
     */
    public function end(QuizAttempt $attempt, User $proctor, string $reason): void
    {
        $this->mustBeRunning($attempt);

        $attempt->forceFill(['ended_by' => $proctor->id, 'ended_reason' => $reason])->save();
        $this->events->record($attempt, 'ended_by_proctor', detail: ['reason' => $reason, 'by' => $proctor->id]);
        $this->grader->submit($attempt, []);

        $this->auditor->actingAs($proctor)->action('quiz.attempt_ended', $attempt, ['reason' => $reason], $this->label($attempt));

        $attempt = $attempt->fresh();
        $attempt->user->notify(new QuizAttemptEnded($attempt));
        $this->announce($attempt);
    }

    /* ------------------------------------------------------ what is seen */

    /**
     * One student, as the console's tile shows them.
     *
     * @return array<string, mixed>
     */
    public function tile(QuizAttempt $attempt): array
    {
        $counts = $attempt->events()->reorder()->selectRaw('type, count(*) as total')->groupBy('type')->pluck('total', 'type');
        $lastViolation = $attempt->events()->reorder('occurred_at', 'desc')->whereIn('type', ['focus_lost', 'blocked'])->value('occurred_at');
        $state = $attempt->live_state ?? [];
        $quiet = $attempt->seen_at === null || $attempt->seen_at->lt(now()->subSeconds(self::QUIET_SECONDS));

        return [
            'id' => $attempt->id,
            'student' => $attempt->user?->name ?? 'A student',
            'number' => $attempt->attempt_number,
            'status' => match (true) {
                $attempt->submitted_at !== null => 'finished',
                $quiet => 'quiet',
                default => 'here',
            },
            'fullscreen' => (bool) ($state['fullscreen'] ?? false),
            'focused' => (bool) ($state['focused'] ?? false),
            'reported' => $attempt->live_state !== null,
            'exits' => (int) ($counts['focus_lost'] ?? 0),
            'blocked' => (int) ($counts['blocked'] ?? 0),
            'messages' => (int) ($counts['proctor_message'] ?? 0),
            'answered' => $attempt->answers()->whereNotNull('response')->count(),
            'total' => count($attempt->question_ids ?? []),
            'secondsRemaining' => $attempt->submitted_at ? null : $attempt->secondsRemaining(),
            'seenAt' => $attempt->seen_at?->toIso8601String(),
            'lastViolationAt' => $lastViolation ? Carbon::parse($lastViolation)->toIso8601String() : null,
            'finishedAt' => $attempt->submitted_at?->format('g:i a'),
            'endedReason' => $attempt->ended_reason,
        ];
    }

    /** Store first, broadcast second: a socket that is down costs the console its speed only. */
    public function announce(QuizAttempt $attempt): void
    {
        if (! $attempt->isProctored()) {
            return;
        }

        try {
            event(new ProctorUpdate($attempt->quiz_id, $this->tile($attempt)));
        } catch (Throwable $e) {
            Log::warning('Proctor broadcast failed; the console picks it up by polling.', ['attempt' => $attempt->id, 'error' => $e->getMessage()]);
        }
    }

    /* ------------------------------------------------------- the browser */

    /**
     * A phone or tablet, as far as the request says. A phone asking for the
     * desktop site can pass; the page checks the screen and pointer as well.
     */
    public function looksLikePhone(Request $request): bool
    {
        if ($request->header('Sec-CH-UA-Mobile') === '?1') {
            return true;
        }

        return (bool) preg_match('/Mobi|Android|iPhone|iPad|iPod|Silk|Kindle|Opera Mini|IEMobile|BlackBerry/i', (string) $request->userAgent());
    }

    /**
     * Whether this request came from Safe Exam Browser set up for this quiz
     * (X10). Each request carries a SHA-256 of its own address followed by the
     * configuration's key, or by the browser's exam key, so a header copied
     * from another page does not fit this one.
     */
    public function inSafeExamBrowser(Quiz $quiz, Request $request): bool
    {
        $url = $request->getSchemeAndHttpHost().$request->getRequestUri();
        $checks = [
            'X-SafeExamBrowser-ConfigKeyHash' => $quiz->seb_config_keys ?? [],
            'X-SafeExamBrowser-RequestHash' => $quiz->seb_exam_keys ?? [],
        ];

        foreach ($checks as $header => $keys) {
            $sent = strtolower(trim((string) $request->header($header)));

            if ($sent === '') {
                continue;
            }

            foreach ($keys as $key) {
                if (hash_equals(hash('sha256', $url.strtolower($key)), $sent)) {
                    return true;
                }
            }
        }

        return false;
    }

    protected function mustBeRunning(QuizAttempt $attempt): void
    {
        if (! $attempt->isOpen()) {
            throw new RuntimeException('That attempt has already finished.');
        }
    }

    protected function label(QuizAttempt $attempt): string
    {
        return ($attempt->user?->name ?? 'A student').' — '.$attempt->quiz->title;
    }
}
