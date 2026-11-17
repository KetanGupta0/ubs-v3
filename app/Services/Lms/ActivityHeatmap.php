<?php

namespace App\Services\Lms;

use App\Models\ActivityLog;
use App\Models\Batch;
use App\Models\Enrollment;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Activity by day, for the heatmaps: a student's own, and a batch's.
 *
 * A student's square for a day counts the things they did; a batch's counts
 * how many of its students did anything at all, which is the question a
 * trainer is actually asking ("who turned up this week?"). Both cover whole
 * weeks, Monday to Sunday, ending this week, so the grid lines up.
 */
class ActivityHeatmap
{
    public const WEEKS = 12;

    /** @return array<int, array{date: string, label: string, count: int}> */
    public function forStudent(User $student, int $weeks = self::WEEKS): array
    {
        [$from, $to] = $this->window($weeks);

        $counts = ActivityLog::query()
            ->where('user_id', $student->id)
            ->whereBetween('occurred_at', [$from, $to])
            ->selectRaw('date(occurred_at) as day, count(*) as total')
            ->groupBy('day')
            ->pluck('total', 'day');

        return $this->days($from, $to, $counts->all());
    }

    /** @return array<int, array{date: string, label: string, count: int}> */
    public function forBatch(Batch $batch, int $weeks = self::WEEKS): array
    {
        [$from, $to] = $this->window($weeks);

        $counts = ActivityLog::query()
            ->whereIn('user_id', Enrollment::query()->where('batch_id', $batch->id)->where('status', 'active')->select('user_id'))
            ->whereBetween('occurred_at', [$from, $to])
            ->selectRaw('date(occurred_at) as day, count(distinct user_id) as total')
            ->groupBy('day')
            ->pluck('total', 'day');

        return $this->days($from, $to, $counts->all());
    }

    /** @return array{0: Carbon, 1: Carbon} */
    protected function window(int $weeks): array
    {
        $to = now()->endOfWeek();

        return [$to->copy()->subWeeks($weeks)->addSecond()->startOfDay(), $to];
    }

    /**
     * @param  array<string, int>  $counts
     * @return array<int, array{date: string, label: string, count: int}>
     */
    protected function days(Carbon $from, Carbon $to, array $counts): array
    {
        $days = [];

        for ($day = $from->copy(); $day->lte($to); $day->addDay()) {
            $key = $day->toDateString();
            $days[] = [
                'date' => $key,
                'label' => $day->format('D j M'),
                'count' => (int) ($counts[$key] ?? 0),
                'future' => $day->isAfter(today()),
            ];
        }

        return $days;
    }
}
