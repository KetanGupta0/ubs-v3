<?php

namespace App\Services\Legacy\Steps;

use App\Models\Batch;
use App\Models\Material;
use App\Services\Legacy\Context;
use App\Services\Legacy\Files;
use App\Services\Legacy\Ledger;

/**
 * v2 study materials (one batch each) → v3 materials, kept to that batch.
 *
 * v3 materials belong to a course and may be narrowed to one batch; v2's were
 * always one batch's, so each stays that batch's rather than appearing for
 * every batch of the course. The file is copied, verified and re-typed.
 */
class MaterialsStep extends Step
{
    public function name(): string
    {
        return 'materials';
    }

    public function sourceTables(): array
    {
        return ['study_materials'];
    }

    public function run(Context $context): void
    {
        $report = $context->report;

        if ($context->storagePath === null) {
            $report->count('materials', 'skipped', 0);
            $report->warn('materials', 'storage_not_given', '--storage', 'No storage copy given, so no material was imported. Run again with --storage=PATH.');

            return;
        }

        $context->source()->table('study_materials')->orderBy('id')->each(function (object $row) use ($context, $report) {
            $report->count('materials', 'read');
            $source = "study_materials#{$row->id}";
            $batchId = $context->ledger->targetId('batches', (int) $row->batch_id);
            $batch = $batchId ? Batch::query()->find($batchId) : null;

            if (! $batch) {
                $report->count('materials', 'skipped');
                $report->warn('materials', 'batch_missing', $source, 'Its batch was not imported; not imported.');

                return;
            }

            $checksum = Ledger::checksum($row);
            $entry = $context->ledger->find('study_materials', (int) $row->id);

            if ($entry && $entry->source_checksum === $checksum) {
                $report->count('materials', 'unchanged');

                return;
            }

            $material = $entry ? Material::query()->find($entry->target_id) : null;
            $file = $material?->path ? null : Files::copy($context, 'materials', $source, 'public', $row->file_path, 'private', "courses/{$batch->course_id}");

            if (! $material && ! $file) {
                $report->count('materials', 'skipped');

                return;
            }

            $isNew = $material === null;
            $material ??= new Material;

            $material->fill([
                'course_id' => $batch->course_id,
                'batch_id' => $batch->id,
                'title' => $row->title,
                'description' => $row->description,
                'is_downloadable' => true,
                'sort_order' => (int) ($row->sort_order ?? 0),
                'uploaded_by' => $context->ledger->targetId('users', $row->uploaded_by ? (int) $row->uploaded_by : null),
                ...($file ? ['path' => $file['path'], 'mime' => $file['mime'], 'size' => $file['size']] : []),
            ])->save();

            $context->ledger->record('study_materials', (int) $row->id, $material, $checksum);
            $report->count('materials', $isNew ? 'created' : 'updated');
        });
    }
}
