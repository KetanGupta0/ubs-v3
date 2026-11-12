<?php

namespace App\Services\Legacy\Steps;

use App\Models\Batch;
use App\Models\Enrollment;
use App\Services\Admin\Auditor;
use App\Services\Chat\Rooms;
use App\Services\Legacy\Context;
use App\Services\Legacy\Ledger;

/**
 * v2 enrolments (and batch memberships) → v3 enrolments.
 *
 * One enrolment per student and course. Its batch is the one v2's enrolment
 * names, or failing that the batch v2 put the student in. A v2 enrolment
 * still waiting for payment is not an enrolment here: v3 would open the
 * course to somebody who never paid. It is listed in the report instead, so
 * a fee can be raised. Whether a fee was paid is set by the payments step.
 */
class EnrolmentsStep extends Step
{
    public const STATUSES = [
        'active' => 'active',
        'completed' => 'completed',
        'cancelled' => 'dropped',
        'blocked' => 'suspended',
        'pending_assignment' => 'active',
    ];

    public function name(): string
    {
        return 'enrolments';
    }

    public function sourceTables(): array
    {
        return ['enrollments', 'batch_students'];
    }

    public function run(Context $context): void
    {
        $report = $context->report;
        $source = $context->source();
        $touchedBatches = [];

        // v2 batch memberships by student, to find a batch the enrolment does not name.
        $memberships = $source->table('batch_students')->get()->groupBy('student_id');

        $source->table('enrollments')->orderBy('id')->each(function (object $row) use ($context, $report, $memberships, &$touchedBatches) {
            $report->count('enrolments', 'read');
            $ref = "enrollments#{$row->id}";

            if ($row->status === 'pending_payment') {
                $report->count('enrolments', 'skipped');
                $report->warn('enrolments', 'pending_payment', $ref, 'Waiting for payment on the old site; not enrolled. Raise a fee for it if it is still wanted.');

                return;
            }

            $userId = $context->ledger->targetId('users', (int) $row->student_id);
            $courseId = $context->ledger->targetId('training_programs', (int) $row->program_id);

            if (! $userId || ! $courseId) {
                $report->count('enrolments', 'skipped');
                $report->warn('enrolments', 'missing_reference', $ref, ($userId ? 'Its program' : 'Its student').' was not imported; not imported.');

                return;
            }

            $batchId = $context->ledger->targetId('batches', $row->batch_id ? (int) $row->batch_id : null)
                ?? $this->batchFromMembership($context, $memberships->get($row->student_id), $courseId);

            $this->save($context, 'enrollments', (int) $row->id, Ledger::checksum($row), [
                'user_id' => $userId,
                'course_id' => $courseId,
                'batch_id' => $batchId,
                'status' => self::STATUSES[$row->status] ?? 'active',
                'enrolled_at' => $row->enrolled_at ?? $row->created_at ?? now(),
                'completed_at' => $row->status === 'completed' ? ($row->updated_at ?? now()) : null,
                'dropped_at' => $row->status === 'cancelled' ? ($row->updated_at ?? now()) : null,
            ], $row, $ref, $touchedBatches);

            if (! isset(self::STATUSES[$row->status])) {
                $report->warn('enrolments', 'unknown_status', $ref, 'Status on the old site was not one v3 knows; imported as active.');
            }
        });

        // Batch memberships with no enrolment behind them.
        $source->table('batch_students')->orderBy('id')->each(function (object $row) use ($context, $report, &$touchedBatches) {
            $batchId = $context->ledger->targetId('batches', (int) $row->batch_id);
            $userId = $context->ledger->targetId('users', (int) $row->student_id);
            $courseId = $batchId ? Batch::query()->whereKey($batchId)->value('course_id') : null;

            if (! $batchId || ! $userId || Enrollment::query()->where('user_id', $userId)->where('course_id', $courseId)->exists()) {
                return;
            }

            $report->count('enrolments', 'read');
            $this->save($context, 'batch_students', (int) $row->id, Ledger::checksum($row), [
                'user_id' => $userId,
                'course_id' => $courseId,
                'batch_id' => $batchId,
                'status' => 'active',
                'enrolled_at' => $row->joined_at ?? now(),
            ], null, "batch_students#{$row->id}", $touchedBatches);
        });

        // Seats and each batch's group chat, as the Enroller would leave them.
        foreach (array_keys($touchedBatches) as $batchId) {
            if ($batch = Batch::query()->find($batchId)) {
                $batch->recountSeats();
                app(Rooms::class)->forBatch($batch);
            }
        }
    }

    protected function save(Context $context, string $table, int $id, string $checksum, array $attributes, ?object $row, string $ref, array &$touchedBatches): void
    {
        $report = $context->report;
        $entry = $context->ledger->find($table, $id);

        if ($entry && $entry->source_checksum === $checksum) {
            $report->count('enrolments', 'unchanged');

            return;
        }

        $enrolment = $entry ? Enrollment::query()->find($entry->target_id) : null;
        $enrolment ??= Enrollment::query()
            ->where('user_id', $attributes['user_id'])
            ->where('course_id', $attributes['course_id'])
            ->first();
        $isNew = $enrolment === null;
        $enrolment ??= new Enrollment(['source' => 'import']);

        $enrolment->fill($attributes)->save();
        $context->ledger->record($table, $id, $enrolment, $checksum);
        $report->count('enrolments', $isNew ? 'created' : 'updated');

        if ($enrolment->batch_id) {
            $touchedBatches[$enrolment->batch_id] = true;
        }

        // What v2 staff wrote down about it stays on record, never shown to the student.
        if ($isNew && $row && (filled($row->admin_notes ?? null) || ($row->certificate_bypass ?? false))) {
            app(Auditor::class)->action('enrolment.imported_notes', $enrolment, array_filter([
                'admin_notes' => filled($row->admin_notes ?? null) ? ['from' => null, 'to' => $row->admin_notes] : null,
                'certificate_bypass' => ($row->certificate_bypass ?? false) ? ['from' => null, 'to' => $row->bypass_reason ?: 'Allowed a certificate without meeting the rules; no reason recorded.'] : null,
            ]), "Notes from the old site ({$ref})");
        }
    }

    protected function batchFromMembership(Context $context, $memberships, int $courseId): ?int
    {
        foreach ($memberships ?? [] as $membership) {
            $batchId = $context->ledger->targetId('batches', (int) $membership->batch_id);

            if ($batchId && Batch::query()->whereKey($batchId)->where('course_id', $courseId)->exists()) {
                return $batchId;
            }
        }

        return null;
    }
}
