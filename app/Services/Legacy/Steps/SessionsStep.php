<?php

namespace App\Services\Legacy\Steps;

use App\Models\LiveSession;
use App\Services\Legacy\Context;
use App\Services\Legacy\Ledger;
use Illuminate\Support\Carbon;

/**
 * v2 live classes → v3 live sessions. A cancelled class stays cancelled, a
 * past one is completed, and anything still ahead keeps its join link.
 */
class SessionsStep extends Step
{
    public function name(): string
    {
        return 'sessions';
    }

    public function sourceTables(): array
    {
        return ['live_classes'];
    }

    public function run(Context $context): void
    {
        $report = $context->report;

        $context->source()->table('live_classes')->orderBy('id')->each(function (object $row) use ($context, $report) {
            $report->count('sessions', 'read');
            $batchId = $context->ledger->targetId('batches', (int) $row->batch_id);

            if (! $batchId) {
                $report->count('sessions', 'skipped');
                $report->warn('sessions', 'batch_missing', "live_classes#{$row->id}", 'Its batch was not imported; not imported.');

                return;
            }

            $checksum = Ledger::checksum($row);
            $entry = $context->ledger->find('live_classes', (int) $row->id);

            if ($entry && $entry->source_checksum === $checksum) {
                $report->count('sessions', 'unchanged');

                return;
            }

            $session = $entry ? LiveSession::query()->find($entry->target_id) : null;
            $isNew = $session === null;
            $session ??= new LiveSession;
            $at = Carbon::parse($row->scheduled_at);

            $session->fill([
                'batch_id' => $batchId,
                'title' => $row->title,
                'agenda' => $row->description,
                'scheduled_at' => $at,
                'duration_minutes' => (int) ($row->duration_minutes ?: 60),
                'meet_link' => $row->join_url,
                'recording_url' => $row->recording_url,
                'status' => match (true) {
                    (bool) $row->is_cancelled => 'cancelled',
                    $at->copy()->addMinutes((int) ($row->duration_minutes ?: 60))->isPast() => 'completed',
                    default => 'scheduled',
                },
            ])->save();

            $context->ledger->record('live_classes', (int) $row->id, $session, $checksum);
            $report->count('sessions', $isNew ? 'created' : 'updated');
        });
    }
}
