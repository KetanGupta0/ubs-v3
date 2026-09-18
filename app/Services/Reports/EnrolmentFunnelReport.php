<?php

namespace App\Services\Reports;

use App\Models\Certificate;
use App\Models\Enrollment;
use App\Models\Lead;
use App\Support\Table\Column;
use Illuminate\Support\Collection;

/**
 * Where people fall out between asking and finishing.
 *
 * Five ordered stages, so the chart uses one hue getting darker rather than
 * five identities: the order is the message, and colouring the stages as
 * separate things would hide it.
 *
 * The drop between two stages is the number worth looking at. A funnel that
 * only shows totals lets a collapse at one step hide behind a healthy total at
 * the next.
 */
class EnrolmentFunnelReport extends Report
{
    public function key(): string
    {
        return 'enrolment-funnel';
    }

    public function title(): string
    {
        return 'Enrolment funnel';
    }

    public function description(): string
    {
        return 'Enquiry through to certificate, and where people stop.';
    }

    public function permission(): ?string
    {
        return 'students.view';
    }

    public function summary(ReportWindow $window): array
    {
        $stages = $this->stages($window);

        $enquiries = $stages[0]['count'];
        $enrolled = $stages[2]['count'];

        return [
            ['label' => 'Training enquiries', 'value' => (string) $enquiries],
            [
                'label' => 'Enrolled',
                'value' => (string) $enrolled,
                'hint' => $enquiries > 0 ? round($enrolled / $enquiries * 100).'% of enquiries' : null,
            ],
            [
                'label' => 'Fees settled',
                'value' => (string) $stages[3]['count'],
                'tone' => 'success',
            ],
            [
                'label' => 'Finished',
                'value' => (string) $stages[4]['count'],
                'hint' => 'Certificate issued',
            ],
        ];
    }

    public function charts(ReportWindow $window): array
    {
        $stages = collect($this->stages($window));

        return [[
            'id' => 'funnel',
            'kind' => 'bar',
            // Ordered stages: one hue, getting darker.
            'ramp' => true,
            'horizontal' => true,
            'title' => 'From enquiry to certificate',
            'subtitle' => 'People at each stage in this period',
            'categories' => $stages->pluck('label')->all(),
            'series' => [[
                'label' => 'People',
                'slot' => 1,
                'values' => $stages->pluck('count')->all(),
            ]],
        ]];
    }

    public function columns(): Collection
    {
        return collect([
            Column::make('stage', 'Stage'),
            Column::make('people', 'People')->numeric()->exportUsing(fn (array $row) => $row['peopleValue']),
            Column::make('ofPrevious', 'Of the stage before'),
            Column::make('ofStart', 'Of all enquiries'),
            Column::make('lost', 'Lost here')->numeric(),
        ]);
    }

    public function rows(ReportWindow $window): Collection
    {
        $stages = $this->stages($window);
        $start = $stages[0]['count'];

        return collect($stages)->map(function (array $stage, int $index) use ($stages, $start) {
            $previous = $index === 0 ? null : $stages[$index - 1]['count'];

            return [
                'stage' => $stage['label'],
                'people' => (string) $stage['count'],
                'peopleValue' => $stage['count'],
                'ofPrevious' => $this->share($stage['count'], $previous),
                // Nobody enquired and five enrolled is a real situation — a
                // college sending a batch straight in. "500% of enquiries" is
                // not what that means, so it is left blank rather than
                // dividing by a base that is not there.
                'ofStart' => $index === 0 ? '—' : $this->share($stage['count'], $start),
                'lost' => $previous === null ? 0 : max(0, $previous - $stage['count']),
            ];
        });
    }

    protected function share(int $count, ?int $of): string
    {
        if ($of === null || $of <= 0) {
            return '—';
        }

        return round($count / $of * 100).'%';
    }

    /** @return array<int, array{label: string, count: int}> */
    protected function stages(ReportWindow $window): array
    {
        $between = [$window->from, $window->to];

        $enquiries = Lead::query()
            ->whereBetween('created_at', $between)
            ->whereIn('interest', ['training', 'internship', 'college'])
            ->count();

        $contacted = Lead::query()
            ->whereBetween('created_at', $between)
            ->whereIn('interest', ['training', 'internship', 'college'])
            ->whereNot('status', 'new')
            ->count();

        $enrolments = Enrollment::query()->whereBetween('enrolled_at', $between);

        return [
            ['label' => 'Enquired', 'count' => $enquiries],
            ['label' => 'Answered', 'count' => $contacted],
            ['label' => 'Enrolled', 'count' => (clone $enrolments)->count()],
            ['label' => 'Fee settled', 'count' => (clone $enrolments)->where('has_paid', true)->count()],
            [
                'label' => 'Certificate issued',
                'count' => Certificate::query()->whereBetween('issued_at', $between)->count(),
            ],
        ];
    }
}
