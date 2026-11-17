<?php

namespace App\Http\Controllers\Client;

use App\Models\Agreement;
use App\Models\ChangeRequest;
use App\Models\ClientMember;
use App\Models\Document;
use App\Models\FeedbackItem;
use App\Models\Invoice;
use App\Models\Meeting;
use App\Models\MilestoneSignoff;
use App\Models\PaymentRequest;
use App\Models\Project;
use App\Models\ProjectUpdate;
use App\Models\Proposal;
use App\Models\Subscription;
use App\Models\SupportTicket;
use App\Models\TdsDeduction;
use App\Models\WaitingItem;
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
            // Organisations that invited this person (CL8), waiting for a yes.
            'invitations' => ClientMember::query()
                ->where('user_id', $request->user()->id)
                ->pending()
                ->with('account.profile', 'inviter:id,name')
                ->get()
                ->map(fn (ClientMember $membership) => [
                    'id' => $membership->id,
                    'organisation' => $membership->account->organisationName(),
                    'role' => $membership->roleLabel(),
                    'invitedBy' => $membership->inviter?->name,
                ]),

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

    /**
     * Everything the organisation owes an answer on, first the ones this
     * person can act on. Something only another role can do still shows,
     * saying whose it is, so nobody wonders why the project is waiting.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function waitingOnYou(Request $request): array
    {
        $client = $this->client($request);
        $user = $request->user();
        $projects = Project::query()->forClient($client)->select('id');
        $items = [];

        // What we need from them to carry on (CL10). Anybody may reply.
        WaitingItem::query()
            ->forClient($client)
            ->open()
            ->with('project:id,name')
            ->orderByRaw('due_on is null')
            ->orderBy('due_on')
            ->get()
            ->each(function (WaitingItem $item) use (&$items) {
                $items[] = [
                    'kind' => 'waiting',
                    'title' => $item->title,
                    'detail' => $item->project?->name.($item->due_on
                        ? ($item->isOverdue() ? ' · was wanted by ' : ' · wanted by ').$item->due_on->format('j M Y')
                        : ''),
                    'href' => "/client/projects/{$item->project_id}/waiting/{$item->id}",
                    'action' => 'Reply',
                    'urgent' => $item->due_on !== null && $item->due_on->lte(today()->addDays(2)),
                ];
            });

        // Minutes to acknowledge, and meetings in the coming week (CL11).
        Meeting::query()
            ->forClient($client)
            ->where(fn ($query) => $query->where('status', 'held')
                ->orWhere(fn ($soon) => $soon->where('status', 'scheduled')->whereBetween('starts_at', [now()->subMinutes(30), now()->addDays(7)])))
            ->with('minutes')
            ->orderBy('starts_at')
            ->get()
            ->each(function (Meeting $meeting) use (&$items) {
                if ($meeting->status === 'scheduled') {
                    $items[] = [
                        'kind' => 'meeting',
                        'title' => $meeting->title,
                        'detail' => $meeting->whenLabel(),
                        'href' => "/client/meetings/{$meeting->id}",
                        'action' => 'Details',
                        'urgent' => $meeting->starts_at->lt(now()->addDay()),
                    ];

                    return;
                }

                if ($meeting->publishedMinutes()?->awaitsAnswer()) {
                    $items[] = [
                        'kind' => 'minutes',
                        'title' => "Minutes: {$meeting->title}",
                        'detail' => 'Meeting on '.$meeting->starts_at->format('j M Y').' · please acknowledge them',
                        'href' => "/client/meetings/{$meeting->id}",
                        'action' => 'Read',
                        'urgent' => false,
                    ];
                }
            });

        // Fixes on staging to check (CL13), for those who report feedback.
        if ($user->canAsClient('feedback')) {
            FeedbackItem::query()
                ->forClient($client)
                ->where('status', 'fixed')
                ->with('project:id,name')
                ->get()
                ->each(function (FeedbackItem $item) use (&$items) {
                    $items[] = [
                        'kind' => 'feedback',
                        'title' => "Check the fix: {$item->title}",
                        'detail' => $item->project->name.' · on staging',
                        'href' => "/client/projects/{$item->project_id}/feedback/{$item->id}",
                        'action' => 'Check',
                        'urgent' => false,
                    ];
                });
        }

        // Milestones to accept (CL9).
        $mayAccept = $user->canAsClient('accept');
        MilestoneSignoff::query()
            ->pending()
            ->whereIn('project_id', $projects)
            ->with('milestone:id,title,payment_amount', 'project:id,name')
            ->get()
            ->each(function (MilestoneSignoff $signoff) use (&$items, $mayAccept) {
                $items[] = [
                    'kind' => 'milestone',
                    'title' => "Accept: {$signoff->milestone->title}",
                    'detail' => $signoff->project->name.($mayAccept ? '' : ' · for a signatory to accept'),
                    'href' => "/client/projects/{$signoff->project_id}/milestones/{$signoff->milestone_id}",
                    'action' => $mayAccept ? 'Review' : 'Read',
                    'urgent' => $signoff->created_at->lt(now()->subDays(7)),
                ];
            });

        // Proposals: accepting one is signing it (CL2).
        $mayApprove = $user->canAsClient('approve');
        Proposal::query()
            ->where('client_id', $client->id)
            ->awaitingResponse()
            ->get()
            ->reject(fn (Proposal $proposal) => $proposal->hasExpired())
            ->each(function (Proposal $proposal) use (&$items, $mayApprove) {
                $items[] = [
                    'kind' => 'proposal',
                    'title' => $proposal->title,
                    'detail' => ($proposal->valid_until ? 'Valid until '.$proposal->valid_until->format('j M Y') : 'Awaiting your answer')
                        .($mayApprove ? '' : ' · for a signatory'),
                    'href' => "/client/proposals/{$proposal->id}",
                    'action' => $mayApprove ? 'Read and sign' : 'Read',
                    'urgent' => $proposal->valid_until !== null && $proposal->valid_until->diffInDays(today()) <= 7,
                ];
            });

        // Changes to the scope waiting for approval (CL12).
        ChangeRequest::query()
            ->forClient($client)
            ->where('status', 'quoted')
            ->with('project:id,name')
            ->get()
            ->each(function (ChangeRequest $change) use (&$items, $mayApprove) {
                $items[] = [
                    'kind' => 'change',
                    'title' => "Approve: {$change->title}",
                    'detail' => "{$change->amountLabel()} before tax · {$change->project->name}".($mayApprove ? '' : ' · for a signatory'),
                    'href' => "/client/projects/{$change->project_id}/changes/{$change->id}",
                    'action' => $mayApprove ? 'Review and sign' : 'Read',
                    'urgent' => $change->quoted_at !== null && $change->quoted_at->lt(now()->subDays(7)),
                ];
            });

        // Agreements waiting for a signature (CL2).
        $maySign = $user->canAsClient('sign');
        Agreement::query()
            ->forClient($client)
            ->where('status', 'sent')
            ->get()
            ->each(function (Agreement $agreement) use (&$items, $maySign) {
                $items[] = [
                    'kind' => 'agreement',
                    'title' => "Sign: {$agreement->title}",
                    'detail' => $agreement->number.($maySign ? '' : ' · for a signatory'),
                    'href' => "/client/agreements/{$agreement->id}",
                    'action' => $maySign ? 'Read and sign' : 'Read',
                    'urgent' => $agreement->sent_at !== null && $agreement->sent_at->lt(now()->subDays(7)),
                ];
            });

        $mayPay = $user->canAsClient('pay');
        PaymentRequest::query()
            ->forUser($client)
            ->pending()
            ->orderBy('due_on')
            ->get()
            ->each(function (PaymentRequest $payment) use (&$items, $mayPay) {
                $items[] = [
                    'kind' => 'payment',
                    'title' => $payment->title,
                    'detail' => $payment->totalLabel()
                        .($payment->due_on ? ' · due '.$payment->due_on->format('j M Y') : '')
                        .($mayPay ? '' : ' · for finance'),
                    'href' => "/client/payments/{$payment->id}",
                    'action' => $mayPay ? 'Pay now' : 'Read',
                    'urgent' => $payment->isOverdue(),
                ];
            });

        // Form 16A for TDS deducted from our invoices (CL14), from a month before it is due.
        if ($mayPay) {
            TdsDeduction::query()
                ->forClient($client)
                ->awaitingCertificate()
                // One that did not match is theirs to correct now, whatever the date.
                ->where(fn ($query) => $query->where('status', 'mismatch')->orWhere('certificate_due_on', '<=', today()->addDays(30)))
                ->with('invoice:id,number,payment_request_id')
                ->get()
                ->each(function (TdsDeduction $deduction) use (&$items) {
                    $items[] = [
                        'kind' => 'tds',
                        'title' => $deduction->status === 'mismatch' ? "Correct the Form 16A for {$deduction->invoice->number}" : "Send the Form 16A for {$deduction->invoice->number}",
                        'detail' => "{$deduction->amountLabel()} TDS, {$deduction->quarter} · due ".$deduction->certificate_due_on->format('j M Y'),
                        'href' => "/client/payments/{$deduction->invoice->payment_request_id}",
                        'action' => 'Upload',
                        'urgent' => $deduction->status === 'mismatch' || $deduction->certificate_due_on->isPast(),
                    ];
                });
        }

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

        // An overdue invoice whose request is still open is already listed
        // above as a payment; listing it twice reads as two debts.
        Invoice::query()
            ->forUser($client)
            ->unpaid()
            ->whereNotNull('due_on')
            ->whereDate('due_on', '<', today())
            ->where(fn ($query) => $query->whereNull('payment_request_id')
                ->orWhereNotIn('payment_request_id', PaymentRequest::query()->forUser($client)->pending()->select('id')))
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

        // Urgent first, then in the order they were gathered: what is asked
        // of them, what to accept, sign and pay.
        usort($items, fn ($a, $b) => $b['urgent'] <=> $a['urgent']);

        return $items;
    }
}
