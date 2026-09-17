<?php

namespace App\Services\Lms;

use App\Models\Certificate;
use App\Models\Enrollment;
use App\Models\InternshipDocument;
use App\Models\MentorReview;
use App\Models\Setting;
use App\Models\Submission;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Certificates, and the four documents an internship produces.
 *
 * Every one is numbered and carries a public verification code, because a
 * certificate nobody can check is decoration, and a college will check.
 *
 * The project report is assembled from what the student actually submitted
 * across the internship rather than written at the end. That is the difference
 * between a report and a fiction.
 */
class Credentials
{
    public function __construct(protected ResultCard $resultCard) {}

    /* ---------------------------------------------------------- certificate */

    public function issueCertificate(Enrollment $enrolment, ?User $issuedBy = null, bool $force = false): Certificate
    {
        $existing = Certificate::query()
            ->where('user_id', $enrolment->user_id)
            ->where('course_id', $enrolment->course_id)
            ->where('batch_id', $enrolment->batch_id)
            ->first();

        if ($existing) {
            return $existing;
        }

        $result = $this->resultCard->for($enrolment);

        if (! $force && ! $result['passing']) {
            throw new RuntimeException($result['shortOnAttendance']
                ? 'Attendance is below the minimum for this course.'
                : 'This student is not passing yet.');
        }

        $certificate = Certificate::query()->create([
            'user_id' => $enrolment->user_id,
            'course_id' => $enrolment->course_id,
            'batch_id' => $enrolment->batch_id,
            'issued_at' => now(),
            'title' => $enrolment->course->title,
            'final_percent' => $result['overall'],
            'grade' => $result['grade'],
            'issued_by' => $issuedBy?->id,
        ]);

        $this->renderCertificate($certificate);

        return $certificate;
    }

    public function renderCertificate(Certificate $certificate): string
    {
        if ($certificate->pdf_path && Storage::disk('private')->exists($certificate->pdf_path)) {
            return $certificate->pdf_path;
        }

        $certificate->loadMissing(['user', 'course', 'batch']);

        $pdf = Pdf::loadView('credentials.certificate', [
            'certificate' => $certificate,
            'company' => $this->company(),
        ])->setPaper('a4', 'landscape');

        $path = 'certificates/'.str($certificate->number)->replace('/', '-')->toString().'.pdf';

        Storage::disk('private')->put($path, $pdf->output());
        $certificate->forceFill(['pdf_path' => $path])->save();

        return $path;
    }

    /* -------------------------------------------------- internship documents */

    public function issueInternshipDocument(
        Enrollment $enrolment,
        string $kind,
        ?User $issuedBy = null,
        array $extra = [],
    ): InternshipDocument {
        if (! in_array($kind, InternshipDocument::KINDS, true)) {
            throw new RuntimeException("Unknown document: {$kind}");
        }

        $existing = InternshipDocument::query()
            ->where('user_id', $enrolment->user_id)
            ->where('course_id', $enrolment->course_id)
            ->where('kind', $kind)
            ->first();

        if ($existing) {
            return $existing;
        }

        $document = InternshipDocument::query()->create([
            'user_id' => $enrolment->user_id,
            'course_id' => $enrolment->course_id,
            'batch_id' => $enrolment->batch_id,
            'kind' => $kind,
            'issued_at' => now(),
            'payload' => $this->payloadFor($enrolment, $kind, $extra),
            'issued_by' => $issuedBy?->id,
        ]);

        $this->renderInternshipDocument($document);

        return $document;
    }

    public function renderInternshipDocument(InternshipDocument $document): string
    {
        if ($document->pdf_path && Storage::disk('private')->exists($document->pdf_path)) {
            return $document->pdf_path;
        }

        $document->loadMissing(['user.profile.college', 'course', 'batch']);

        // The completion certificate is its own landscape template; the other
        // three are letters. Mapping explicitly beats interpolating the kind
        // into a view name and finding out at render time.
        $view = match ($document->kind) {
            'certificate' => 'credentials.internship_certificate',
            default => "credentials.{$document->kind}",
        };

        $pdf = Pdf::loadView($view, [
            'document' => $document,
            'payload' => $document->payload ?? [],
            'company' => $this->company(),
        ])->setPaper('a4', $document->kind === 'certificate' ? 'landscape' : 'portrait');

        $path = 'internships/'.str($document->number)->replace('/', '-')->toString().'.pdf';

        Storage::disk('private')->put($path, $pdf->output());
        $document->forceFill(['pdf_path' => $path])->save();

        return $path;
    }

