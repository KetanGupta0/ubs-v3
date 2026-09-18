<?php

namespace App\Services\Reports;

use App\Models\Attendance;
use App\Models\Batch;
use App\Models\Enrollment;
use App\Models\LiveSession;
use App\Support\Table\Column;
use Illuminate\Support\Collection;

/**
 * Who is turning up.
 *
 * Per student rather than per batch, because the batch average is exactly the
 * figure that hides the three people who have stopped coming. The classes each
 * of them missed are in the rows, so a phone call can start with something
 * specific.
 */
class AttendanceReport extends Report
{
    public function key(): string
    {
        return 'attendance';
    }

    public function title(): string
    {
        return 'Attendance';
    }

    public function description(): string
    {
        return 'Every student against the classes actually held in the period.';
    }

    public function permission(): ?string
    {
        return 'students.view';
    }

    public function controls(): array
    {
        return [[
            'key' => 'batch_id',
            'label' => 'Batch',
            'type' => 'select',
            'options' => Batch::query()
                ->orderByDesc('starts_on')
                ->take(60)
                ->get(['id', 'name'])
                ->map(fn ($batch) => ['value' => (string) $batch->id, 'label' => $batch->name])
                ->all(),
        ]];
    }

    public function summary(ReportWindow $window): array
    {
        $rows = $this->rows($window);
        $held = $this->sessions($window)->count();

        return [
            ['label' => 'Classes held', 'value' => (string) $held],
            ['label' => 'Students', 'value' => (string) $rows->count()],
            [
                'label' => 'Average attendance',
                'value' => $rows->isEmpty() ? '—' : round($rows->avg('percentValue')).'%',
                'tone' => $rows->avg('percentValue') >= 75 ? 'success' : 'warning',
            ],
            [
                'label' => 'Below three quarters',
                'value' => (string) $rows->where('percentValue', '<', 75)->count(),
                'hint' => 'The ones worth a call',
                'tone' => $rows->where('percentValue', '<', 75)->isEmpty() ? 'success' : 'warning',
            ],
        ];
    }

    public function charts(ReportWindow $window): array
    {
        $sessions = $this->sessions($window)->sortBy('scheduled_at')->values();

        if ($sessions->isEmpty()) {
            return [];
        }

        $marks = Attendance::query()
            ->whereIn('live_session_id', $sessions->pluck('id'))
            ->get()
            ->groupBy('live_session_id');

        return [[
            'id' => 'per-class',
            'kind' => 'line',
            'title' => 'Turnout, class by class',
            'subtitle' => 'How many were present or late',
            'categories' => $sessions->map(fn (LiveSession $session) => $session->scheduled_at->format('j M'))->all(),
            'series' => [[
                'label' => 'Present',
                'slot' => 1,
                'values' => $sessions->map(
                    fn (LiveSession $session) => ($marks[$session->id] ?? collect())
                        ->filter(fn (Attendance $mark) => $mark->counts())
                        ->count(),
                )->all(),
            ]],
        ]];
    }

    public function columns(): Collection
    {
        return collect([
            Column::make('student', 'Student'),
            Column::make('batch', 'Batch'),
            Column::make('attended', 'Attended')->numeric(),
            Column::make('held', 'Classes held')->numeric(),
            Column::make('percent', 'Attendance')->numeric()->exportUsing(fn (array $row) => $row['percentValue']),
            Column::make('missed', 'Classes missed'),
        ]);
    }

    public function rows(ReportWindow $window): Collection
    {
        $sessions = $this->sessions($window);

        if ($sessions->isEmpty()) {
            return collect();
        }

        $byBatch = $sessions->groupBy('batch_id');

        $enrolments = Enrollment::query()
            ->whereIn('batch_id', $byBatch->keys())
            ->active()
            ->with(['user:id,name', 'batch:id,name'])
            ->get();

        $marks = Attendance::query()
            ->whereIn('live_session_id', $sessions->pluck('id'))
            ->whereIn('user_id', $enrolments->pluck('user_id'))
            ->get()
            ->groupBy('user_id');

        return $enrolments->map(function (Enrollment $enrolment) use ($byBatch, $marks) {
            $held = ($byBatch[$enrolment->batch_id] ?? collect());
            $mine = ($marks[$enrolment->user_id] ?? collect())->keyBy('live_session_id');

            $attended = $held->filter(fn (LiveSession $session) => ($mine[$session->id] ?? null)?->counts() ?? false);
            $missed = $held->reject(fn (LiveSession $session) => ($mine[$session->id] ?? null)?->counts() ?? false);

            $percent = $held->isEmpty() ? 0 : round($attended->count() / $held->count() * 100, 1);

            return [
                'id' => $enrolment->user_id,
                'student' => $enrolment->user?->name ?? '—',
                'batch' => $enrolment->batch?->name ?? '—',
                'attended' => $attended->count(),
                'held' => $held->count(),
                'percent' => $percent.'%',
                'percentValue' => $percent,
                'missed' => $missed->isEmpty()
                    ? '—'
                    : $missed->take(4)->map(fn (LiveSession $session) => $session->scheduled_at->format('j M'))->join(', ')
                        .($missed->count() > 4 ? ' and '.($missed->count() - 4).' more' : ''),
                'low' => $percent < 75,
            ];
        })->sortBy('percentValue')->values();
    }

    protected function sessions(ReportWindow $window): Collection
    {
        return LiveSession::query()
            ->whereBetween('scheduled_at', [$window->from, $window->to])
            ->where('scheduled_at', '<', now())
            ->whereNot('status', 'cancelled')
            ->when($window->filter('batch_id'), fn ($query, $batchId) => $query->where('batch_id', $batchId))
            ->get();
    }
}
