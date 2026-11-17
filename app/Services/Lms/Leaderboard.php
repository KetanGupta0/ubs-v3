<?php

namespace App\Services\Lms;

use App\Models\Batch;
use App\Models\Course;
use App\Models\LeaderboardPoint;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who is ahead: on a batch, on a batch this week, on a course, and overall.
 *
 * The batch is the board that matters, because a student's own cohort is a
 * comparison they can act on. The weekly board exists so that somebody who
 * joined late can still top something. Points are the ones `Activity` awards,
 * once per reason, so no board can be farmed.
 *
 * A student who chose to hide their name is shown to other students as "A
 * student", in their real place: hiding a name never moves anybody else.
 * Staff pass `$revealNames` and always see who is who.
 */
class Leaderboard
{
    public const BOARDS = [
        'batch' => 'My batch',
        'week' => 'This week',
        'course' => 'My course',
        'all' => 'Everybody',
    ];

    /** @return array<int, array<string, mixed>> */
    public function forBatch(Batch $batch, int $limit = 50, bool $revealNames = false, ?User $viewer = null): array
    {
        return $this->board($this->batchPoints($batch), $limit, $revealNames, $viewer);
    }

    /** @return array<int, array<string, mixed>> */
    public function forBatchThisWeek(Batch $batch, int $limit = 50, bool $revealNames = false, ?User $viewer = null): array
    {
        return $this->board($this->weekPoints($batch), $limit, $revealNames, $viewer);
    }

    /** @return array<int, array<string, mixed>> */
    public function forCourse(Course $course, int $limit = 50, bool $revealNames = false, ?User $viewer = null): array
    {
        return $this->board($this->coursePoints($course), $limit, $revealNames, $viewer);
    }

    /** @return array<int, array<string, mixed>> */
    public function allTime(int $limit = 50, bool $revealNames = false, ?User $viewer = null): array
    {
        return $this->board(LeaderboardPoint::query(), $limit, $revealNames, $viewer);
    }

    /** @return array<string, mixed>|null */
    public function standingFor(User $student, Batch $batch): ?array
    {
        return $this->standing($this->batchPoints($batch), $student);
    }

    /** @return array<string, mixed>|null */
    public function standingThisWeek(User $student, Batch $batch): ?array
    {
        return $this->standing($this->weekPoints($batch), $student);
    }

    /** @return array<string, mixed>|null */
    public function standingOnCourse(User $student, Course $course): ?array
    {
        return $this->standing($this->coursePoints($course), $student);
    }

    /** @return array<string, mixed>|null */
    public function standingOverall(User $student): ?array
    {
        return $this->standing(LeaderboardPoint::query(), $student);
    }

    public function totalFor(User $student, ?int $batchId = null): int
    {
        return (int) LeaderboardPoint::query()
            ->where('user_id', $student->id)
            ->when($batchId, fn ($query) => $query->where('batch_id', $batchId))
            ->sum('points');
    }

    /* -------------------------------------------------------------------- */

    protected function batchPoints(Batch $batch): Builder
    {
        return LeaderboardPoint::query()->where('batch_id', $batch->id);
    }

    protected function weekPoints(Batch $batch): Builder
    {
        return $this->batchPoints($batch)->where('awarded_at', '>=', now()->startOfWeek());
    }

    protected function coursePoints(Course $course): Builder
    {
        return LeaderboardPoint::query()->where('course_id', $course->id);
    }

    /** @return array<int, array<string, mixed>> */
    protected function board(Builder $points, int $limit, bool $revealNames, ?User $viewer): array
    {
        $totals = (clone $points)
            ->selectRaw('user_id, sum(points) as total')
            ->groupBy('user_id')
            ->orderByDesc('total')
            ->orderBy('user_id')
            ->limit($limit)
            ->pluck('total', 'user_id');

        if ($totals->isEmpty()) {
            return [];
        }

        $students = User::query()
            ->whereIn('id', $totals->keys())
            ->get(['id', 'name', 'avatar_path', 'show_on_leaderboard'])
            ->keyBy('id');

        $rank = 0;
        $lastTotal = null;
        $seen = 0;

        return $totals->map(function ($total, $userId) use ($students, $revealNames, $viewer, &$rank, &$lastTotal, &$seen) {
            $seen++;

            // Equal points share a rank, and the next one skips. Two people on
            // 180 are both second; nobody is second and a half.
            if ((int) $total !== $lastTotal) {
                $rank = $seen;
                $lastTotal = (int) $total;
            }

            $student = $students[$userId] ?? null;
            $hidden = ! $revealNames && $student && ! $student->show_on_leaderboard && $viewer?->id !== (int) $userId;

            return [
                'rank' => $rank,
                'userId' => $hidden ? null : (int) $userId,
                'name' => $hidden || ! $student ? 'A student' : $student->name,
                'avatarUrl' => $hidden ? null : $student?->avatar_url,
                'points' => (int) $total,
                'hidden' => $hidden,
                'me' => $viewer?->id === (int) $userId,
            ];
        })->values()->all();
    }

    /**
     * A student's place without building the whole board: how many have more
     * points, plus one.
     *
     * @return array<string, mixed>|null
     */
    protected function standing(Builder $points, User $student): ?array
    {
        $mine = (int) (clone $points)->where('user_id', $student->id)->sum('points');

        if ($mine === 0 && ! (clone $points)->where('user_id', $student->id)->exists()) {
            return null;
        }

        $totals = (clone $points)->selectRaw('user_id, sum(points) as total')->groupBy('user_id');

        $ahead = LeaderboardPoint::query()->fromSub($totals, 'totals')->where('total', '>', $mine)->count();
        $outOf = LeaderboardPoint::query()->fromSub($totals, 'totals')->count();
        $top = (int) LeaderboardPoint::query()->fromSub($totals, 'totals')->max('total');

        return [
            'rank' => $ahead + 1,
            'userId' => $student->id,
            'name' => $student->name,
            'points' => $mine,
            'outOf' => $outOf,
            'topPoints' => $top,
        ];
    }
}
