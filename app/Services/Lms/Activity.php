<?php

namespace App\Services\Lms;

use App\Models\ActivityLog;
use App\Models\LeaderboardPoint;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * What a student did, and what it was worth.
 *
 * Points and the activity log are written together because they answer the same
 * event from two sides: the log is the student's own record, the points are the
 * comparison. Keeping them apart guarantees they eventually disagree.
 *
 * Points are awarded once per thing. Watching the same lesson twice is not
 * twice the points, and a leaderboard that can be farmed is not a leaderboard.
 */
class Activity
{
    /** What each kind of effort is worth. */
    public const POINTS = [
        'lesson.completed' => 10,
        'session.attended' => 20,
        'quiz.passed' => 30,
        'quiz.distinction' => 15,
        'assignment.submitted' => 25,
        'assignment.onTime' => 10,
        'assignment.marked' => 0,
        'course.completed' => 100,
    ];

    public function record(
        User $student,
        string $action,
        ?Model $subject = null,
        array $meta = [],
        ?int $courseId = null,
        ?int $batchId = null,
    ): ActivityLog {
        return ActivityLog::query()->create([
            'user_id' => $student->id,
            'course_id' => $courseId,
            'batch_id' => $batchId,
            'action' => $action,
            'subject_type' => $subject ? $subject::class : null,
            'subject_id' => $subject?->getKey(),
            'meta' => $meta ?: null,
            'occurred_at' => now(),
        ]);
    }

    /**
     * Award points, once, for a given reason.
     *
     * Returns null when this student has already been paid for this exact
     * thing, which is the normal case on a second visit.
     */
    public function award(
        User $student,
        string $source,
        ?Model $reason = null,
        ?int $points = null,
        ?int $courseId = null,
        ?int $batchId = null,
    ): ?LeaderboardPoint {
        $value = $points ?? self::POINTS[$source] ?? 0;

        if ($value === 0) {
            return null;
        }

        $already = LeaderboardPoint::query()
            ->where('user_id', $student->id)
            ->where('source', $source)
            ->where('reason_type', $reason ? $reason::class : null)
            ->where('reason_id', $reason?->getKey())
            ->exists();

        if ($already) {
            return null;
        }

        return LeaderboardPoint::query()->create([
            'user_id' => $student->id,
            'course_id' => $courseId,
            'batch_id' => $batchId,
            'source' => $source,
            'reason_type' => $reason ? $reason::class : null,
            'reason_id' => $reason?->getKey(),
            'points' => $value,
            'awarded_at' => now(),
        ]);
    }

    /** Record and award in one call, for the common case where both apply. */
    public function did(
        User $student,
        string $action,
        ?Model $subject = null,
        array $meta = [],
        ?int $courseId = null,
        ?int $batchId = null,
        ?int $points = null,
    ): void {
        $this->record($student, $action, $subject, $meta, $courseId, $batchId);
        $this->award($student, $action, $subject, $points, $courseId, $batchId);
    }

    /** How many days in a row this student has done something. */
    public function streak(User $student): int
    {
        $days = ActivityLog::query()
            ->where('user_id', $student->id)
            ->where('occurred_at', '>=', now()->subDays(60))
            ->get(['occurred_at'])
            ->map(fn (ActivityLog $log) => $log->occurred_at->toDateString())
            ->unique()
            ->sortDesc()
            ->values();

        if ($days->isEmpty()) {
            return 0;
        }

        // A streak that has not been broken today is still alive: somebody
        // looking at nine in the evening has not yet failed to study.
        $cursor = today();

        if ($days->first() !== $cursor->toDateString()) {
            $cursor = $cursor->subDay();

            if ($days->first() !== $cursor->toDateString()) {
                return 0;
            }
        }

        $streak = 0;

        foreach ($days as $day) {
            if ($day !== $cursor->toDateString()) {
                break;
            }

            $streak++;
            $cursor = $cursor->subDay();
        }

        return $streak;
    }
}
