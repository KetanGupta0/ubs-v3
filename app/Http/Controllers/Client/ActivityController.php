<?php

namespace App\Http\Controllers\Client;

use App\Enums\AuthEvent;
use App\Models\AuditLog;
use App\Models\AuthAuditLog;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserProfile;
use App\Support\Table\Column;
use App\Support\Table\Filter;
use App\Support\Table\Table;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Who in the organisation did what (CL20), for its owners: everything the
 * audit log recorded with one of its people as the actor, their payments,
 * and their recent sign ins. Our staff's actions are not listed; theirs are.
 */
class ActivityController extends ClientController
{
    /** What an action means, in the organisation's words. Anything else is shown as recorded. */
    public const LABELS = [
        'proposal.accepted' => 'Accepted and signed a proposal',
        'proposal.rejected' => 'Declined a proposal',
        'agreement.signed' => 'Signed an agreement',
        'milestone.accepted' => 'Accepted a milestone',
        'milestone.changes_requested' => 'Asked for changes to a milestone',
        'payment.requested' => 'Raised an invoice by accepting a milestone',
        'tds.declared' => 'Recorded TDS on an invoice',
        'tds.withdrawn' => 'Removed TDS from an invoice',
        'tds.certificate_received' => 'Sent a Form 16A',
        'waiting.answered' => 'Replied to something we asked for',
        'change_request.raised' => 'Asked for a change',
        'change_request.approved' => 'Approved and signed a change',
        'change_request.declined' => 'Declined a change',
        'project.changed_by_request' => 'Changed the project by approving a change',
        'feedback.reported' => 'Reported something on staging',
        'feedback.closed' => 'Confirmed a fix',
        'feedback.in_progress' => 'Sent a fix back',
        'handover.acknowledged' => 'Acknowledged a handover',
        'meeting.minutes_acknowledged' => 'Acknowledged meeting minutes',
        'meeting.minutes_disputed' => 'Asked for a correction to minutes',
    ];

    public function __invoke(Request $request): Response|HttpResponse
    {
        $this->mayAsClient($request, 'manage_people');
        $ids = $request->user()->organisationUserIds();
        $people = User::query()->whereIn('id', $ids)->orderBy('name')->pluck('name', 'id')->all();

        $table = Table::for(AuditLog::query()->whereIn('actor_id', $ids)->with('actor:id,name'))
            ->searchable(['subject_label'])
            ->defaultSort('-created_at')
            ->exportName('organisation-activity')
            ->columns([
                Column::make('created_at', 'When')->sortable(),
                Column::make('who', 'Who'),
                Column::make('action', 'What')->sortable(),
                Column::make('subject', 'On'),
                Column::make('ip', 'From'),
            ])
            ->filters([
                Filter::select('actor_id', $people, 'Person')->placeholder('Anybody'),
            ])
            ->transform(fn (AuditLog $log) => [
                'id' => $log->id,
                'created_at' => $log->created_at->format('j M Y, g:i a'),
                'who' => $log->actor?->name ?? '—',
                'action' => self::describe($log),
                'subject' => $log->subject_label ?? '—',
                'ip' => $log->ip_address ?? '—',
            ]);

        if ($export = $table->exportResponse($request)) {
            return $export;
        }

        return Inertia::render('client/Activity', [
            'table' => $table->toArray($request),
            'payments' => Transaction::query()->whereIn('paid_by', $ids)->where('status', 'successful')->with('invoice:id,number')->latest('paid_at')->take(10)->get()
                ->map(fn (Transaction $payment) => [
                    'id' => $payment->id,
                    'who' => $people[$payment->paid_by] ?? '—',
                    'amount' => $payment->amountLabel(),
                    'invoice' => $payment->invoice?->number,
                    'at' => ($payment->paid_at ?? $payment->created_at)->format('j M Y, g:i a'),
                ]),
            'signIns' => AuthAuditLog::query()->whereIn('user_id', $ids)->where('event', AuthEvent::LoginSucceeded->value)->latest('id')->take(15)->get()
                ->map(fn (AuthAuditLog $log) => [
                    'id' => $log->id,
                    'who' => $people[$log->user_id] ?? '—',
                    'method' => $log->method,
                    'ip' => $log->ip_address,
                    'at' => $log->created_at->format('j M Y, g:i a'),
                ]),
        ]);
    }

    public static function describe(AuditLog $log): string
    {
        return self::LABELS[$log->action] ?? match (true) {
            $log->action === 'updated' && $log->subject_type === UserProfile::class => 'Changed the billing details',
            in_array($log->action, ['created', 'updated', 'deleted'], true) => ucfirst($log->action).' '.str(class_basename((string) $log->subject_type))->headline()->lower(),
            default => str($log->action)->replace(['.', '_'], ' ')->ucfirst()->toString(),
        };
    }
}
