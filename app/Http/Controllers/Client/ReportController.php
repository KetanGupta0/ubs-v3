<?php

namespace App\Http\Controllers\Client;

use App\Models\Invoice;
use App\Models\Project;
use App\Models\SupportTicket;
use App\Models\Transaction;
use App\Support\Money;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What the client can see about their own account, gathered in one place.
 *
 * Three questions, answered plainly: how is the work going, what have we spent,
 * and how quickly are problems being dealt with.
 */
class ReportController extends ClientController
{
    public function __invoke(Request $request): Response
    {
        $client = $this->client($request);

        $projects = Project::query()->forClient($client)->get();
        $tickets = SupportTicket::query()->forClient($client)->get();

        return Inertia::render('client/Reports', [
            'projects' => [
                'total' => $projects->count(),
                'active' => $projects->whereIn('status', ['planning', 'in_progress', 'review'])->count(),
                'delivered' => $projects->where('status', 'delivered')->count(),
                'overdue' => $projects->filter(fn (Project $project) => $project->isOverdue())->count(),
                'averageProgress' => $projects->isEmpty()
                    ? 0
                    : (int) round($projects->avg('progress_percent')),
                'rows' => $projects->map(fn (Project $project) => [
                    'id' => $project->id,
                    'name' => $project->name,
                    'status' => $project->statusLabel(),
                    'progress' => $project->progress_percent,
                    'target' => $project->target_date?->format('j M Y') ?? '—',
                    'overdue' => $project->isOverdue(),
                ])->values(),
            ],

            'spend' => [
                'billed' => Money::display((int) Invoice::query()->forUser($client)->whereNot('status', 'cancelled')->sum('total')),
                'paid' => Money::display((int) Transaction::query()->forUser($client)->successful()->sum('amount')),
                'outstanding' => Money::display((int) Invoice::query()->forUser($client)->unpaid()->sum('total')
                    - (int) Invoice::query()->forUser($client)->unpaid()->sum('amount_paid')),
                'byMonth' => $this->spendByMonth($client->id),
            ],

            'support' => [
                'total' => $tickets->count(),
                'open' => $tickets->filter(fn (SupportTicket $ticket) => $ticket->isOpen())->count(),
                'breached' => $tickets->filter(fn (SupportTicket $ticket) => $ticket->breachedResolution())->count(),
                'averageResolutionHours' => $this->averageResolutionHours($tickets),
                'byPriority' => collect(SupportTicket::PRIORITIES)
                    ->map(fn (string $priority) => [
                        'priority' => $priority,
                        'count' => $tickets->where('priority', $priority)->count(),
                    ])
                    ->values(),
            ],
        ]);
    }

    /** Twelve months, zero filled, so the chart does not skip a quiet month. */
    protected function spendByMonth(int $clientId): array
    {
        $paid = Transaction::query()
            ->where('user_id', $clientId)
            ->successful()
            ->where('paid_at', '>=', now()->subMonths(11)->startOfMonth())
            ->get()
            ->groupBy(fn (Transaction $transaction) => $transaction->paid_at->format('Y-m'))
            ->map(fn ($group) => (int) $group->sum('amount'));

        return collect(range(11, 0))
            ->map(function (int $monthsAgo) use ($paid) {
                $month = now()->subMonths($monthsAgo);
                $amount = $paid[$month->format('Y-m')] ?? 0;

                return [
                    'month' => $month->format('M'),
                    'label' => $month->format('M Y'),
                    'amount' => $amount / 100,
                    'display' => Money::display($amount),
                ];
            })
            ->all();
    }

    protected function averageResolutionHours($tickets): ?int
    {
        $resolved = $tickets->filter(fn (SupportTicket $ticket) => $ticket->resolved_at !== null);

        if ($resolved->isEmpty()) {
            return null;
        }

        return (int) round($resolved->avg(
            fn (SupportTicket $ticket) => $ticket->created_at->diffInHours($ticket->resolved_at),
        ));
    }
}
