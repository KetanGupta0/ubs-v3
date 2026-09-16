<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\MaintenanceContract;
use App\Models\Project;
use App\Models\SupportTicket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\Admin\Auditor;
use App\Support\Money;
use App\Support\Table\Column;
use App\Support\Table\Filter;
use App\Support\Table\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Maintenance contracts and the tickets raised against them.
 *
 * The first reply is recorded automatically, because whether we answered in
 * time is not something anybody should have to remember to tick.
 */
class SupportController extends Controller
{
    public function index(Request $request): Response|HttpResponse
    {
        $table = $this->ticketsTable();

        if ($export = $table->exportResponse($request)) {
            return $export;
        }

        return Inertia::render('admin/support/Index', [
            'table' => $table->toArray($request),
            'counts' => [
                'open' => SupportTicket::query()->open()->count(),
                'unassigned' => SupportTicket::query()->open()->whereNull('assigned_to')->count(),
                'breaching' => SupportTicket::query()
                    ->open()
                    ->where(function (Builder $query) {
                        $query->where(fn (Builder $q) => $q->whereNull('first_response_at')
                            ->whereNotNull('response_due_at')
                            ->where('response_due_at', '<', now()))
                            ->orWhere(fn (Builder $q) => $q->whereNull('resolved_at')
                                ->whereNotNull('resolution_due_at')
                                ->where('resolution_due_at', '<', now()));
                    })
                    ->count(),
            ],
        ]);
    }

