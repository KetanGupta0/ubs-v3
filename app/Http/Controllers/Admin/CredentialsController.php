<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Batch;
use App\Models\Certificate;
use App\Models\Enrollment;
use App\Models\InternshipDocument;
use App\Models\MentorReview;
use App\Services\Admin\Auditor;
use App\Services\Lms\Credentials;
use App\Services\Lms\ResultCard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Certificates, internship paperwork and weekly mentor reviews.
 *
 * Issuing is deliberately a decision rather than a job that runs nightly.
 * Somebody signs these, a college relies on them, and an automatic certificate
 * for a student who scraped through on a technicality is a document we would
 * have to withdraw.
 */
class CredentialsController extends Controller
{
    public function index(Request $request, Batch $batch, ResultCard $card): Response
    {
        $batch->load('course:id,title,type,issues_certificate,pass_percent,minimum_attendance');

        $enrolments = $batch->enrollments()
            ->with('user:id,name,email')
            ->get();

        $certificates = Certificate::query()
            ->where('batch_id', $batch->id)
            ->get()
            ->keyBy('user_id');

        $documents = InternshipDocument::query()
            ->where('batch_id', $batch->id)
            ->get()
            ->groupBy('user_id');

        return Inertia::render('admin/batches/Credentials', [
            'batch' => [
                'id' => $batch->id,
                'name' => $batch->name,
                'course' => $batch->course?->title,
                'isInternship' => $batch->course?->type === 'internship',
                'issuesCertificate' => (bool) $batch->course?->issues_certificate,
                'passPercent' => $batch->course?->pass_percent,
                'minimumAttendance' => $batch->course?->minimum_attendance,
            ],
            'kinds' => collect(InternshipDocument::KINDS)->map(fn (string $kind) => [
                'value' => $kind,
                'label' => (new InternshipDocument(['kind' => $kind]))->kindLabel(),
            ]),
            'students' => $enrolments->map(function (Enrollment $enrolment) use ($card, $certificates, $documents) {
                $result = $card->for($enrolment);
                $held = $documents[$enrolment->user_id] ?? collect();

                return [
                    'enrolmentId' => $enrolment->id,
                    'userId' => $enrolment->user_id,
                    'name' => $enrolment->user->name,
                    'progress' => $enrolment->progress_percent,
                    'overall' => $result['overall'],
                    'grade' => $result['grade'],
                    'passing' => $result['passing'],
                    'shortOnAttendance' => $result['shortOnAttendance'],
                    'attendance' => $result['attendance']['percent'],
                    'certificate' => ($certificates[$enrolment->user_id] ?? null) ? [
                        'id' => $certificates[$enrolment->user_id]->id,
                        'number' => $certificates[$enrolment->user_id]->number,
                        'issuedAt' => $certificates[$enrolment->user_id]->issued_at->format('j M Y'),
                        'revoked' => ! $certificates[$enrolment->user_id]->isValid(),
                    ] : null,
                    'documents' => $held->map(fn (InternshipDocument $document) => [
                        'id' => $document->id,
                        'kind' => $document->kind,
                        'kindLabel' => $document->kindLabel(),
                        'number' => $document->number,
                        'issuedAt' => $document->issued_at->format('j M Y'),
                    ])->values(),
                ];
            })->sortBy('name')->values(),
        ]);
    }

    public function issueCertificate(Request $request, Enrollment $enrolment, Credentials $credentials, Auditor $auditor): RedirectResponse
    {
        $validated = $this->validatedInput($request, ['force' => ['boolean']]);

        try {
            $certificate = $credentials->issueCertificate(
                $enrolment,
                $request->user(),
                $validated['force'] ?? false,
            );
        } catch (RuntimeException $e) {
            return back()->withErrors(['certificate' => $e->getMessage()]);
        }

        $auditor->action('certificate.issued', $certificate, [
            'user_id' => $enrolment->user_id,
            'forced' => $validated['force'] ?? false,
        ], $certificate->number);

        return back()->with('success', "Issued {$certificate->number}.");
    }

    public function revokeCertificate(Request $request, Certificate $certificate, Auditor $auditor): RedirectResponse
    {
        $validated = $this->validatedInput($request, [
            'reason' => ['required', 'string', 'max:200'],
        ]);

        // The row stays so the public check keeps working and says it was
        // withdrawn. Deleting it would make a verification silently fail.
        $certificate->forceFill([
            'revoked_at' => now(),
            'revoked_reason' => $validated['reason'],
        ])->save();

        $auditor->action('certificate.revoked', $certificate, [
            'reason' => $validated['reason'],
        ], $certificate->number);

        return back()->with('success', 'Withdrawn. Anyone checking the code will now be told.');
    }

