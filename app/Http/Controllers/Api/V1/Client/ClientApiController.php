<?php

namespace App\Http\Controllers\Api\V1\Client;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\Invoice;
use App\Models\PaymentRequest;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\SupportTicket;
use App\Models\Transaction;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The client module, for the mobile application.
 *
 * Shapes here mirror what the web screens receive, so one change to what a
 * project looks like does not have to be made twice and get made differently.
 *
 * Every query starts from the signed in client's own relations, exactly as the
 * web controllers do. A token is not a reason to relax that.
 */
class ClientApiController extends Controller
{
    public function overview(Request $request): JsonResponse
    {
        $client = $request->user();

        return response()->json([
            'totals' => [
                'activeProjects' => Project::query()->forClient($client)->active()->count(),
                'openTickets' => SupportTicket::query()->forClient($client)->open()->count(),
                'outstanding' => Money::display((int) PaymentRequest::query()->forUser($client)->pending()->sum('total')),
                'proposalsAwaiting' => Proposal::query()->where('client_id', $client->id)->awaitingResponse()->count(),
            ],
            'projects' => Project::query()
                ->forClient($client)
                ->active()
                ->orderByDesc('updated_at')
                ->take(5)
                ->get()
                ->map(fn (Project $project) => $this->projectArray($project)),
        ]);
    }

    public function projects(Request $request): JsonResponse
    {
        return response()->json([
            'data' => Project::query()
                ->forClient($request->user())
                ->orderByDesc('updated_at')
                ->get()
                ->map(fn (Project $project) => $this->projectArray($project)),
        ]);
    }

    public function project(Request $request, int $project): JsonResponse
    {
        $record = Project::query()
            ->forClient($request->user())
            ->with(['milestones', 'manager:id,name'])
            ->findOrFail($project);

        return response()->json([
            'data' => [
                ...$this->projectArray($record),
                'summary' => $record->summary,
                'manager' => $record->manager?->name,
                'milestones' => $record->milestones->map(fn ($milestone) => [
                    'id' => $milestone->id,
                    'title' => $milestone->title,
                    'dueDate' => $milestone->due_date?->toDateString(),
                    'complete' => $milestone->isComplete(),
                    'overdue' => $milestone->isOverdue(),
                ]),
                'updates' => $record->updates()
                    ->visibleToClient()
                    ->with('author:id,name')
                    ->take(20)
                    ->get()
                    ->map(fn ($update) => [
                        'id' => $update->id,
                        'title' => $update->title,
                        'body' => $update->body,
                        'author' => $update->author?->name ?? 'Unboundbyte',
                        'at' => $update->created_at->toIso8601String(),
                    ]),
            ],
        ]);
    }

    public function documents(Request $request): JsonResponse
    {
        return response()->json([
            'data' => Document::query()
                ->where('client_id', $request->user()->id)
                ->current()
                ->visibleToClient()
                ->latest('id')
                ->take(100)
                ->get()
                ->map(fn (Document $document) => [
                    'id' => $document->id,
                    'name' => $document->name,
                    'size' => $document->sizeLabel(),
                    'kind' => $document->kind(),
                    'version' => $document->version,
                    'at' => $document->created_at->toIso8601String(),
                    // A URL, not the bytes. The download still goes through a
                    // controller that checks who is asking.
                    'downloadUrl' => url("/client/documents/{$document->id}/download"),
                ]),
        ]);
    }

    public function tickets(Request $request): JsonResponse
    {
        return response()->json([
            'data' => SupportTicket::query()
                ->forClient($request->user())
                ->latest('id')
                ->take(100)
                ->get()
                ->map(fn (SupportTicket $ticket) => [
                    'id' => $ticket->id,
                    'reference' => $ticket->reference,
                    'subject' => $ticket->subject,
                    'status' => $ticket->status,
                    'priority' => $ticket->priority,
                    'open' => $ticket->isOpen(),
                    'sla' => $ticket->slaLabel(),
                    'at' => $ticket->created_at->toIso8601String(),
                ]),
        ]);
    }

