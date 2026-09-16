<?php

namespace App\Http\Controllers\Client;

use App\Enums\Role;
use App\Models\MaintenanceContract;
use App\Models\Project;
use App\Models\SupportTicket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Notifications\TicketRaised;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Maintenance contracts and the tickets raised against them.
 *
 * The clock a ticket is measured against is copied from the contract when the
 * ticket is raised, not read from the contract later. Editing a contract must
 * not be able to retrospectively change whether we met a promise.
 */
class SupportController extends ClientController
{
    public function index(Request $request): Response
    {
        $client = $this->client($request);

        return Inertia::render('client/support/Index', [
            'contracts' => MaintenanceContract::query()
                ->forClient($client)
                ->with('project:id,name')
                ->orderByDesc('ends_on')
                ->get()
                ->map(fn (MaintenanceContract $contract) => [
                    'id' => $contract->id,
                    'reference' => $contract->reference,
                    'plan' => $contract->plan,
                    'project' => $contract->project?->name,
                    'scope' => $contract->scope ?? [],
                    'exclusions' => $contract->exclusions ?? [],
                    'startsOn' => $contract->starts_on->format('j M Y'),
                    'endsOn' => $contract->ends_on->format('j M Y'),
                    'responseHours' => $contract->response_hours,
                    'resolutionHours' => $contract->resolution_hours,
                    'includedTickets' => $contract->included_tickets,
                    'ticketsUsed' => $contract->ticketsUsed(),
                    'status' => $contract->status,
                    'expired' => $contract->hasExpired(),
                    'expiring' => $contract->isExpiring(),
                    'daysRemaining' => $contract->daysRemaining(),
                    'amount' => $contract->amountLabel(),
                ]),

            'tickets' => SupportTicket::query()
                ->forClient($client)
                ->with('project:id,name')
                ->latest('id')
                ->take(50)
                ->get()
                ->map(fn (SupportTicket $ticket) => $this->card($ticket)),

            'canRaise' => MaintenanceContract::query()->forClient($client)->active()->exists(),
        ]);
    }

    public function create(Request $request): Response
    {
        $client = $this->client($request);

        return Inertia::render('client/support/Create', [
            'contracts' => MaintenanceContract::query()
                ->forClient($client)
                ->active()
                ->get()
                ->map(fn (MaintenanceContract $contract) => [
                    'value' => $contract->id,
                    'label' => $contract->plan.' — '.$contract->reference,
                    'description' => 'Response within '.$contract->response_hours.' hours',
                ]),
            'projects' => Project::query()
                ->forClient($client)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Project $project) => ['value' => $project->id, 'label' => $project->name]),
            'priorities' => SupportTicket::PRIORITIES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $client = $this->client($request);

        $validated = $this->validatedInput($request, [
            'subject' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:5000'],
            'contract_id' => ['nullable', 'integer'],
            'project_id' => ['nullable', 'integer'],
            'category' => ['nullable', 'string', 'max:40'],
            'priority' => ['required', Rule::in(SupportTicket::PRIORITIES)],
        ]);

        // Both references are re-resolved through the client's own relations,
        // so an id typed into the request body cannot attach this ticket to
        // somebody else's contract.
        $contract = $validated['contract_id']
            ? MaintenanceContract::query()->forClient($client)->find($validated['contract_id'])
            : MaintenanceContract::query()->forClient($client)->active()->first();

        $project = $validated['project_id']
            ? Project::query()->forClient($client)->find($validated['project_id'])
            : null;

        $ticket = SupportTicket::query()->create([
            'client_id' => $client->id,
            'contract_id' => $contract?->id,
            'project_id' => $project?->id ?? $contract?->project_id,
            'subject' => $validated['subject'],
            'body' => $validated['body'],
            'category' => $validated['category'] ?? null,
            'priority' => $validated['priority'],
            'status' => 'open',
            'response_due_at' => $contract ? now()->addHours($contract->response_hours) : null,
            'resolution_due_at' => $contract ? now()->addHours($contract->resolution_hours) : null,
        ]);

        $this->notifyStaff($ticket);

        return redirect()
            ->route('client.tickets.show', $ticket->id)
            ->with('success', "Ticket {$ticket->reference} raised. We will come back to you.");
    }

