<?php

namespace App\Http\Controllers\Client;

use App\Models\ChangeRequest;
use App\Models\Document;
use App\Models\FeedbackItem;
use App\Models\MilestoneSignoff;
use App\Models\Project;
use App\Models\ProjectMilestone;
use App\Models\ProjectUpdate;
use App\Services\Client\ClientDocuments;
use App\Services\Client\MilestoneSignoffs;
use App\Services\Client\WaitingItems;
use App\Support\Money;
use App\Support\Table\Column;
use App\Support\Table\Filter;
use App\Support\Table\Table;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
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

    public function show(Request $request, int $project, WaitingItems $waiting): Response
    {
        $record = $this->own($request, Project::query()
            ->forClient($this->client($request))
            ->with(['solution:id,title,slug', 'manager:id,name,avatar_path', 'milestones.signoffs']), $project);

        $waitingTime = $waiting->waitingTime($record);

        return Inertia::render('client/projects/Show', [
            // What we are waiting on them for (CL10), still open or just answered.
            'waiting' => $waiting->forScreen($record->waitingItems()->whereIn('status', ['open', 'answered'])->get(), $waitingTime['items']),
            'waitingTime' => $waitingTime['seconds'] > 0 ? $waitingTime['label'] : null,

            // Feedback on staging (CL13): how much is open, and whether this person may report.
            'feedback' => [
                'open' => FeedbackItem::query()->where('project_id', $record->id)->open()->count(),
                'toCheck' => FeedbackItem::query()->where('project_id', $record->id)->where('status', 'fixed')->count(),
                'total' => FeedbackItem::query()->where('project_id', $record->id)->count(),
                'canReport' => $request->user()->canAsClient('feedback'),
            ],

            // Changes to the scope (CL12), newest first.
            'changes' => ChangeRequest::query()->where('project_id', $record->id)->latest('id')->get()
                ->map(fn (ChangeRequest $change) => [
                    'id' => $change->id,
                    'number' => $change->number,
                    'title' => $change->title,
                    'status' => $change->status,
                    'statusLabel' => $change->statusLabel(),
                    'amount' => $change->amountLabel(),
                ]),

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
                // Acceptance (CL9): waiting, changes or accepted; null if never asked.
                'signoff' => $milestone->signoffState(),
                'acceptedAt' => $milestone->accepted_at?->format('j M Y'),
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

    /** Where the project stands, as a PDF, as of now (CL3). */
    /* ------------------------------------------------ milestone acceptance */

    /** A milestone, what we said was delivered, and every answer given on it. */
    public function milestone(Request $request, int $project, int $milestone): Response
    {
        $record = $this->ownMilestone($request, $project, $milestone);
        $record->load('signoffs.requester:id,name', 'signoffs.decider:id,name');

        $pending = $record->signoffs->first(fn (MilestoneSignoff $signoff) => $signoff->isPending());
        $billed = $record->billedRequest();

        return Inertia::render('client/projects/Milestone', [
            'project' => ['id' => $record->project->id, 'name' => $record->project->name, 'code' => $record->project->code, 'stagingUrl' => $record->project->staging_url],
            'milestone' => [
                'id' => $record->id,
                'title' => $record->title,
                'description' => $record->description,
                'dueDate' => $record->due_date?->format('j M Y'),
                'payment' => $record->payment_amount ? Money::display($record->payment_amount) : null,
                'acceptedAt' => $record->accepted_at?->format('j M Y, g:i a'),
                'state' => $record->signoffState(),
            ],
            'pending' => $pending?->toScreen(),
            'history' => $record->signoffs->reject(fn (MilestoneSignoff $signoff) => $signoff->isPending() || $signoff->withdrawn_at)
                ->map->toScreen()->reverse()->values(),
            'invoice' => $billed ? ['id' => $billed->id, 'reference' => $billed->reference, 'status' => $billed->status, 'total' => Money::display($billed->total)] : null,
            'canAnswer' => $pending !== null && $request->user()->canAsClient('accept'),
            // Feedback reported against it and not yet done (CL13).
            'openFeedback' => FeedbackItem::query()->where('milestone_id', $record->id)->open()->get(['id', 'title', 'status'])
                ->map(fn (FeedbackItem $item) => ['id' => $item->id, 'title' => $item->title, 'statusLabel' => FeedbackItem::statusLabel($item->status)]),
        ]);
    }

    public function acceptMilestone(Request $request, int $project, int $milestone, MilestoneSignoffs $signoffs): RedirectResponse
    {
        $this->mayAsClient($request, 'accept');
        $record = $this->ownMilestone($request, $project, $milestone);

        $validated = $this->validatedInput($request, [
            'comments' => ['nullable', 'string', 'max:2000'],
            'confirm' => ['accepted'],
        ], [
            'confirm.accepted' => 'Confirm that the work listed was delivered.',
        ]);

        $pending = $record->signoffs()->pending()->first();

        if (! $pending) {
            return back()->with('error', 'There is nothing waiting for your answer on this milestone.');
        }

        try {
            $payment = $signoffs->accept($pending, $request->user(), $validated['comments'], $request);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        $billed = $payment ?? $record->billedRequest();

        return back()->with('success', match (true) {
            $payment !== null => "Accepted. Its invoice {$payment->invoice->number} has been raised and sent to your finance contacts.",
            $billed !== null => 'Accepted. Its invoice'.($billed->invoice ? " {$billed->invoice->number}" : '').' was raised earlier and is under Payments.',
            default => 'Accepted. Thank you.',
        });
    }

    public function rejectMilestone(Request $request, int $project, int $milestone, MilestoneSignoffs $signoffs): RedirectResponse
    {
        $this->mayAsClient($request, 'accept');
        $record = $this->ownMilestone($request, $project, $milestone);

        $validated = $this->validatedInput($request, [
            'comments' => ['required', 'string', 'min:10', 'max:2000'],
        ], [
            'comments.required' => 'Tell us what needs changing, so we can put it right.',
            'comments.min' => 'Tell us a little more about what needs changing.',
        ]);

        $pending = $record->signoffs()->pending()->first();

        if (! $pending) {
            return back()->with('error', 'There is nothing waiting for your answer on this milestone.');
        }

        try {
            $signoffs->reject($pending, $request->user(), $validated['comments'], $request);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Sent. We will make the changes and ask you again.');
    }

    /** A milestone on one of the organisation's own projects, or not found. */
    protected function ownMilestone(Request $request, int $project, int $milestone): ProjectMilestone
    {
        $owned = $this->own($request, Project::query()->forClient($this->client($request)), $project);

        return $owned->milestones()->whereKey($milestone)->firstOrFail()->setRelation('project', $owned);
    }

    public function statusReport(Request $request, int $project, ClientDocuments $documents): HttpResponse
    {
        $record = $this->own($request, Project::query()->forClient($this->client($request)), $project);

        return response($documents->statusReport($record), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$record->code.'-status-'.now()->format('Y-m-d').'.pdf"',
        ]);
    }
}
