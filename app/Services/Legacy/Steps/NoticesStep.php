<?php

namespace App\Services\Legacy\Steps;

use App\Models\Announcement;
use App\Models\Batch;
use App\Services\Legacy\Context;
use App\Services\Legacy\Ledger;

/**
 * v2 notices → v3 announcements, each reaching the same people it did:
 * everybody, one batch, or one student. An urgent or warning notice stays
 * pinned. Who read what is not carried over.
 */
class NoticesStep extends Step
{
    public function name(): string
    {
        return 'notices';
    }

    public function sourceTables(): array
    {
        return ['notices'];
    }

    public function run(Context $context): void
    {
        $report = $context->report;

        $context->source()->table('notices')->orderBy('id')->each(function (object $row) use ($context, $report) {
            $report->count('notices', 'read');
            $source = "notices#{$row->id}";
            $target = $this->audience($context, $row);

            if ($target === null) {
                $report->count('notices', 'skipped');
                $report->warn('notices', 'audience_missing', $source, 'The batch or student it was for was not imported; not imported.');

                return;
            }

            $checksum = Ledger::checksum($row);
            $entry = $context->ledger->find('notices', (int) $row->id);

            if ($entry && $entry->source_checksum === $checksum) {
                $report->count('notices', 'unchanged');

                return;
            }

            $announcement = $entry ? Announcement::query()->find($entry->target_id) : null;
            $isNew = $announcement === null;
            $announcement ??= new Announcement;

            $announcement->forceFill([
                ...$target,
                'author_id' => $context->ledger->targetId('users', $row->created_by ? (int) $row->created_by : null),
                'title' => $row->title,
                'body' => trim(strip_tags((string) $row->body)),
                'is_pinned' => in_array($row->type, ['urgent', 'warning'], true),
                'published_at' => $row->created_at ?? now(),
                'created_at' => $row->created_at ?? now(),
            ])->save();

            $context->ledger->record('notices', (int) $row->id, $announcement, $checksum);
            $report->count('notices', $isNew ? 'created' : 'updated');
        });
    }

    /** @return array<string, mixed>|null */
    protected function audience(Context $context, object $row): ?array
    {
        return match ($row->target_type) {
            'batch' => ($batch = Batch::query()->find($context->ledger->targetId('batches', (int) $row->target_id)))
                ? ['audience' => 'batch', 'batch_id' => $batch->id, 'course_id' => $batch->course_id, 'user_id' => null]
                : null,
            'student' => ($userId = $context->ledger->targetId('users', (int) $row->target_id))
                ? ['audience' => 'student', 'user_id' => $userId, 'batch_id' => null, 'course_id' => null]
                : null,
            default => ['audience' => 'everyone', 'user_id' => null, 'batch_id' => null, 'course_id' => null],
        };
    }
}
