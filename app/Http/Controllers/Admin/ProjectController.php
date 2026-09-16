<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\ProjectMilestone;
use App\Models\ProjectUpdate;
use App\Models\Solution;
use App\Models\User;
use App\Notifications\ProjectUpdated;
use App\Services\Admin\Auditor;
use App\Support\Money;
use App\Support\Table\Column;
use App\Support\Table\Filter;
use App\Support\Table\Table;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Projects, their milestones and their timeline.
 *
 * An update is written here and read by the client, so the visibility switch on
 * each entry is the whole point of the screen: a delivery team needs somewhere
 * to write "the client's API is down again" that is not the client's timeline.
 */
class ProjectController extends Controller
{
    public function index(Request $request): Response|HttpResponse
    {
        $table = $this->table();

        if ($export = $table->exportResponse($request)) {
            return $export;
        }

        return Inertia::render('admin/projects/Index', [
            'table' => $table->toArray($request),
            'counts' => [
                'all' => Project::query()->count(),
                'active' => Project::query()->active()->count(),
                'overdue' => Project::query()->active()->whereDate('target_date', '<', today())->count(),
            ],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/projects/Form', [
            'project' => null,
            ...$this->options(),
        ]);
    }

    public function store(Request $request, Auditor $auditor): RedirectResponse
    {
        $project = Project::query()->create($this->validated($request));
        $auditor->created($project, $project->name);

        return redirect()
            ->route('admin.projects.show', $project->id)
            ->with('success', "Project {$project->code} created.");
    }

    public function show(Request $request, Project $project): Response
    {
        $project->load(['client:id,name,email', 'manager:id,name', 'solution:id,title', 'milestones']);

        return Inertia::render('admin/projects/Show', [
            'project' => [
                'id' => $project->id,
                'code' => $project->code,
                'name' => $project->name,
                'summary' => $project->summary,
                'status' => $project->status,
                'statusLabel' => $project->statusLabel(),
                'phase' => $project->phase,
                'progress' => $project->progress_percent,
                'startDate' => $project->start_date?->format('j M Y'),
                'targetDate' => $project->target_date?->format('j M Y'),
                'overdue' => $project->isOverdue(),
                'budget' => $project->budgetLabel(),
                'client' => ['id' => $project->client_id, 'name' => $project->client->name],
                'manager' => $project->manager?->name,
                'solution' => $project->solution?->title,
                'team' => $project->team ?? [],
                'stagingUrl' => $project->staging_url,
                'productionUrl' => $project->production_url,
            ],
            'milestones' => $project->milestones->map(fn (ProjectMilestone $milestone) => [
                'id' => $milestone->id,
                'title' => $milestone->title,
                'description' => $milestone->description,
                // Two shapes on purpose: the ISO date feeds the date input in
                // the edit dialog, the readable one is what the list shows.
                'dueDate' => $milestone->due_date?->toDateString(),
                'dueDateLabel' => $milestone->due_date?->format('j M Y'),
                'completedAt' => $milestone->completed_at?->format('j M Y'),
                'complete' => $milestone->isComplete(),
                'overdue' => $milestone->isOverdue(),
                'progress' => $milestone->progress_percent,
                'payment' => $milestone->payment_amount ? Money::display($milestone->payment_amount) : null,
                'paymentValue' => $milestone->payment_amount ? $milestone->payment_amount / 100 : null,
            ]),
            'updates' => $project->updates()
                ->with('author:id,name')
                ->take(50)
                ->get()
                ->map(fn (ProjectUpdate $update) => [
                    'id' => $update->id,
                    'title' => $update->title,
                    'body' => $update->body,
                    'author' => $update->author?->name ?? 'System',
                    'visibleToClient' => $update->visible_to_client,
                    'at' => $update->created_at->format('j M Y, g:i a'),
                    'ago' => $update->created_at->diffForHumans(),
                ]),
            'statuses' => Project::STATUSES,
        ]);
    }

