<?php

namespace App\Services\Legacy\Steps;

use App\Models\CourseReview;
use App\Services\Legacy\Context;
use App\Services\Legacy\Ledger;
use Illuminate\Support\Str;

/**
 * v2 course reviews → v3 course reviews, with their status and reply.
 *
 * None is shown on the public site on the strength of the import: v2 never
 * asked whether a review could be published, and v3 shows one only with the
 * student's recorded consent (decision D3). Approved ones stay approved for
 * when that consent is given.
 */
class ReviewsStep extends Step
{
    public function name(): string
    {
        return 'reviews';
    }

    public function sourceTables(): array
    {
        return ['reviews'];
    }

    public function run(Context $context): void
    {
        $report = $context->report;

        $context->source()->table('reviews')->orderBy('id')->each(function (object $row) use ($context, $report) {
            $report->count('reviews', 'read');
            $source = "reviews#{$row->id}";
            $userId = $context->ledger->targetId('users', (int) $row->student_id);
            $courseId = $context->ledger->targetId('training_programs', (int) $row->program_id);

            if (! $userId || ! $courseId) {
                $report->count('reviews', 'skipped');
                $report->warn('reviews', 'missing_reference', $source, ($userId ? 'Its program' : 'Its student').' was not imported; not imported.');

                return;
            }

            $checksum = Ledger::checksum($row);
            $entry = $context->ledger->find('reviews', (int) $row->id);

            if ($entry && $entry->source_checksum === $checksum) {
                $report->count('reviews', 'unchanged');

                return;
            }

            $review = $entry ? CourseReview::query()->find($entry->target_id) : null;

            if (! $review && CourseReview::query()->where('user_id', $userId)->where('course_id', $courseId)->exists()) {
                $report->count('reviews', 'skipped');
                $report->warn('reviews', 'duplicate', $source, 'The student already has a review of this course in v3; not imported twice.');

                return;
            }

            $isNew = $review === null;
            $review ??= new CourseReview;

            $review->forceFill([
                'user_id' => $userId,
                'course_id' => $courseId,
                'enrollment_id' => $context->ledger->targetId('enrollments', $row->enrollment_id ? (int) $row->enrollment_id : null),
                'rating' => max(1, min(5, (int) $row->rating)),
                'title' => filled($row->title) ? Str::limit(trim($row->title), 115) : null,
                'body' => trim(strip_tags((string) $row->body)),
                'status' => in_array($row->status, CourseReview::STATUSES, true) ? $row->status : 'pending',
                'may_publish' => false,
                'reply' => $row->admin_reply,
                'replied_at' => $row->admin_reply ? ($row->admin_replied_at ?? $row->updated_at) : null,
                'created_at' => $row->created_at ?? now(),
            ])->save();

            $context->ledger->record('reviews', (int) $row->id, $review, $checksum);
            $report->count('reviews', $isNew ? 'created' : 'updated');
        });
    }
}
