<?php

namespace App\Http\Controllers\Client;

use App\Models\Document;
use App\Models\Project;
use App\Models\ProjectMilestone;
use App\Models\ProjectUpdate;
use App\Support\Money;
use App\Support\Table\Column;
use App\Support\Table\Filter;
use App\Support\Table\Table;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * What a client can see of the work we are doing for them.
 *
 * Read only. A client changing a milestone date would be changing a promise
 * they are owed, which is a conversation, not a form field.
 */
class ProjectController extends ClientController
{
    public function index(Request $request): Response|HttpResponse
    {
        $table = $this->table($request);

        if ($export = $table->exportResponse($request)) {
            return $export;
        }

        return Inertia::render('client/projects/Index', [
            'table' => $table->toArray($request),
            'counts' => [
                'all' => Project::query()->forClient($this->client($request))->count(),
                'active' => Project::query()->forClient($this->client($request))->active()->count(),
            ],
        ]);
    }

    public function show(Request $request, int $project): Response
    {
        $record = $this->own($request, Project::query()
            ->forClient($this->client($request))
            ->with(['solution:id,title,slug', 'manager:id,name,avatar_path']), $project);

        return Inertia::render('client/projects/Show', [
            'project' => [
                'id' => $record->id,
                'code' => $record->code,
                'name' => $record->name,
                'summary' => $record->summary,
                'scope' => $record->scope,
                'status' => $record->status,
                'statusLabel' => $record->statusLabel(),
                'phase' => $record->phase,
                'progress' => $record->progress_percent,
                'startDate' => $record->start_date?->format('j M Y'),
                'targetDate' => $record->target_date?->format('j M Y'),
                'deliveredOn' => $record->delivered_on?->format('j M Y'),
                'overdue' => $record->isOverdue(),
                'budget' => $record->budgetLabel(),
                'solution' => $record->solution?->title,
                'manager' => $record->manager?->name,
                'team' => $record->team ?? [],
                'stagingUrl' => $record->staging_url,
                'productionUrl' => $record->production_url,
            ],

            'milestones' => $record->milestones->map(fn (ProjectMilestone $milestone) => [
                'id' => $milestone->id,
                'title' => $milestone->title,
                'description' => $milestone->description,
                'dueDate' => $milestone->due_date?->format('j M Y'),
                'completedAt' => $milestone->completed_at?->format('j M Y'),
                'complete' => $milestone->isComplete(),
                'overdue' => $milestone->isOverdue(),
                'progress' => $milestone->progress_percent,
                'payment' => $milestone->payment_amount ? Money::display($milestone->payment_amount) : null,
            ]),

            'updates' => $record->updates()
                ->visibleToClient()
                ->with('author:id,name')
                ->take(40)
                ->get()
                ->map(fn (ProjectUpdate $update) => [
                    'id' => $update->id,
                    'title' => $update->title,
                    'body' => $update->body,
                    'author' => $update->author?->name ?? 'Unboundbyte',
                    'at' => $update->created_at->format('j M Y, g:i a'),
                    'ago' => $update->created_at->diffForHumans(),
                ]),

            'documents' => $record->documents()
                ->current()
                ->visibleToClient()
                ->latest('id')
                ->take(8)
                ->get()
                ->map(fn (Document $document) => [
                    'id' => $document->id,
                    'name' => $document->name,
                    'size' => $document->sizeLabel(),
                    'kind' => $document->kind(),
                    'at' => $document->created_at->format('j M Y'),
                ]),
        ]);
    }

    protected function table(Request $request): Table
    {
        return Table::for(Project::query()
            ->forClient($this->client($request))
            ->with('solution:id,title'))
            ->searchable(['name', 'code', 'phase'])
            ->sortable(['updated_at'])
            ->defaultSort('-updated_at')
            ->exportName('projects')
            ->columns([
                Column::make('name', 'Project')->sortable(),
                Column::make('code', 'Reference')->sortable(),
                Column::make('status', 'Status'),
                Column::make('phase', 'Phase'),
                Column::make('progress', 'Progress')->numeric(),
                Column::make('target_date', 'Target date')->sortable(),
                Column::make('solution', 'Built on')->hidden(),
            ])
            ->filters([
                Filter::multi('status', collect(Project::STATUSES)
                    ->map(fn (string $status) => [
                        'value' => $status,
                        'label' => str($status)->replace('_', ' ')->title()->toString(),
                    ])->all(), 'Status'),
            ])
            ->transform(fn (Project $project) => [
                'id' => $project->id,
                'name' => $project->name,
                'code' => $project->code,
                'status' => $project->status,
                'statusLabel' => $project->statusLabel(),
                'phase' => $project->phase ?? '—',
                'progress' => $project->progress_percent,
                'target_date' => $project->target_date?->format('j M Y') ?? '—',
                'overdue' => $project->isOverdue(),
                'solution' => $project->solution?->title ?? '—',
            ]);
    }
}