    /**
     * What each document says, gathered at the moment it is issued.
     *
     * Frozen into the row for the same reason an invoice freezes its buyer: a
     * document that re-reads live data is not a record of anything.
     *
     * @return array<string, mixed>
     */
    protected function payloadFor(Enrollment $enrolment, string $kind, array $extra): array
    {
        $student = $enrolment->user->loadMissing('profile.college');
        $course = $enrolment->course;
        $batch = $enrolment->batch;

        $base = [
            'student' => $student->name,
            'email' => $student->email,
            'enrollmentNumber' => $student->profile?->enrollment_number,
            'college' => $student->profile?->college?->name,
            'courseOfStudy' => $student->profile?->course_of_study,
            'title' => $course->title,
            'mode' => $course->mode,
            'projectFocus' => $course->project_focus,
            'startsOn' => $batch?->starts_on?->toDateString(),
            'endsOn' => $batch?->ends_on?->toDateString(),
            'durationLabel' => $course->durationLabel(),
            ...$extra,
        ];

        return match ($kind) {
            'project_report' => [...$base, 'work' => $this->workFor($enrolment)],
            'mentor_evaluation' => [...$base, ...$this->evaluationFor($enrolment)],
            'certificate' => [...$base, 'result' => $this->resultCard->for($enrolment)],
            default => $base,
        };
    }

    /**
     * The student's own submissions, in order.
     *
     * This is what makes the project report theirs rather than a template with
     * a name typed into it.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function workFor(Enrollment $enrolment): array
    {
        return Submission::query()
            ->where('user_id', $enrolment->user_id)
            ->whereHas('assignment', fn ($query) => $query->where('course_id', $enrolment->course_id))
            ->with('assignment')
            ->whereNotNull('submitted_at')
            ->orderBy('submitted_at')
            ->get()
            ->map(fn (Submission $submission) => [
                'title' => $submission->assignment->title,
                'brief' => str($submission->assignment->brief)->limit(600)->toString(),
                'notes' => $submission->notes,
                'repository' => $submission->repository_url,
                'demo' => $submission->demo_url,
                'submittedOn' => $submission->submitted_at->format('j M Y'),
                'marks' => $submission->marks,
                'maxMarks' => $submission->assignment->max_marks,
                'feedback' => $submission->feedback,
            ])
            ->all();
    }

    /** @return array<string, mixed> */
    protected function evaluationFor(Enrollment $enrolment): array
    {
        $reviews = MentorReview::query()
            ->where('user_id', $enrolment->user_id)
            ->where('batch_id', $enrolment->batch_id)
            ->orderBy('week_number')
            ->get();

        $criteria = collect(MentorReview::CRITERIA)
            ->map(function (string $label, string $key) use ($reviews) {
                $marks = $reviews
                    ->map(fn (MentorReview $review) => $review->marks[$key] ?? null)
                    ->filter(fn ($value) => is_numeric($value));

                return [
                    'key' => $key,
                    'label' => $label,
                    'mark' => $marks->isEmpty() ? null : round($marks->avg(), 1),
                    'outOf' => 10,
                ];
            })
            ->values()
            ->all();

        $marked = collect($criteria)->whereNotNull('mark');

        return [
            'criteria' => $criteria,
            'overall' => $marked->isEmpty() ? null : round($marked->avg('mark'), 1),
            'reviewCount' => $reviews->count(),
            'mentor' => $reviews->last()?->reviewer?->name ?? $enrolment->batch?->trainer?->name,
            'weeks' => $reviews->map(fn (MentorReview $review) => [
                'week' => $review->week_number,
                'summary' => $review->summary,
                'wentWell' => $review->what_went_well,
                'toImprove' => $review->to_improve,
            ])->all(),
            'result' => $this->resultCard->for($enrolment),
        ];
    }

    /** @return array<string, mixed> */
    protected function company(): array
    {
        return [
            'name' => Setting::get('company.legal_name', config('company.legal_name')),
            'trading' => Setting::get('company.name', config('company.name')),
            'email' => Setting::get('company.email', config('company.email')),
            'phone' => Setting::get('company.phone', config('company.phone')),
            'address' => Setting::get('company.address', config('company.address')),
            'city' => Setting::get('company.city'),
            'state' => Setting::get('company.state'),
            'cin' => Setting::get('company.cin', config('company.cin')),
        ];
    }
}
