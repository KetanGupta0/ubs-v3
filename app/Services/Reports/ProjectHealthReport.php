<?php

namespace App\Services\Reports;

use App\Models\Project;
use App\Models\SupportTicket;
use App\Support\Table\Column;
use Illuminate\Support\Collection;

/**
 * Which projects are in trouble.
 *
 * Not a league table of progress percentages. A project is in trouble when it
 * is behind its target date, when milestones have slipped, or when its tickets
 * are breaching the promise we sold — and any one of those is worth a call
 * before the client makes it.
 */
class ProjectHealthReport extends Report
{
    public function key(): string
    {
        return 'project-health';
    }

    public function title(): string
    {
        return 'Project health';
    }

    public function description(): string
    {
        return 'Where each project stands against its dates, its milestones and its tickets.';
    }

    public function permission(): ?string
    {
        return 'projects.view';
    }

    public function summary(ReportWindow $window): array
    {
        $projects = $this->projects();
        $atRisk = $projects->filter(fn (Project $project) => $this->risks($project) !== []);

        return [
            ['label' => 'Live projects', 'value' => (string) $projects->count()],
            [
                'label' => 'Needing attention',
                'value' => (string) $atRisk->count(),
                'hint' => $atRisk->isEmpty() ? 'Nothing overdue or breaching' : 'Behind, slipping or breaching',
                'tone' => $atRisk->isEmpty() ? 'success' : 'warning',
            ],
            [
                'label' => 'Average progress',
                'value' => $projects->isEmpty() ? '—' : round($projects->avg('progress_percent')).'%',
            ],
            [
                'label' => 'Open tickets',
                'value' => (string) SupportTicket::query()
                    ->whereIn('project_id', $projects->pluck('id'))
                    ->open()
                    ->count(),
            ],
        ];
    }

    public function charts(ReportWindow $window): array
    {
        $projects = $this->projects();

        $bands = [
            'Not started' => fn (Project $p) => $p->progress_percent === 0,
            'Under a quarter' => fn (Project $p) => $p->progress_percent > 0 && $p->progress_percent < 25,
            'A quarter to half' => fn (Project $p) => $p->progress_percent >= 25 && $p->progress_percent < 50,
            'Half to three quarters' => fn (Project $p) => $p->progress_percent >= 50 && $p->progress_percent < 75,
            'Nearly there' => fn (Project $p) => $p->progress_percent >= 75,
        ];

        return [[
            'id' => 'progress',
            'kind' => 'bar',
            'ramp' => true,
            'title' => 'How far along the live projects are',
            'subtitle' => 'Counted by completion band',
            'categories' => array_keys($bands),
            'series' => [[
                'label' => 'Projects',
                'slot' => 1,
                'values' => collect($bands)->map(fn ($test) => $projects->filter($test)->count())->values()->all(),
            ]],
        ]];
    }

    public function columns(): Collection
    {
        return collect([
            Column::make('name', 'Project'),
            Column::make('client', 'Client'),
            Column::make('status', 'Status'),
            Column::make('progress', 'Progress')->numeric()->exportUsing(fn (array $row) => $row['progressValue']),
            Column::make('target', 'Target date'),
            Column::make('milestones', 'Milestones'),
            Column::make('tickets', 'Open tickets')->numeric(),
            Column::make('concern', 'Needs attention because'),
        ]);
    }

    public function rows(ReportWindow $window): Collection
    {
        $projects = $this->projects();

        $openTickets = SupportTicket::query()
            ->whereIn('project_id', $projects->pluck('id'))
            ->open()
            ->get()
            ->groupBy('project_id');

        return $projects->map(function (Project $project) use ($openTickets) {
            $milestones = $project->milestones;
            $done = $milestones->whereNotNull('completed_at')->count();
            $risks = $this->risks($project, $openTickets[$project->id] ?? collect());

            return [
                'id' => $project->id,
                'name' => $project->name,
                'client' => $project->client?->name ?? '—',
                'status' => $project->statusLabel(),
                'progress' => $project->progress_percent.'%',
                'progressValue' => $project->progress_percent,
                'target' => $project->target_date?->format('j M Y') ?? '—',
                'milestones' => $milestones->isEmpty() ? '—' : "{$done} of {$milestones->count()} done",
                'tickets' => ($openTickets[$project->id] ?? collect())->count(),
                'concern' => $risks === [] ? '—' : implode('; ', $risks),
                'atRisk' => $risks !== [],
            ];
        })->sortByDesc('atRisk')->values();
    }

    protected function projects(): Collection
    {
        return Project::query()
            ->active()
            ->with(['client:id,name', 'milestones'])
            ->get();
    }

    /** @return array<int, string> */
    protected function risks(Project $project, ?Collection $tickets = null): array
    {
        $risks = [];

        if ($project->isOverdue()) {
            $risks[] = 'past its target date';
        }

        $slipped = $project->milestones
            ->whereNull('completed_at')
            ->filter(fn ($milestone) => $milestone->due_date?->isPast())
            ->count();

        if ($slipped > 0) {
            $risks[] = $slipped.' '.str('milestone')->plural($slipped).' overdue';
        }

        $breaching = ($tickets ?? SupportTicket::query()->where('project_id', $project->id)->open()->get())
            ->filter(fn (SupportTicket $ticket) => $ticket->breachedResolution())
            ->count();

        if ($breaching > 0) {
            $risks[] = $breaching.' '.str('ticket')->plural($breaching).' past the promised time';
        }

        return $risks;
    }
}
