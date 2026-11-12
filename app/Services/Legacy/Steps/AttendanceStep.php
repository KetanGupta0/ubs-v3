<?php

namespace App\Services\Legacy\Steps;

use App\Models\Attendance;
use App\Services\Legacy\Context;
use App\Services\Legacy\Ledger;

/**
 * v2 attendance → v3 attendance.
 *
 * A v2 row means the student joined, so it becomes "present". Nobody is made
 * absent for a class v2 has no row for: v2 did not record absence, and
 * unknown is not absent. No points are awarded for history.
 */
class AttendanceStep extends Step
{
    public function name(): string
    {
        return 'attendance';
    }

    public function sourceTables(): array
    {
        return ['attendances'];
    }

    public function run(Context $context): void
    {
        $report = $context->report;

        $context->source()->table('attendances')->orderBy('id')->each(function (object $row) use ($context, $report) {
            $report->count('attendance', 'read');
            $sessionId = $context->ledger->targetId('live_classes', (int) $row->live_class_id);
            $userId = $context->ledger->targetId('users', (int) $row->student_id);

            if (! $sessionId || ! $userId) {
                $report->count('attendance', 'skipped');
                $report->warn('attendance', 'missing_reference', "attendances#{$row->id}", ($sessionId ? 'Its student' : 'Its class').' was not imported; not imported.');

                return;
            }

            $checksum = Ledger::checksum($row);
            $entry = $context->ledger->find('attendances', (int) $row->id);

            if ($entry && $entry->source_checksum === $checksum) {
                $report->count('attendance', 'unchanged');

                return;
            }

            $attendance = Attendance::query()->firstOrNew(['live_session_id' => $sessionId, 'user_id' => $userId]);
            $isNew = ! $attendance->exists;

            $attendance->fill([
                'status' => 'present',
                'marked_at' => $row->joined_at,
                'note' => $row->is_manual ? 'Marked by hand on the old site' : 'Joined on the old site',
            ])->save();

            $context->ledger->record('attendances', (int) $row->id, $attendance, $checksum);
            $report->count('attendance', $isNew ? 'created' : 'updated');
        });
    }
}