    public function show(SupportTicket $ticket): Response
    {
        $ticket->load(['client:id,name,email', 'project:id,name', 'contract:id,reference,plan,response_hours', 'assignee:id,name']);

        return Inertia::render('admin/support/Show', [
            'ticket' => [
                'id' => $ticket->id,
                'reference' => $ticket->reference,
                'subject' => $ticket->subject,
                'body' => $ticket->body,
                'status' => $ticket->status,
                'priority' => $ticket->priority,
                'category' => $ticket->category,
                'client' => ['id' => $ticket->client_id, 'name' => $ticket->client->name, 'email' => $ticket->client->email],
                'project' => $ticket->project?->name,
                'contract' => $ticket->contract?->reference,
                'assignedTo' => $ticket->assigned_to,
                'assignee' => $ticket->assignee?->name,
                'responseDueAt' => $ticket->response_due_at?->format('j M Y, g:i a'),
                'resolutionDueAt' => $ticket->resolution_due_at?->format('j M Y, g:i a'),
                'firstResponseAt' => $ticket->first_response_at?->format('j M Y, g:i a'),
                'resolvedAt' => $ticket->resolved_at?->format('j M Y, g:i a'),
                'breachedResponse' => $ticket->breachedResponse(),
                'breachedResolution' => $ticket->breachedResolution(),
                'sla' => $ticket->slaLabel(),
                'satisfaction' => $ticket->satisfaction,
                'raisedAt' => $ticket->created_at->format('j M Y, g:i a'),
            ],
            'messages' => $ticket->messages()
                ->with('author:id,name,role')
                ->get()
                ->map(fn (TicketMessage $message) => [
                    'id' => $message->id,
                    'body' => $message->body,
                    'author' => $message->author?->name ?? 'System',
                    'fromUs' => $message->author?->isAdmin() ?? true,
                    'internal' => $message->is_internal,
                    'at' => $message->created_at->format('j M Y, g:i a'),
                    'ago' => $message->created_at->diffForHumans(),
                ]),
            'statuses' => SupportTicket::STATUSES,
            'priorities' => SupportTicket::PRIORITIES,
            'assignees' => User::query()
                ->role(Role::Admin)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (User $user) => ['value' => $user->id, 'label' => $user->name]),
        ]);
    }

    public function reply(Request $request, SupportTicket $ticket): RedirectResponse
    {
        $validated = $this->validatedInput($request, [
            'body' => ['required', 'string', 'max:5000'],
            'is_internal' => ['boolean'],
            'status' => ['nullable', Rule::in(SupportTicket::STATUSES)],
        ]);

        $internal = $validated['is_internal'] ?? false;

        $ticket->messages()->create([
            'author_id' => $request->user()->id,
            'body' => $validated['body'],
            'is_internal' => $internal,
        ]);

        $changes = [];

        // An internal note is not a response to the client, so it does not stop
        // the response clock. Only something the client can read does.
        if (! $internal && $ticket->first_response_at === null) {
            $changes['first_response_at'] = now();
        }

        if ($validated['status'] ?? null) {
            $changes['status'] = $validated['status'];

            if ($validated['status'] === 'resolved' && $ticket->resolved_at === null) {
                $changes['resolved_at'] = now();
            }
        } elseif (! $internal && $ticket->status === 'open') {
            $changes['status'] = 'in_progress';
        }

        if ($changes !== []) {
            $ticket->forceFill($changes)->save();
        }

        return back()->with('success', $internal ? 'Internal note added.' : 'Reply sent.');
    }

    public function update(Request $request, SupportTicket $ticket, Auditor $auditor): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['sometimes', Rule::in(SupportTicket::STATUSES)],
            'priority' => ['sometimes', Rule::in(SupportTicket::PRIORITIES)],
            'assigned_to' => ['sometimes', 'nullable', 'integer', Rule::exists('users', 'id')->where('role', Role::Admin->value)],
        ]);

        if (($validated['status'] ?? null) === 'resolved' && $ticket->resolved_at === null) {
            $validated['resolved_at'] = now();
        }

        $ticket->fill($validated);
        $auditor->updated($ticket, label: $ticket->reference);
        $ticket->save();

        return back()->with('success', 'Ticket updated.');
    }

    /* ------------------------------------------------------------ contracts */

    public function contracts(Request $request): Response|HttpResponse
    {
        $table = $this->contractsTable();

        if ($export = $table->exportResponse($request)) {
            return $export;
        }

        return Inertia::render('admin/support/Contracts', [
            'table' => $table->toArray($request),
            'counts' => [
                'active' => MaintenanceContract::query()->active()->count(),
                'expiring' => MaintenanceContract::query()
                    ->active()
                    ->whereBetween('ends_on', [today(), today()->addDays(45)])
                    ->count(),
            ],
        ]);
    }

    public function createContract(): Response
    {
        return Inertia::render('admin/support/ContractForm', [
            'contract' => null,
            ...$this->contractOptions(),
        ]);
    }

    public function storeContract(Request $request, Auditor $auditor): RedirectResponse
    {
        $contract = MaintenanceContract::query()->create($this->contractRules($request));
        $auditor->created($contract, $contract->reference);

        return redirect()
            ->route('admin.contracts.index')
            ->with('success', "Contract {$contract->reference} created.");
    }

    public function editContract(MaintenanceContract $contract): Response
    {
        return Inertia::render('admin/support/ContractForm', [
            'contract' => [
                'id' => $contract->id,
                'reference' => $contract->reference,
                'client_id' => $contract->client_id,
                'project_id' => $contract->project_id,
                'plan' => $contract->plan,
                'scope' => $contract->scope ?? [],
                'exclusions' => $contract->exclusions ?? [],
                'starts_on' => $contract->starts_on->toDateString(),
                'ends_on' => $contract->ends_on->toDateString(),
                'response_hours' => $contract->response_hours,
                'resolution_hours' => $contract->resolution_hours,
                'included_tickets' => $contract->included_tickets,
                'amount' => $contract->amount / 100,
                'billing_interval' => $contract->billing_interval,
                'status' => $contract->status,
            ],
            ...$this->contractOptions(),
        ]);
    }

    public function updateContract(Request $request, MaintenanceContract $contract, Auditor $auditor): RedirectResponse
    {
        $contract->fill($this->contractRules($request));
        $auditor->updated($contract, label: $contract->reference);
        $contract->save();

        return back()->with('success', 'Contract saved.');
    }

    public function destroyContract(MaintenanceContract $contract, Auditor $auditor): RedirectResponse
    {
        if ($contract->tickets()->exists()) {
            return back()->withErrors([
                'contract' => 'Tickets were raised against this contract. Mark it expired instead.',
            ]);
        }

        $auditor->deleted($contract, $contract->reference);
        $contract->delete();

        return redirect()->route('admin.contracts.index')->with('success', 'Contract removed.');
    }

    /* --------------------------------------------------------------- helpers */

    /** @return array<string, mixed> */
    protected function contractRules(Request $request): array
    {
        $validated = $this->validatedInput($request, [
            'client_id' => ['required', 'integer', Rule::exists('users', 'id')->where('role', Role::Client->value)],
            'project_id' => ['nullable', 'integer', Rule::exists('projects', 'id')],
            'plan' => ['required', 'string', 'max:40'],
            'scope' => ['array'],
            'scope.*' => ['string', 'max:200'],
            'exclusions' => ['array'],
            'exclusions.*' => ['string', 'max:200'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after:starts_on'],
            'response_hours' => ['required', 'integer', 'min:1', 'max:720'],
            'resolution_hours' => ['required', 'integer', 'min:1', 'max:2160'],
            'included_tickets' => ['nullable', 'integer', 'min:1', 'max:9999'],
            'amount' => ['required', 'numeric', 'min:0'],
            'billing_interval' => ['required', Rule::in(['monthly', 'quarterly', 'half_yearly', 'yearly'])],
            'status' => ['required', Rule::in(['active', 'expired', 'cancelled'])],
        ], [
            'ends_on.after' => 'A contract cannot end before it starts.',
        ]);

        $validated['amount'] = Money::toPaise($validated['amount']);

        return $validated;
    }

    /** @return array<string, mixed> */
    protected function contractOptions(): array
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
            'projects' => Project::query()
                ->orderBy('name')
                ->get(['id', 'name', 'client_id'])
                ->map(fn (Project $project) => [
                    'value' => $project->id,
                    'label' => $project->name,
                    'clientId' => $project->client_id,
                ]),
        ];
    }

    protected function ticketsTable(): Table
    {
        return Table::for(SupportTicket::query()->with(['client:id,name', 'assignee:id,name', 'project:id,name']))
            ->searchable(['reference', 'subject', 'body'])
            ->sortable(['created_at'])
            ->defaultSort('-created_at')
            ->exportName('tickets')
            ->columns([
                Column::make('reference', 'Reference'),
                Column::make('subject', 'Subject'),
                Column::make('client', 'Client'),
                Column::make('priority', 'Priority'),
                Column::make('status', 'Status'),
                Column::make('sla', 'SLA'),
                Column::make('assignee', 'Assigned to'),
                Column::make('created_at', 'Raised')->sortable(),
            ])
            ->filters([
                Filter::multi('status', collect(SupportTicket::STATUSES)
                    ->map(fn (string $status) => [
                        'value' => $status,
                        'label' => str($status)->replace('_', ' ')->title()->toString(),
                    ])->all(), 'Status'),
                Filter::multi('priority', collect(SupportTicket::PRIORITIES)
                    ->map(fn (string $priority) => ['value' => $priority, 'label' => ucfirst($priority)])
                    ->all(), 'Priority'),
                Filter::select('assigned_to', User::query()->role(Role::Admin)->orderBy('name')->get()
                    ->map(fn (User $user) => ['value' => $user->id, 'label' => $user->name])->all(), 'Assigned to')
                    ->placeholder('Anyone'),
            ])
            ->transform(fn (SupportTicket $ticket) => [
                'id' => $ticket->id,
                'reference' => $ticket->reference,
                'subject' => $ticket->subject,
                'client' => $ticket->client?->name ?? '—',
                'priority' => $ticket->priority,
                'status' => $ticket->status,
                'statusLabel' => str($ticket->status)->replace('_', ' ')->title()->toString(),
                'sla' => $ticket->slaLabel(),
                'breached' => $ticket->breachedResponse() || $ticket->breachedResolution(),
                'assignee' => $ticket->assignee?->name ?? 'Unassigned',
                'created_at' => $ticket->created_at->format('j M Y'),
            ]);
    }

    protected function contractsTable(): Table
    {
        return Table::for(MaintenanceContract::query()->with(['client:id,name', 'project:id,name'])->withCount('tickets'))
            ->searchable(['reference', 'plan'])
            ->sortable(['ends_on'])
            ->defaultSort('ends_on')
            ->exportName('contracts')
            ->columns([
                Column::make('reference', 'Reference'),
                Column::make('client', 'Client'),
                Column::make('plan', 'Plan'),
                Column::make('project', 'Project'),
                Column::make('ends_on', 'Ends')->sortable(),
                Column::make('tickets', 'Tickets')->numeric(),
                Column::make('status', 'Status'),
            ])
            ->filters([
                Filter::multi('status', [
                    ['value' => 'active', 'label' => 'Active'],
                    ['value' => 'expired', 'label' => 'Expired'],
                    ['value' => 'cancelled', 'label' => 'Cancelled'],
                ], 'Status'),
            ])
            ->transform(fn (MaintenanceContract $contract) => [
                'id' => $contract->id,
                'reference' => $contract->reference,
                'client' => $contract->client?->name ?? '—',
                'plan' => $contract->plan,
                'project' => $contract->project?->name ?? '—',
                'ends_on' => $contract->ends_on->format('j M Y'),
                'expiring' => $contract->isExpiring(),
                'expired' => $contract->hasExpired(),
                'tickets' => $contract->tickets_count,
                'status' => $contract->status,
            ]);
    }
}
