<?php

namespace App\Services\Legacy\Steps;

use App\Models\Lead;
use App\Models\LeadNote;
use App\Services\Legacy\Context;
use App\Services\Legacy\Ledger;
use App\Support\Identifier;
use Illuminate\Support\Str;

/**
 * v2 contact form enquiries → v3 leads, each with a reference and its note.
 *
 * Nobody is written to about it: an old enquiry arriving in v3 is a record,
 * not a new conversation, and the importer sends nothing anyway.
 */
class LeadsStep extends Step
{
    public const STATUSES = ['unread' => 'new', 'read' => 'contacted', 'replied' => 'contacted', 'archived' => 'lost'];

    public function name(): string
    {
        return 'leads';
    }

    public function sourceTables(): array
    {
        return ['contact_queries'];
    }

    public function run(Context $context): void
    {
        $report = $context->report;

        $context->source()->table('contact_queries')->orderBy('id')->each(function (object $row) use ($context, $report) {
            $report->count('leads', 'read');
            $checksum = Ledger::checksum($row);
            $entry = $context->ledger->find('contact_queries', (int) $row->id);

            if ($entry && $entry->source_checksum === $checksum) {
                $report->count('leads', 'unchanged');

                return;
            }

            $lead = $entry ? Lead::query()->withTrashed()->find($entry->target_id) : null;
            $isNew = $lead === null;
            $lead ??= new Lead;
            $mobile = filled($row->phone) ? Identifier::normaliseMobile((string) $row->phone) : null;

            $lead->forceFill([
                'name' => Str::limit(trim((string) $row->name) ?: 'Unknown', 250, ''),
                'email' => Str::lower(trim((string) $row->email)),
                'mobile' => $mobile && preg_match('/^\+\d{11,15}$/', $mobile) ? $mobile : null,
                'message' => trim(($row->subject ? "Subject: {$row->subject}\n\n" : '').strip_tags((string) $row->message)),
                'interest' => 'general',
                'source_page' => 'Contact form on the old site',
                'status' => self::STATUSES[$row->status] ?? 'new',
                'ip_address' => $row->ip_address,
                'user_agent' => $row->user_agent,
                'created_at' => $row->created_at ?? now(),
                'updated_at' => $row->updated_at ?? now(),
            ])->save();

            if ($isNew && filled($row->admin_note)) {
                LeadNote::query()->forceCreate([
                    'lead_id' => $lead->id,
                    'body' => "From the old site: {$row->admin_note}",
                    'created_at' => $row->updated_at ?? now(),
                    'updated_at' => $row->updated_at ?? now(),
                ]);
            }

            $context->ledger->record('contact_queries', (int) $row->id, $lead, $checksum);
            $report->count('leads', $isNew ? 'created' : 'updated');
        });
    }
}