    public function show(Request $request, int $ticket): Response
    {
        $record = $this->own($request, SupportTicket::query()
            ->forClient($this->client($request))
            ->with(['project:id,name', 'contract:id,reference,plan', 'assignee:id,name']), $ticket);

        return Inertia::render('client/support/Show', [
            'ticket' => [
                ...$this->card($record),
                'body' => $record->body,
                'project' => $record->project?->name,
                'contract' => $record->contract?->reference,
                'assignee' => $record->assignee?->name,
                'responseDueAt' => $record->response_due_at?->format('j M Y, g:i a'),
                'resolutionDueAt' => $record->resolution_due_at?->format('j M Y, g:i a'),
                'firstResponseAt' => $record->first_response_at?->diffForHumans(),
                'resolvedAt' => $record->resolved_at?->format('j M Y, g:i a'),
                'canReply' => $record->isOpen(),
                'canClose' => $record->status === 'resolved',
            ],

            'messages' => $record->messages()
                ->visibleToClient()
                ->with('author:id,name,role')
                ->get()
                ->map(fn (TicketMessage $message) => [
                    'id' => $message->id,
                    'body' => $message->body,
                    'author' => $message->author?->name ?? 'Unboundbyte',
                    'fromUs' => $message->author?->isAdmin() ?? true,
                    'at' => $message->created_at->format('j M Y, g:i a'),
                    'ago' => $message->created_at->diffForHumans(),
                ]),
        ]);
    }

    public function reply(Request $request, int $ticket): RedirectResponse
    {
        $record = $this->own($request, SupportTicket::query()
            ->forClient($this->client($request)), $ticket);

        abort_unless($record->isOpen(), 422, 'This ticket is closed.');

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
        ]);

        $record->messages()->create([
            'author_id' => $request->user()->id,
            'body' => $validated['body'],
        ]);

        // A client reply puts the ball back with us, whatever it was before.
        if ($record->status === 'waiting_on_client') {
            $record->forceFill(['status' => 'in_progress'])->save();
        }

        return back()->with('success', 'Reply sent.');
    }

    public function close(Request $request, int $ticket): RedirectResponse
    {
        $record = $this->own($request, SupportTicket::query()
            ->forClient($this->client($request)), $ticket);

        $validated = $this->validatedInput($request, [
            'satisfaction' => ['nullable', 'integer', 'min:1', 'max:5'],
        ]);

        $record->forceFill([
            'status' => 'closed',
            'closed_at' => now(),
            'resolved_at' => $record->resolved_at ?? now(),
            'satisfaction' => $validated['satisfaction'] ?? null,
        ])->save();

        return back()->with('success', 'Ticket closed. Thank you.');
    }

    /** @return array<string, mixed> */
    protected function card(SupportTicket $ticket): array
    {
        return [
            'id' => $ticket->id,
            'reference' => $ticket->reference,
            'subject' => $ticket->subject,
            'status' => $ticket->status,
            'statusLabel' => str($ticket->status)->replace('_', ' ')->title()->toString(),
            'priority' => $ticket->priority,
            'category' => $ticket->category,
            'sla' => $ticket->slaLabel(),
            'breached' => $ticket->breachedResponse() || $ticket->breachedResolution(),
            'open' => $ticket->isOpen(),
            'at' => $ticket->created_at->format('j M Y'),
            'ago' => $ticket->created_at->diffForHumans(),
        ];
    }

    protected function notifyStaff(SupportTicket $ticket): void
    {
        $recipients = User::query()
            ->role(Role::Admin)
            ->active()
            ->get()
            ->filter(fn ($staff) => $staff->hasPermission('support.manage'));

        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, new TicketRaised($ticket));
        }
    }
}