    public function edit(Project $project): Response
    {
        return Inertia::render('admin/projects/Form', [
            'project' => [
                'id' => $project->id,
                'code' => $project->code,
                'client_id' => $project->client_id,
                'name' => $project->name,
                'solution_id' => $project->solution_id,
                'summary' => $project->summary,
                'scope' => $project->scope,
                'status' => $project->status,
                'phase' => $project->phase,
                'progress_percent' => $project->progress_percent,
                'start_date' => $project->start_date?->toDateString(),
                'target_date' => $project->target_date?->toDateString(),
                'delivered_on' => $project->delivered_on?->toDateString(),
                'budget' => $project->budget ? $project->budget / 100 : null,
                'manager_id' => $project->manager_id,
                'team' => $project->team ?? [],
                'repository_url' => $project->repository_url,
                'staging_url' => $project->staging_url,
                'production_url' => $project->production_url,
            ],
            ...$this->options(),
        ]);
    }

    public function update(Request $request, Project $project, Auditor $auditor): RedirectResponse
    {
        $project->fill($this->validated($request, $project));
        $auditor->updated($project, label: $project->name);
        $project->save();

        return back()->with('success', 'Project saved.');
    }

    public function destroy(Project $project, Auditor $auditor): RedirectResponse
    {
        $auditor->deleted($project, $project->name);
        $project->delete();

        return redirect()->route('admin.projects.index')->with('success', 'Project removed.');
    }

    /* ----------------------------------------------------------- milestones */

    public function storeMilestone(Request $request, Project $project): RedirectResponse
    {
        $project->milestones()->create($this->milestoneRules($request) + [
            'sort_order' => ($project->milestones()->max('sort_order') ?? -1) + 1,
        ]);

        return back()->with('success', 'Milestone added.');
    }

    public function updateMilestone(Request $request, Project $project, ProjectMilestone $milestone): RedirectResponse
    {
        abort_unless($milestone->project_id === $project->id, 404);

        $milestone->fill($this->milestoneRules($request))->save();

        return back()->with('success', 'Milestone saved.');
    }

    public function completeMilestone(Request $request, Project $project, ProjectMilestone $milestone): RedirectResponse
    {
        abort_unless($milestone->project_id === $project->id, 404);

        $done = $milestone->completed_at === null;

        $milestone->forceFill([
            'completed_at' => $done ? now() : null,
            'progress_percent' => $done ? 100 : $milestone->progress_percent,
        ])->save();

        return back()->with('success', $done ? 'Milestone marked done.' : 'Milestone reopened.');
    }

    public function destroyMilestone(Project $project, ProjectMilestone $milestone): RedirectResponse
    {
        abort_unless($milestone->project_id === $project->id, 404);

        $milestone->delete();

        return back()->with('success', 'Milestone removed.');
    }

    /* -------------------------------------------------------------- updates */

    public function storeUpdate(Request $request, Project $project): RedirectResponse
    {
        $validated = $this->validatedInput($request, [
            'title' => ['nullable', 'string', 'max:160'],
            'body' => ['required', 'string', 'max:5000'],
            'visible_to_client' => ['boolean'],
            'notify' => ['boolean'],
        ]);

        $update = $project->updates()->create([
            'author_id' => $request->user()->id,
            'title' => $validated['title'] ?? null,
            'body' => $validated['body'],
            'visible_to_client' => $validated['visible_to_client'] ?? true,
        ]);

        // Only a client visible update can be emailed to the client, whatever
        // the notify box says. An internal note is internal.
        if (($validated['notify'] ?? false) && $update->visible_to_client) {
            $project->client->notify(new ProjectUpdated($update));
        }

        return back()->with('success', 'Update posted.');
    }

    public function destroyUpdate(Project $project, ProjectUpdate $update): RedirectResponse
    {
        abort_unless($update->project_id === $project->id, 404);

        $update->delete();

        return back()->with('success', 'Update removed.');
    }

    /* -------------------------------------------------------------- helpers */

