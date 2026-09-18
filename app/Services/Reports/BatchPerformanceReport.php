<?php

namespace App\Services\Reports;

use App\Models\Batch;
use App\Models\Certificate;
use App\Models\Enrollment;
use App\Services\Lms\ResultCard;
use App\Support\Table\Column;
use Illuminate\Support\Collection;

/**
 * How each batch is actually going.
 *
 * The same result card the student sees, aggregated. Using a second definition
 * of "passing" here would mean a trainer and a student reading different
 * numbers about the same cohort, which is the sort of disagreement nobody ever
 * gets to the bottom of.
 */
class BatchPerformanceReport extends Report
{
    public function __construct(protected ResultCard $resultCard) {}

    public function key(): string
    {
        return 'batch-performance';
    }

    public function title(): string
    {
        return 'Batch performance';
    }

    public function description(): string
    {
        return 'Attendance, marks and pass rate per batch, from the same result card a student sees.';
    }

    public function permission(): ?string
    {
        return 'students.view';
    }

    public function summary(ReportWindow $window): array
    {
        $rows = $this->rows($window);

        return [
            ['label' => 'Batches running', 'value' => (string) $rows->count()],
            [
                'label' => 'Students',
                'value' => (string) $rows->sum('studentsValue'),
            ],
            [
                'label' => 'Average attendance',
                'value' => $rows->isEmpty() ? '—' : round($rows->avg('attendanceValue')).'%',
                'tone' => $rows->avg('attendanceValue') >= 75 ? 'success' : 'warning',
            ],
            [
                'label' => 'Passing so far',
                'value' => $rows->isEmpty() ? '—' : round($rows->avg('passRateValue')).'%',
            ],
        ];
    }

    public function charts(ReportWindow $window): array
    {
        $rows = $this->rows($window);

        return [[
            'id' => 'batches',
            'kind' => 'bar',
            'title' => 'Attendance and pass rate by batch',
            'subtitle' => 'Percentage of the cohort',
            'categories' => $rows->pluck('batch')->all(),
            'series' => [
                [
                    'label' => 'Attendance',
                    'slot' => 1,
                    'values' => $rows->pluck('attendanceValue')->all(),
                    'display' => $rows->pluck('attendance')->all(),
                ],
                [
                    'label' => 'Passing',
                    'slot' => 2,
                    'values' => $rows->pluck('passRateValue')->all(),
                    'display' => $rows->pluck('passRate')->all(),
                ],
            ],
        ]];
    }

    public function columns(): Collection
    {
        return collect([
            Column::make('batch', 'Batch'),
            Column::make('course', 'Course'),
            Column::make('students', 'Students')->numeric()->exportUsing(fn (array $row) => $row['studentsValue']),
            Column::make('attendance', 'Attendance')->numeric()->exportUsing(fn (array $row) => $row['attendanceValue']),
            Column::make('averageMark', 'Average mark')->numeric()->exportUsing(fn (array $row) => $row['averageMarkValue']),
            Column::make('passRate', 'Passing')->numeric()->exportUsing(fn (array $row) => $row['passRateValue']),
            Column::make('certificates', 'Certificates')->numeric(),
            Column::make('atRisk', 'Needing a nudge')->numeric(),
        ]);
    }

    public function rows(ReportWindow $window): Collection
    {
        return Batch::query()
            ->with('course:id,title,pass_percent,minimum_attendance')
            ->whereHas('enrollments')
            ->where(fn ($query) => $query
                ->whereNull('starts_on')
                ->orWhere('starts_on', '<=', $window->to))
            ->get()
            ->map(function (Batch $batch) {
                $enrolments = Enrollment::query()
                    ->where('batch_id', $batch->id)
                    ->active()
                    ->with('course')
                    ->get();

                if ($enrolments->isEmpty()) {
                    return null;
                }

                $cards = $enrolments->map(fn (Enrollment $enrolment) => $this->resultCard->for($enrolment));

                $attendance = $cards->pluck('attendance.percent')->filter(fn ($value) => $value !== null);
                $marks = $cards->pluck('overall')->filter(fn ($value) => $value !== null);
                $passing = $cards->where('passing', true)->count();

                return [
                    'id' => $batch->id,
                    'batch' => $batch->name,
                    'course' => $batch->course?->title ?? '—',
                    'students' => (string) $enrolments->count(),
                    'studentsValue' => $enrolments->count(),
                    'attendance' => $attendance->isEmpty() ? '—' : round($attendance->avg()).'%',
                    'attendanceValue' => $attendance->isEmpty() ? 0 : round($attendance->avg(), 1),
                    'averageMark' => $marks->isEmpty() ? '—' : round($marks->avg()).'%',
                    'averageMarkValue' => $marks->isEmpty() ? 0 : round($marks->avg(), 1),
                    'passRate' => round($passing / $enrolments->count() * 100).'%',
                    'passRateValue' => round($passing / $enrolments->count() * 100, 1),
                    'certificates' => Certificate::query()->where('batch_id', $batch->id)->count(),
                    'atRisk' => $cards->filter(
                        fn (array $card) => $card['shortOnAttendance']
                            || ($card['overall'] !== null && $card['overall'] < $card['passMark']),
                    )->count(),
                ];
            })
            ->filter()
            ->values();
    }
}