    public function issueDocument(Request $request, Enrollment $enrolment, Credentials $credentials, Auditor $auditor): RedirectResponse
    {
        $validated = $this->validatedInput($request, [
            'kind' => ['required', Rule::in(InternshipDocument::KINDS)],
        ]);

        $document = $credentials->issueInternshipDocument(
            $enrolment,
            $validated['kind'],
            $request->user(),
        );

        $auditor->action('internship_document.issued', $document, [
            'kind' => $document->kind,
            'user_id' => $enrolment->user_id,
        ], $document->number);

        return back()->with('success', $document->kindLabel().' issued.');
    }

    public function downloadCertificate(Certificate $certificate, Credentials $credentials): StreamedResponse
    {
        $path = $credentials->renderCertificate($certificate);

        return Storage::disk('private')->download(
            $path,
            str($certificate->number)->replace('/', '-')->toString().'.pdf',
        );
    }

    public function downloadDocument(InternshipDocument $document, Credentials $credentials): StreamedResponse
    {
        $path = $credentials->renderInternshipDocument($document);

        return Storage::disk('private')->download(
            $path,
            str($document->number)->replace('/', '-')->toString().'.pdf',
        );
    }

    /* --------------------------------------------------- mentor reviews */

    public function reviews(Request $request, Batch $batch): Response
    {
        $batch->load('course:id,title,type');

        return Inertia::render('admin/batches/Reviews', [
            'batch' => [
                'id' => $batch->id,
                'name' => $batch->name,
                'course' => $batch->course?->title,
                'week' => $batch->weekNumber(),
            ],
            'students' => $batch->enrollments()
                ->active()
                ->with('user:id,name')
                ->get()
                ->map(fn (Enrollment $enrolment) => [
                    'value' => $enrolment->user_id,
                    'label' => $enrolment->user->name,
                ])
                ->sortBy('label')
                ->values(),
            'criteria' => collect(MentorReview::CRITERIA)->map(fn (string $label, string $key) => [
                'key' => $key,
                'label' => $label,
            ])->values(),
            'reviews' => MentorReview::query()
                ->where('batch_id', $batch->id)
                ->with(['user:id,name', 'reviewer:id,name'])
                ->orderByDesc('week_number')
                ->get()
                ->map(fn (MentorReview $review) => [
                    'id' => $review->id,
                    'student' => $review->user->name,
                    'userId' => $review->user_id,
                    'week' => $review->week_number,
                    'reviewedOn' => $review->reviewed_on->format('j M Y'),
                    'summary' => $review->summary,
                    'wentWell' => $review->what_went_well,
                    'toImprove' => $review->to_improve,
                    'marks' => $review->marks ?? [],
                    'average' => $review->averageMark(),
                    'reviewer' => $review->reviewer?->name,
                ]),
        ]);
    }

    public function storeReview(Request $request, Batch $batch): RedirectResponse
    {
        $validated = $this->validatedInput($request, [
            'user_id' => ['required', 'integer'],
            'week_number' => ['required', 'integer', 'min:1', 'max:104'],
            'reviewed_on' => ['required', 'date'],
            'summary' => ['required', 'string', 'max:3000'],
            'what_went_well' => ['nullable', 'string', 'max:2000'],
            'to_improve' => ['nullable', 'string', 'max:2000'],
            'marks' => ['array'],
            'marks.*' => ['nullable', 'integer', 'min:0', 'max:10'],
        ]);

        abort_unless($batch->enrollments()->where('user_id', $validated['user_id'])->exists(), 404);

        MentorReview::query()->updateOrCreate(
            [
                'user_id' => $validated['user_id'],
                'batch_id' => $batch->id,
                'week_number' => $validated['week_number'],
            ],
            [
                'reviewer_id' => $request->user()->id,
                'reviewed_on' => $validated['reviewed_on'],
                'summary' => $validated['summary'],
                'what_went_well' => $validated['what_went_well'],
                'to_improve' => $validated['to_improve'],
                'marks' => array_filter($validated['marks'] ?? [], fn ($value) => $value !== null),
            ],
        );

        return back()->with('success', 'Review saved.');
    }

    public function destroyReview(Batch $batch, MentorReview $review): RedirectResponse
    {
        abort_unless($review->batch_id === $batch->id, 404);

        $review->delete();

        return back()->with('success', 'Review removed.');
    }
}