    /** @return array<string, mixed> */
    protected function validated(Request $request, ?Project $project = null): array
    {
        $validated = $this->validatedInput($request, [
            'client_id' => ['required', 'integer', Rule::exists('users', 'id')->where('role', Role::Client->value)],
            'name' => ['required', 'string', 'max:160'],
            'solution_id' => ['nullable', 'integer', Rule::exists('solutions', 'id')],
            'summary' => ['nullable', 'string', 'max:500'],
            'scope' => ['nullable', 'string', 'max:20000'],
            'status' => ['required', Rule::in(Project::STATUSES)],
            'phase' => ['nullable', 'string', 'max:40'],
            'progress_percent' => ['required', 'integer', 'min:0', 'max:100'],
            'start_date' => ['nullable', 'date'],
            'target_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'delivered_on' => ['nullable', 'date'],
            'budget' => ['nullable', 'numeric', 'min:0'],
            'manager_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('role', Role::Admin->value)],
            'team' => ['array'],
            'team.*' => ['string', 'max:120'],
            'repository_url' => ['nullable', 'url', 'max:255'],
            'staging_url' => ['nullable', 'url', 'max:255'],
            'production_url' => ['nullable', 'url', 'max:255'],
        ], [
            'target_date.after_or_equal' => 'A target date before the start date is not a plan.',
        ]);

        $validated['budget'] = $validated['budget'] === null ? null : Money::toPaise($validated['budget']);

        return $validated;
    }

    /** @return array<string, mixed> */
    protected function milestoneRules(Request $request): array
    {
        $validated = $this->validatedInput($request, [
            'title' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:2000'],
            'due_date' => ['nullable', 'date'],
            'progress_percent' => ['nullable', 'integer', 'min:0', 'max:100'],
            'payment_amount' => ['nullable', 'numeric', 'min:0'],
        ]);

        $validated['progress_percent'] ??= 0;
        $validated['payment_amount'] = ($validated['payment_amount'] ?? null) === null
            ? null
            : Money::toPaise($validated['payment_amount']);

        return $validated;
    }

    /** @return array<string, mixed> */
    protected function options(): array
    {
        return [
            'clients' => User::query()
                ->role(Role::Client)
                ->orderBy('name')
                ->get(['id', 'name', 'email'])
                ->map(fn (User $user) => [
                    'value' => $user->id,
                    'label' => $user->name,
                    'description' => $user->email,
                ]),
            'managers' => User::query()
                ->role(Role::Admin)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (User $user) => ['value' => $user->id, 'label' => $user->name]),
            'solutions' => Solution::query()
                ->orderBy('title')
                ->get(['id', 'title'])
                ->map(fn (Solution $solution) => ['value' => $solution->id, 'label' => $solution->title]),
            'statuses' => collect(Project::STATUSES)
                ->map(fn (string $status) => [
                    'value' => $status,
                    'label' => str($status)->replace('_', ' ')->title()->toString(),
                ]),
        ];
    }

    protected function table(): Table
    {
        return Table::for(Project::query()->with(['client:id,name', 'manager:id,name']))
            ->searchable(['name', 'code', 'phase'])
            ->sortable(['updated_at'])
            ->defaultSort('-updated_at')
            ->exportName('projects')
            ->columns([
                Column::make('name', 'Project')->sortable(),
                Column::make('client', 'Client'),
                Column::make('status', 'Status'),
                Column::make('progress', 'Progress')->numeric(),
                Column::make('target_date', 'Target')->sortable(),
                Column::make('manager', 'Manager'),
                Column::make('code', 'Reference')->hidden()->sortable(),
            ])
            ->filters([
                Filter::multi('status', collect(Project::STATUSES)
                    ->map(fn (string $status) => [
                        'value' => $status,
                        'label' => str($status)->replace('_', ' ')->title()->toString(),
                    ])->all(), 'Status'),
                Filter::select('manager_id', User::query()->role(Role::Admin)->orderBy('name')->get()
                    ->map(fn (User $user) => ['value' => $user->id, 'label' => $user->name])->all(), 'Manager')
                    ->placeholder('Anyone'),
            ])
            ->transform(fn (Project $project) => [
                'id' => $project->id,
                'name' => $project->name,
                'code' => $project->code,
                'client' => $project->client?->name ?? '—',
                'status' => $project->status,
                'statusLabel' => $project->statusLabel(),
                'progress' => $project->progress_percent,
                'target_date' => $project->target_date?->format('j M Y') ?? '—',
                'overdue' => $project->isOverdue(),
                'manager' => $project->manager?->name ?? 'Unassigned',
            ]);
    }
}
