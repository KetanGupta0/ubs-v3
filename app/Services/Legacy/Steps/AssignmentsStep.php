<?php

namespace App\Services\Legacy\Steps;

use App\Models\Assignment;
use App\Models\Batch;
use App\Models\Submission;
use App\Models\SubmissionVersion;
use App\Services\Legacy\Context;
use App\Services\Legacy\Files;
use App\Services\Legacy\Ledger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * v2 assignments and every version of every submission.
 *
 * v2 kept each resubmission as its own row. The latest becomes the v3
 * submission; the earlier ones become its version history, feedback and
 * marks included, so nothing a trainer once wrote is lost. Each file is
 * copied, verified and typed from its bytes.
 */
class AssignmentsStep extends Step
{
    public function name(): string
    {
        return 'assignments';
    }

    public function sourceTables(): array
    {
        return ['assignments', 'assignment_submissions'];
    }

    public function run(Context $context): void
    {
        $source = $context->source();

        $source->table('assignments')->orderBy('id')->each(fn (object $row) => $this->assignment($context, $row));

        $source->table('assignment_submissions')
            ->orderBy('assignment_id')->orderBy('student_id')->orderBy('submission_number')->orderBy('id')
            ->get()
            ->groupBy(fn (object $row) => "{$row->assignment_id}-{$row->student_id}")
            ->each(fn ($versions) => $this->submission($context, $versions->values()));
    }

    protected function assignment(Context $context, object $row): void
    {
        $report = $context->report;
        $report->count('assignments', 'read');
        $batch = Batch::query()->find($context->ledger->targetId('batches', (int) $row->batch_id));

        if (! $batch) {
            $report->count('assignments', 'skipped');
            $report->warn('assignments', 'batch_missing', "assignments#{$row->id}", 'Its batch was not imported; not imported, nor its submissions.');

            return;
        }

        $checksum = Ledger::checksum($row);
        $entry = $context->ledger->find('assignments', (int) $row->id);

        if ($entry && $entry->source_checksum === $checksum) {
            $report->count('assignments', 'unchanged');

            return;
        }

        $assignment = $entry ? Assignment::query()->find($entry->target_id) : null;
        $isNew = $assignment === null;
        $assignment ??= new Assignment;

        $assignment->forceFill([
            'course_id' => $batch->course_id,
            'batch_id' => $batch->id,
            'title' => $row->title,
            'brief' => trim(strip_tags(implode("\n\n", array_filter([$row->description, $row->instructions])))) ?: $row->title,
            'due_at' => $row->due_at,
            'max_marks' => (int) $row->max_marks,
            'allow_late' => true,
            'is_published' => true,
        ])->save();

        $context->ledger->record('assignments', (int) $row->id, $assignment, $checksum);
        $report->count('assignments', $isNew ? 'created' : 'updated');
    }

    /** @param  Collection<int, object>  $versions  one student's rows for one assignment, oldest first */
    protected function submission(Context $context, $versions): void
    {
        $report = $context->report;
        $first = $versions->first();
        $assignment = Assignment::query()->find($context->ledger->targetId('assignments', (int) $first->assignment_id));
        $userId = $context->ledger->targetId('users', (int) $first->student_id);

        if (! $assignment) {
            return;
        }

        $report->count('assignments', 'read', $versions->count());

        if (! $userId) {
            $report->count('assignments', 'skipped', $versions->count());
            $report->warn('assignments', 'student_missing', "assignment_submissions#{$first->id}", 'Its student was not imported; not imported.');

            return;
        }

        $latest = $versions->last();
        $checksum = Ledger::checksum(['rows' => $versions->all()]);
        $entry = $context->ledger->find('assignment_submissions', (int) $latest->id);

        if ($entry && $entry->source_checksum === $checksum) {
            $report->count('assignments', 'unchanged', $versions->count());

            return;
        }

        $submission = Submission::query()->firstOrNew(['assignment_id' => $assignment->id, 'user_id' => $userId]);
        $isNew = ! $submission->exists;

        $submission->forceFill([
            ...$this->attributes($context, $latest, $assignment, $userId),
            'version' => max((int) $latest->submission_number, $versions->count()),
        ])->save();

        $context->ledger->record('assignment_submissions', (int) $latest->id, $submission, $checksum);
        $report->count('assignments', $isNew ? 'created' : 'updated');

        foreach ($versions->slice(0, -1)->values() as $index => $row) {
            $version = SubmissionVersion::query()->firstOrNew([
                'submission_id' => $submission->id,
                'version' => (int) $row->submission_number ?: $index + 1,
            ]);
            $version->forceFill($this->attributes($context, $row, $assignment, $userId, forVersion: true))->save();
            $context->ledger->record('assignment_submissions', (int) $row->id, $version, Ledger::checksum($row));
            $report->count('assignments', 'created');
        }
    }

    /** @return array<string, mixed> */
    protected function attributes(Context $context, object $row, Assignment $assignment, int $userId, bool $forVersion = false): array
    {
        $file = $row->file_path
            ? Files::copy($context, 'assignments', "assignment_submissions#{$row->id}", 'local', $row->file_path, 'private', "submissions/{$assignment->id}/{$userId}")
            : null;

        $marked = $row->marks !== null || $row->graded_at !== null;

        return [
            'notes' => $row->notes,
            'files' => $file ? [[
                'name' => $row->original_name ?: basename($file['path']),
                'path' => $file['path'],
                'mime' => $file['mime'],
                'size' => $file['size'],
            ]] : null,
            'submitted_at' => $row->submitted_at,
            'is_late' => $assignment->due_at && $row->submitted_at && Carbon::parse($row->submitted_at)->gt($assignment->due_at),
            'status' => match (true) {
                ! $forVersion && (bool) ($row->resubmission_allowed ?? false) => 'returned',
                $marked => 'evaluated',
                default => 'submitted',
            },
            'marks' => $row->marks !== null ? (int) round((float) $row->marks) : null,
            'feedback' => $row->feedback,
            'evaluated_by' => $context->ledger->targetId('users', $row->graded_by ? (int) $row->graded_by : null),
            'evaluated_at' => $row->graded_at,
        ];
    }
}
