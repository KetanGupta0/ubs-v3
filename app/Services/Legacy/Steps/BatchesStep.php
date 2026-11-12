<?php

namespace App\Services\Legacy\Steps;

use App\Models\Batch;
use App\Services\Legacy\Context;
use App\Services\Legacy\Ledger;
use Illuminate\Support\Carbon;

/**
 * v2 batches → v3 batches.
 *
 * v3 requires a unique code; an imported batch gets V2-<id>, which the
 * admin can rename. The status is worked out from the dates, and the seat
 * count is left to the enrolments step, which recounts every batch.
 */
class BatchesStep extends Step
{
    public function name(): string
    {
        return 'batches';
    }

    public function sourceTables(): array
    {
        return ['batches'];
    }

    public function run(Context $context): void
    {
        $report = $context->report;

        $context->source()->table('batches')->orderBy('id')->each(function (object $row) use ($context, $report) {
            $report->count('batches', 'read');
            $source = "batches#{$row->id}";
            $courseId = $context->ledger->targetId('training_programs', (int) $row->program_id);

            if (! $courseId) {
                $report->count('batches', 'skipped');
                $report->warn('batches', 'course_missing', $source, 'Its program was not imported; not imported.');

                return;
            }

            $checksum = Ledger::checksum($row);
            $entry = $context->ledger->find('batches', (int) $row->id);

            if ($entry && $entry->source_checksum === $checksum) {
                $report->count('batches', 'unchanged');

                return;
            }

            $batch = $entry ? Batch::query()->find($entry->target_id) : null;
            $isNew = $batch === null;
            $batch ??= new Batch(['code' => "V2-{$row->id}"]);

            $batch->fill([
                'course_id' => $courseId,
                'name' => $row->name,
                'starts_on' => $row->start_date,
                'ends_on' => $row->end_date,
                'schedule' => $this->schedule($row->schedule ?? null),
                'capacity' => $row->max_students ?: null,
                'status' => $this->status($row->start_date, $row->end_date),
                'is_published' => (bool) $row->is_active,
            ]);

            $batch->save();
            $context->ledger->record('batches', (int) $row->id, $batch, $checksum);
            $report->count('batches', $isNew ? 'created' : 'updated');
        });
    }

    protected function status(?string $starts, ?string $ends): string
    {
        $today = today();

        return match (true) {
            $ends && Carbon::parse($ends)->lt($today) => 'completed',
            $starts && Carbon::parse($starts)->gt($today) => 'upcoming',
            $starts === null => 'upcoming',
            default => 'running',
        };
    }

    /** v2's [{day, start_time, end_time}] as v3's [{day, from, to}]. */
    protected function schedule(mixed $schedule): ?array
    {
        $items = is_string($schedule) ? json_decode($schedule, true) : $schedule;

        if (! is_array($items) || $items === []) {
            return null;
        }

        return collect($items)
            ->map(fn ($slot) => (array) $slot)
            ->filter(fn (array $slot) => filled($slot['day'] ?? null))
            ->map(fn (array $slot) => [
                'day' => ucfirst(strtolower(substr((string) $slot['day'], 0, 3))),
                'from' => substr((string) ($slot['start_time'] ?? $slot['from'] ?? ''), 0, 5),
                'to' => substr((string) ($slot['end_time'] ?? $slot['to'] ?? ''), 0, 5),
            ])
            ->values()
            ->all() ?: null;
    }
}
