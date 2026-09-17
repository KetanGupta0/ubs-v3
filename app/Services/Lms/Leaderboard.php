<?php

namespace App\Services\Lms;

use App\Models\Batch;
use App\Models\LeaderboardPoint;
use App\Models\User;

/**
 * Who is ahead, on one batch.
 *
 * Scoped to a batch rather than the whole platform on purpose: a student who
 * joined last week being bottom of a list of everybody who ever enrolled tells
 * them nothing except that they are behind. Their own cohort is a comparison
 * they can actually act on.
 */
class Leaderboard
{
    /** @return array<int, array<string, mixed>> */
    public function forBatch(Batch $batch, int $limit = 50): array
    {
        $totals = LeaderboardPoint::query()
            ->where('batch_id', $batch->id)
            ->selectRaw('user_id, sum(points) as total')
            ->groupBy('user_id')
            ->orderByDesc('total')
            ->limit($limit)
            ->pluck('total', 'user_id');

        if ($totals->isEmpty()) {
            return [];
        }

        $students = User::query()
            ->whereIn('id', $totals->keys())
            ->get(['id', 'name', 'avatar_path'])
            ->keyBy('id');

        $rank = 0;
        $lastTotal = null;
        $seen = 0;

        return $totals->map(function ($total, $userId) use ($students, &$rank, &$lastTotal, &$seen) {
            $seen++;

            // Equal points share a rank, and the next one skips. Two people on
            // 180 are both second; nobody is second and a half.
            if ((int) $total !== $lastTotal) {
                $rank = $seen;
                $lastTotal = (int) $total;
            }

            $student = $students[$userId] ?? null;

            return [
                'rank' => $rank,
                'userId' => $userId,
                'name' => $student?->name ?? 'A student',
                'avatarUrl' => $student?->avatar_url,
                'points' => (int) $total,
            ];
        })->values()->all();
    }

    /** @return array<string, mixed>|null */
    public function standingFor(User $student, Batch $batch): ?array
    {
        $board = $this->forBatch($batch, 500);

        $mine = collect($board)->firstWhere('userId', $student->id);

        if (! $mine) {
            return null;
        }

        return [
            ...$mine,
            'outOf' => count($board),
            'topPoints' => $board[0]['points'] ?? 0,
        ];
    }

    public function totalFor(User $student, ?int $batchId = null): int
    {
        return (int) LeaderboardPoint::query()
            ->where('user_id', $student->id)
            ->when($batchId, fn ($query) => $query->where('batch_id', $batchId))
            ->sum('points');
    }
}
