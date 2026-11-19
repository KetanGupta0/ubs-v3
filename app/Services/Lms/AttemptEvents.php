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

    public function record(QuizAttempt $attempt, string $type, ?int $questionId = null, ?int $seconds = null, ?array $detail = null, ?string $ip = null): void
    {
        QuizAttemptEvent::query()->create([
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
        $kept = 0;

        foreach (array_slice($reported, 0, self::PER_REPORT) as $event) {
            $type = $event['type'] ?? null;

            if (! in_array($type, QuizAttemptEvent::FROM_BROWSER, true)) {
                continue;
            }

            $question = isset($event['question_id']) && $paper->contains((int) $event['question_id']) ? (int) $event['question_id'] : null;
            $seconds = isset($event['seconds']) && is_numeric($event['seconds']) ? max(0, min((int) $event['seconds'], 86400)) : null;
            $length = isset($event['length']) && is_numeric($event['length']) ? max(0, min((int) $event['length'], 1000000)) : null;

            $this->record($attempt, $type, $question, $seconds, $length !== null ? ['length' => $length] : null, $ip);
            $kept++;
        }

        return $kept;
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
