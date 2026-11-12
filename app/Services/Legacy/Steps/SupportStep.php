<?php

namespace App\Services\Legacy\Steps;

use App\Models\SupportTicket;
use App\Models\TicketMessage;
use App\Services\Legacy\Context;
use App\Services\Legacy\Files;
use App\Services\Legacy\Ledger;

/**
 * v2 student tickets and their replies → v3 tickets raised by those students.
 *
 * v2 kept a ticket's first message as its first reply; v3 keeps it as the
 * ticket's body. Every later reply follows in order, staff-only notes stay
 * staff-only, and a reply's attachment is copied with it. Student tickets
 * have no contract, so no response time is promised after the fact.
 */
class SupportStep extends Step
{
    public const STATUSES = ['open' => 'open', 'in_progress' => 'in_progress', 'resolved' => 'resolved', 'closed' => 'closed'];

    public const PRIORITIES = ['low' => 'low', 'medium' => 'normal', 'normal' => 'normal', 'high' => 'high', 'urgent' => 'urgent'];

    public function name(): string
    {
        return 'support';
    }

    public function sourceTables(): array
    {
        return ['support_tickets', 'ticket_replies'];
    }

    public function run(Context $context): void
    {
        $report = $context->report;

        $context->source()->table('support_tickets')->orderBy('id')->each(function (object $row) use ($context, $report) {
            $report->count('support', 'read');
            $source = "support_tickets#{$row->id}";
            $userId = $context->ledger->targetId('users', (int) $row->student_id);

            if (! $userId) {
                $report->count('support', 'skipped');
                $report->warn('support', 'student_missing', $source, 'Its student was not imported; not imported.');

                return;
            }

            $replies = $context->source()->table('ticket_replies')->where('ticket_id', $row->id)->orderBy('created_at')->orderBy('id')->get();
            $checksum = Ledger::checksum(['ticket' => $row, 'replies' => $replies->all()]);
            $entry = $context->ledger->find('support_tickets', (int) $row->id);

            if ($entry && $entry->source_checksum === $checksum) {
                $report->count('support', 'unchanged');

                return;
            }

            // The student's first public message is what the ticket says.
            $opening = $replies->first(fn (object $reply) => ! $reply->is_internal && (int) $reply->sender_id === (int) $row->student_id);
            $status = self::STATUSES[$row->status] ?? 'open';

            $ticket = $entry ? SupportTicket::query()->find($entry->target_id) : null;
            $isNew = $ticket === null;
            $ticket ??= new SupportTicket;

            $ticket->forceFill([
                'requester_id' => $userId,
                'subject' => $row->subject,
                'body' => $opening?->body ? trim(strip_tags($opening->body)) : $row->subject,
                'category' => $row->category,
                'priority' => self::PRIORITIES[$row->priority] ?? 'normal',
                'status' => $status,
                'resolved_at' => in_array($status, ['resolved', 'closed'], true) ? ($row->updated_at ?? now()) : null,
                'closed_at' => $status === 'closed' ? ($row->updated_at ?? now()) : null,
                'first_response_at' => $replies->first(fn (object $reply) => ! $reply->is_internal && $reply->sender_type === 'admin')?->created_at,
                'created_at' => $row->created_at ?? now(),
                'updated_at' => $row->last_replied_at ?? $row->updated_at ?? now(),
            ])->save();

            if (! $isNew) {
                $ticket->messages()->delete();
            }

            foreach ($replies as $reply) {
                if ($opening && $reply->id === $opening->id) {
                    continue;
                }

                $file = $reply->attachment_path
                    ? Files::copy($context, 'support', "ticket_replies#{$reply->id}", 'any', $reply->attachment_path, 'private', "tickets/{$ticket->id}")
                    : null;

                TicketMessage::query()->forceCreate([
                    'support_ticket_id' => $ticket->id,
                    'author_id' => $context->ledger->targetId('users', (int) $reply->sender_id),
                    'body' => trim(strip_tags((string) $reply->body)) ?: '(No text.)',
                    'attachments' => $file ? [['name' => $reply->attachment_name ?: basename($file['path']), 'path' => $file['path'], 'mime' => $file['mime'], 'size' => $file['size']]] : null,
                    'is_internal' => (bool) $reply->is_internal,
                    'created_at' => $reply->created_at ?? now(),
                    'updated_at' => $reply->updated_at ?? $reply->created_at ?? now(),
                ]);
            }

            $context->ledger->record('support_tickets', (int) $row->id, $ticket, $checksum);
            $report->count('support', $isNew ? 'created' : 'updated');
        });
    }
}
