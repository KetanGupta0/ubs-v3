<?php

namespace App\Services\Lms;

use App\Models\QuizAttempt;
use App\Models\QuizAttemptEvent;

/**
 * What happened during a quiz attempt (§9.1, layer 2), written down at the
 * server's time.
 *
 * The server writes what it saw itself: the start, every answer kept, the
 * submission, a new network or another browser. The browser reports what only
 * it can see (leaving the quiz, pasting, copying), and those are taken as
 * reported: evidence for a person, which can be missing or made up.
 */
class AttemptEvents
{
    /** The most a browser may report in one go, and in one attempt. */
    public const PER_REPORT = 50;

    public const PER_ATTEMPT = 2000;

    public function record(QuizAttempt $attempt, string $type, ?int $questionId = null, ?int $seconds = null, ?array $detail = null, ?string $ip = null): QuizAttemptEvent
    {
        return QuizAttemptEvent::query()->create([
            'quiz_attempt_id' => $attempt->id,
            'type' => $type,
            'question_id' => $questionId,
            'duration_seconds' => $seconds,
            'detail' => $detail,
            'ip' => $ip,
            'occurred_at' => now(),
        ]);
    }

    /**
     * What the browser reported, kept only where it is the browser's to say.
     *
     * @param  array<int, array<string, mixed>>  $reported
     */
    public function fromBrowser(QuizAttempt $attempt, array $reported, ?string $ip): int
    {
        if (QuizAttemptEvent::query()->where('quiz_attempt_id', $attempt->id)->count() >= self::PER_ATTEMPT) {
            return 0;
        }

        $paper = collect($attempt->question_ids ?? [])->map(fn ($id) => (int) $id);
        $away = $this->away($attempt);
        $kept = 0;

        foreach (array_slice($reported, 0, self::PER_REPORT) as $event) {
            $type = $event['type'] ?? null;

            if (! in_array($type, QuizAttemptEvent::FROM_BROWSER, true)) {
                continue;
            }

            // Nobody leaves twice without coming back. A page closing reports
            // leaving full screen, and the page opening again reports it too:
            // that is one departure, and counting it twice would bring a
            // proctored student nearer being submitted for nothing.
            if ($type === 'focus_lost' && $away) {
                continue;
            }

            if (in_array($type, ['focus_lost', 'focus_back'], true)) {
                $away = $type === 'focus_lost';
            }

            $question = isset($event['question_id']) && $paper->contains((int) $event['question_id']) ? (int) $event['question_id'] : null;
            $seconds = isset($event['seconds']) && is_numeric($event['seconds']) ? max(0, min((int) $event['seconds'], 86400)) : null;
            $detail = $this->detail($attempt, $type, $event);

            // Blocked needs to say what; read needs a message that was sent.
            if (($type === 'blocked' && ! isset($detail['what'])) || ($type === 'message_seen' && ! isset($detail['message']))) {
                continue;
            }

            $this->record($attempt, $type, $question, $seconds, $detail ?: null, $ip);
            $kept++;
        }

        return $kept;
    }

    /**
     * Whether the student is away from the quiz as far as the record goes:
     * they left and have not come back, and the page has not since said it is
     * in full screen and in focus.
     */
    protected function away(QuizAttempt $attempt): bool
    {
        $last = QuizAttemptEvent::query()
            ->where('quiz_attempt_id', $attempt->id)
            ->whereIn('type', ['focus_lost', 'focus_back'])
            ->latest('id')
            ->value('type');

        $back = ($attempt->live_state['fullscreen'] ?? false) && ($attempt->live_state['focused'] ?? false);

        return $last === 'focus_lost' && ! $back;
    }

    /**
     * The little a browser may add to an event, each from a fixed list or a
     * plain number, so nothing it types reaches the record as text.
     *
     * @param  array<string, mixed>  $event
     * @return array<string, int|string>
     */
    protected function detail(QuizAttempt $attempt, string $type, array $event): array
    {
        $detail = [];

        if (isset($event['length']) && is_numeric($event['length'])) {
            $detail['length'] = max(0, min((int) $event['length'], 1000000));
        }

        if ($type === 'blocked' && in_array($event['what'] ?? null, QuizAttemptEvent::BLOCKABLE, true)) {
            $detail['what'] = $event['what'];

            if (is_string($event['key'] ?? null) && preg_match('/^[A-Za-z0-9+]{1,24}$/', $event['key'])) {
                $detail['key'] = $event['key'];
            }
        }

        if ($type === 'focus_lost' && in_array($event['why'] ?? null, QuizAttemptEvent::LEFT_BY, true)) {
            $detail['why'] = $event['why'];
        }

        if ($type === 'message_seen' && is_numeric($event['message'] ?? null)
            && QuizAttemptEvent::query()->where('quiz_attempt_id', $attempt->id)->where('type', 'proctor_message')->whereKey((int) $event['message'])->exists()) {
            $detail['message'] = (int) $event['message'];
        }

        return $detail;
    }

    /**
     * A request on an open attempt from somewhere new: another network, or
     * another browser than the one that started it. Noted, never refused: a
     * phone dropping off Wi-Fi is not cheating.
     */
    public function notice(QuizAttempt $attempt, ?string $ip, ?string $deviceHash): void
    {
        $changes = [];

        if ($ip && $attempt->last_ip && $ip !== $attempt->last_ip) {
            $this->record($attempt, 'ip_changed', detail: ['from' => $attempt->last_ip], ip: $ip);
            $changes['last_ip'] = $ip;
        } elseif ($ip && ! $attempt->last_ip) {
            $changes['last_ip'] = $ip;
        }

        if ($deviceHash && $attempt->device_hash && ! hash_equals($attempt->device_hash, $deviceHash)) {
            $this->record($attempt, 'device_changed', ip: $ip);
            $changes['device_hash'] = $deviceHash;
        }

        if ($changes !== []) {
            $attempt->forceFill($changes)->save();
        }
    }
}
