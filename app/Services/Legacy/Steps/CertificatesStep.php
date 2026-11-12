<?php

namespace App\Services\Legacy\Steps;

use App\Models\Certificate;
use App\Models\Course;
use App\Services\Legacy\Context;
use App\Services\Legacy\Files;
use App\Services\Legacy\Ledger;
use Illuminate\Support\Str;

/**
 * v2 certificates → v3 certificates.
 *
 * Anything printed on paper keeps working: the certificate number is kept
 * exactly, and the token its QR code carries is kept as legacy_token, which
 * /verify still answers to. Each also gets a v3 verification code. A
 * revoked certificate stays revoked and still answers, saying so. The PDF
 * the old site rendered is copied when the storage copy has it, so the
 * document a student holds looks the same when downloaded again.
 */
class CertificatesStep extends Step
{
    public function name(): string
    {
        return 'certificates';
    }

    public function sourceTables(): array
    {
        return ['certificates'];
    }

    public function run(Context $context): void
    {
        $report = $context->report;

        $context->source()->table('certificates')->orderBy('id')->each(function (object $row) use ($context, $report) {
            $report->count('certificates', 'read');
            $source = "certificates#{$row->id}";
            $userId = $context->ledger->targetId('users', (int) $row->student_id);
            $course = Course::query()->find($context->ledger->targetId('training_programs', (int) $row->program_id));
            $batchId = $context->ledger->targetId('batches', $row->batch_id ? (int) $row->batch_id : null);

            if (! $userId || ! $course) {
                $report->count('certificates', 'skipped');
                $report->warn('certificates', 'missing_reference', $source, ($userId ? 'Its program' : 'Its student').' was not imported; not imported. Its QR code will not verify until it is.');

                return;
            }

            $checksum = Ledger::checksum($row);
            $entry = $context->ledger->find('certificates', (int) $row->id);

            if ($entry && $entry->source_checksum === $checksum) {
                $report->count('certificates', 'unchanged');

                return;
            }

            $certificate = $entry ? Certificate::query()->find($entry->target_id) : null;

            if (! $certificate) {
                $clash = Certificate::query()
                    ->where('number', $row->certificate_number)
                    ->orWhere(fn ($q) => $q->where('user_id', $userId)->where('course_id', $course->id)->where('batch_id', $batchId))
                    ->exists();

                if ($clash) {
                    $report->count('certificates', 'skipped');
                    $report->warn('certificates', 'duplicate', $source, 'v3 already holds this certificate number, or a certificate for the same student, course and batch; not imported.');

                    return;
                }
            }

            $isNew = $certificate === null;
            $certificate ??= new Certificate(['number' => $row->certificate_number]);

            $certificate->forceFill([
                'user_id' => $userId,
                'course_id' => $course->id,
                'batch_id' => $batchId,
                'legacy_token' => $row->token,
                'title' => $course->title,
                'issued_at' => $row->issued_at ?? $row->created_at ?? now(),
                'revoked_at' => $row->revoked_at,
                'revoked_reason' => $row->revoked_at ? Str::limit((string) ($row->revocation_reason ?: 'Withdrawn on the old site.'), 250) : null,
            ]);

            if (filled($row->pdf_path ?? null) && ! $certificate->pdf_path) {
                $file = Files::copy($context, 'certificates', $source, 'any', $row->pdf_path, 'private', 'certificates/legacy');
                $certificate->pdf_path = $file['path'] ?? null;
            } elseif ($isNew) {
                $report->warn('certificates', 'no_pdf', $source, 'The old site\'s PDF is not in the storage copy; v3 will draw it in its own design the first time it is downloaded.');
            }

            $certificate->save();
            $context->ledger->record('certificates', (int) $row->id, $certificate, $checksum);
            $report->count('certificates', $isNew ? 'created' : 'updated');
        });
    }
}