    public function storeTicket(Request $request): JsonResponse
    {
        $client = $request->user();

        $validated = $this->validatedInput($request, [
            'subject' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:5000'],
            'priority' => ['required', Rule::in(SupportTicket::PRIORITIES)],
            'project_id' => ['nullable', 'integer'],
        ]);

        $contract = $client->maintenanceContracts()->active()->first();

        $ticket = SupportTicket::query()->create([
            'client_id' => $client->id,
            'contract_id' => $contract?->id,
            'project_id' => $validated['project_id']
                ? Project::query()->forClient($client)->find($validated['project_id'])?->id
                : null,
            'subject' => $validated['subject'],
            'body' => $validated['body'],
            'priority' => $validated['priority'],
            'status' => 'open',
            'response_due_at' => $contract ? now()->addHours($contract->response_hours) : null,
            'resolution_due_at' => $contract ? now()->addHours($contract->resolution_hours) : null,
        ]);

        return response()->json([
            'data' => ['id' => $ticket->id, 'reference' => $ticket->reference],
        ], 201);
    }

    public function payments(Request $request): JsonResponse
    {
        $client = $request->user();

        return response()->json([
            'pending' => PaymentRequest::query()
                ->forUser($client)
                ->pending()
                ->orderBy('due_on')
                ->get()
                ->map(fn (PaymentRequest $payment) => [
                    'id' => $payment->id,
                    'reference' => $payment->reference,
                    'title' => $payment->title,
                    'total' => Money::display($payment->total),
                    'totalMinor' => $payment->total,
                    'dueOn' => $payment->due_on?->toDateString(),
                    'overdue' => $payment->isOverdue(),
                ]),
            'summary' => [
                'paid' => Money::display((int) Transaction::query()->forUser($client)->successful()->sum('amount')),
                'outstanding' => Money::display((int) PaymentRequest::query()->forUser($client)->pending()->sum('total')),
            ],
        ]);
    }

    public function transactions(Request $request): JsonResponse
    {
        return response()->json([
            'data' => Transaction::query()
                ->forUser($request->user())
                ->with('invoice:id,number')
                ->latest('id')
                ->take(100)
                ->get()
                ->map(fn (Transaction $transaction) => [
                    'reference' => $transaction->reference,
                    'amount' => $transaction->amountLabel(),
                    'status' => $transaction->status,
                    'method' => $transaction->method,
                    'invoice' => $transaction->invoice?->number,
                    'at' => $transaction->created_at->toIso8601String(),
                    'receiptUrl' => $transaction->isSuccessful()
                        ? url("/client/receipts/{$transaction->reference}")
                        : null,
                ]),
        ]);
    }

    public function invoices(Request $request): JsonResponse
    {
        return response()->json([
            'data' => Invoice::query()
                ->forUser($request->user())
                ->latest('id')
                ->take(100)
                ->get()
                ->map(fn (Invoice $invoice) => [
                    'id' => $invoice->id,
                    'number' => $invoice->number,
                    'total' => Money::display($invoice->total),
                    'balance' => Money::display($invoice->balance()),
                    'status' => $invoice->status,
                    'issuedAt' => $invoice->issued_at->toIso8601String(),
                    'pdfUrl' => url("/client/invoices/{$invoice->id}/pdf"),
                ]),
        ]);
    }

    /** @return array<string, mixed> */
    protected function projectArray(Project $project): array
    {
        return [
            'id' => $project->id,
            'code' => $project->code,
            'name' => $project->name,
            'status' => $project->status,
            'statusLabel' => $project->statusLabel(),
            'phase' => $project->phase,
            'progress' => $project->progress_percent,
            'targetDate' => $project->target_date?->toDateString(),
            'overdue' => $project->isOverdue(),
        ];
    }
}
