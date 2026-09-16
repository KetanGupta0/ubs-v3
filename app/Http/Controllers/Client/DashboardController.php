<?php

namespace App\Http\Controllers\Client;

use App\Models\Document;
use App\Models\Invoice;
use App\Models\PaymentRequest;
use App\Models\Project;
use App\Models\ProjectUpdate;
use App\Models\Proposal;
use App\Models\Subscription;
use App\Models\SupportTicket;
use App\Support\Money;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The client's landing screen.
 *
 * It leads with what is waiting on them — a proposal to answer, an invoice to
 * pay, a renewal coming up — and only then shows how the work is going. A
 * dashboard that opens with four large numbers looks impressive and tells
 * somebody nothing about what to do next.
 */
class DashboardController extends ClientController
{
    public function __invoke(Request $request): Response
    {
        $client = $this->client($request);

        return Inertia::render('client/Dashboard', [
            'projects' => Project::query()
                ->forClient($client)
                ->active()
                ->orderByDesc('updated_at')
                ->take(4)
                ->get()
                ->map(fn (Project $project) => [
                    'id' => $project->id,
                    'code' => $project->code,
                    'name' => $project->name,
                    'status' => $project->status,
                    'statusLabel' => $project->statusLabel(),
                    'phase' => $project->phase,
                    'progress' => $project->progress_percent,
                    'targetDate' => $project->target_date?->format('j M Y'),
                    'overdue' => $project->isOverdue(),
                ]),

            'waitingOnYou' => $this->waitingOnYou($request),

            'timeline' => ProjectUpdate::query()
                ->visibleToClient()
                ->whereIn('project_id', Project::query()->forClient($client)->select('id'))
                ->with(['project:id,name,code', 'author:id,name'])
                ->latest('id')
                ->take(6)
                ->get()
                ->map(fn (ProjectUpdate $update) => [
                    'id' => $update->id,
                    'projectId' => $update->project_id,
                    'project' => $update->project?->name,
                    'title' => $update->title,
                    'body' => str($update->body)->limit(180)->toString(),
                    'author' => $update->author?->name ?? 'Unboundbyte',
                    'at' => $update->created_at->diffForHumans(),
                ]),

            'totals' => [
                'projects' => Project::query()->forClient($client)->active()->count(),
                'openTickets' => SupportTicket::query()->forClient($client)->open()->count(),
                'documents' => Document::query()
                    ->where('client_id', $client->id)
                    ->current()
                    ->visibleToClient()
                    ->count(),
                'due' => Money::display((int) PaymentRequest::query()->forUser($client)->pending()->sum('total')),
            ],
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    protected function waitingOnYou(Request $request): array
    {
        $client = $this->client($request);
        $items = [];

        Proposal::query()
            ->where('client_id', $client->id)
            ->awaitingResponse()
            ->get()
            ->each(function (Proposal $proposal) use (&$items) {
                if ($proposal->hasExpired()) {
                    return;
                }

                $items[] = [
                    'kind' => 'proposal',
                    'title' => $proposal->title,
                    'detail' => $proposal->valid_until
                        ? 'Valid until '.$proposal->valid_until->format('j M Y')
                        : 'Awaiting your answer',
                    'href' => "/client/proposals/{$proposal->id}",
                    'action' => 'Read and respond',
                    'urgent' => $proposal->valid_until !== null && $proposal->valid_until->diffInDays(today()) <= 7,
                ];
            });

        PaymentRequest::query()
            ->forUser($client)
            ->pending()
            ->orderBy('due_on')
            ->get()
            ->each(function (PaymentRequest $payment) use (&$items) {
                $items[] = [
                    'kind' => 'payment',
                    'title' => $payment->title,
                    'detail' => $payment->totalLabel().($payment->due_on
                        ? ' · due '.$payment->due_on->format('j M Y')
                        : ''),
                    'href' => "/client/payments/{$payment->id}",
                    'action' => 'Pay now',
                    'urgent' => $payment->isOverdue(),
                ];
            });

        Subscription::query()
            ->forClient($client)
            ->renewingWithin(30)
            ->get()
            ->each(function (Subscription $subscription) use (&$items) {
                $items[] = [
                    'kind' => 'renewal',
                    'title' => $subscription->name.' renews',
                    'detail' => $subscription->renews_on->format('j M Y').' · '.$subscription->amountLabel(),
                    'href' => '/client/subscriptions',
                    'action' => 'Review',
                    'urgent' => $subscription->daysToRenewal() <= 7,
                ];
            });

        Invoice::query()
            ->forUser($client)
            ->unpaid()
            ->whereNotNull('due_on')
            ->whereDate('due_on', '<', today())
            ->get()
            ->each(function (Invoice $invoice) use (&$items) {
                $items[] = [
                    'kind' => 'overdue',
                    'title' => "Invoice {$invoice->number} is overdue",
                    'detail' => $invoice->totalLabel().' · was due '.$invoice->due_on->format('j M Y'),
                    'href' => "/client/invoices/{$invoice->id}",
                    'action' => 'Open',
                    'urgent' => true,
                ];
            });

        // Urgent first, then in the order they were gathered.
        usort($items, fn ($a, $b) => $b['urgent'] <=> $a['urgent']);

        return $items;
    }
}
