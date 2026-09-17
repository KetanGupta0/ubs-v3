<?php

namespace App\Http\Controllers\Student;

use App\Models\Announcement;
use App\Models\Batch;
use App\Models\Certificate;
use App\Models\Enrollment;
use App\Models\InternshipDocument;
use App\Models\StudentWarning;
use App\Services\Lms\Activity;
use App\Services\Lms\Credentials;
use App\Services\Lms\Leaderboard;
use App\Services\Lms\ResultCard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Results, leaderboard, certificates, announcements and warnings.
 *
 * Grouped because they are all the same thing from different angles: how this
 * student is doing, and what the platform has to say about it.
 */
class ProgressController extends StudentController
{
    public function results(Request $request, ResultCard $card): Response
    {
        $enrolments = Enrollment::query()
            ->forStudent($this->student($request))
            ->with(['course', 'batch'])
            ->get();

        return Inertia::render('student/Results', [
            'courses' => $enrolments->map(fn (Enrollment $enrolment) => [
                'courseId' => $enrolment->course_id,
                'course' => $enrolment->course->title,
                'type' => $enrolment->course->type,
                'batch' => $enrolment->batch?->name,
                'result' => $card->for($enrolment),
            ]),
        ]);
    }

    public function leaderboard(Request $request, Leaderboard $leaderboard): Response
    {
        $student = $this->student($request);

        $batches = Batch::query()
            ->whereIn('id', $this->enrolledBatchIds($request))
            ->with('course:id,title')
            ->get();

        return Inertia::render('student/Leaderboard', [
            'boards' => $batches->map(fn (Batch $batch) => [
                'batchId' => $batch->id,
                'batch' => $batch->name,
                'course' => $batch->course?->title,
                'rows' => $leaderboard->forBatch($batch),
                'mine' => $leaderboard->standingFor($student, $batch),
            ]),
            'points' => $leaderboard->totalFor($student),
            'howItWorks' => collect(Activity::POINTS)
                ->filter(fn (int $points) => $points > 0)
                ->map(fn (int $points, string $source) => [
                    'source' => str($source)->replace('.', ' ')->ucfirst()->toString(),
                    'points' => $points,
                ])
                ->values(),
        ]);
    }

    public function certificates(Request $request): Response
    {
        $student = $this->student($request);

        return Inertia::render('student/Certificates', [
            'certificates' => Certificate::query()
                ->where('user_id', $student->id)
                ->with('course:id,title')
                ->latest('issued_at')
                ->get()
                ->map(fn (Certificate $certificate) => [
                    'id' => $certificate->id,
                    'number' => $certificate->number,
                    'title' => $certificate->title,
                    'course' => $certificate->course?->title,
                    'issuedAt' => $certificate->issued_at->format('j M Y'),
                    'grade' => $certificate->grade,
                    'percent' => $certificate->final_percent ? (float) $certificate->final_percent : null,
                    'verificationUrl' => $certificate->verificationUrl(),
                    'code' => $certificate->verification_code,
                    'valid' => $certificate->isValid(),
                ]),

            'internshipDocuments' => InternshipDocument::query()
                ->where('user_id', $student->id)
                ->with('course:id,title')
                ->latest('issued_at')
                ->get()
                ->map(fn (InternshipDocument $document) => [
                    'id' => $document->id,
                    'kind' => $document->kind,
                    'kindLabel' => $document->kindLabel(),
                    'number' => $document->number,
                    'course' => $document->course?->title,
                    'issuedAt' => $document->issued_at->format('j M Y'),
                    'verificationUrl' => $document->verificationUrl(),
                    'code' => $document->verification_code,
                ]),
        ]);
    }

    public function downloadCertificate(Request $request, int $certificate, Credentials $credentials): StreamedResponse
    {
        /** @var Certificate $record */
        $record = Certificate::query()
            ->where('user_id', $this->student($request)->id)
            ->findOrFail($certificate);

        $path = $credentials->renderCertificate($record);

        return Storage::disk('private')->download(
            $path,
            str($record->number)->replace('/', '-')->toString().'.pdf',
        );
    }

    public function downloadInternshipDocument(Request $request, int $document, Credentials $credentials): StreamedResponse
    {
        /** @var InternshipDocument $record */
        $record = InternshipDocument::query()
            ->where('user_id', $this->student($request)->id)
            ->findOrFail($document);

        $path = $credentials->renderInternshipDocument($record);

        return Storage::disk('private')->download(
            $path,
            str($record->number)->replace('/', '-')->toString().'.pdf',
        );
    }

    public function announcements(Request $request): Response
    {
        return Inertia::render('student/Announcements', [
            'announcements' => Announcement::query()
                ->for($this->enrolledBatchIds($request), $this->enrolledCourseIds($request))
                ->with(['batch:id,name', 'course:id,title', 'author:id,name'])
                ->orderByDesc('is_pinned')
                ->orderByDesc('published_at')
                ->take(60)
                ->get()
                ->map(fn (Announcement $announcement) => [
                    'id' => $announcement->id,
                    'title' => $announcement->title,
                    'body' => $announcement->body,
                    'scope' => $announcement->batch?->name ?? $announcement->course?->title ?? 'Everyone',
                    'author' => $announcement->author?->name ?? 'Unboundbyte',
                    'pinned' => $announcement->is_pinned,
                    'at' => $announcement->published_at->format('j M Y'),
                    'ago' => $announcement->published_at->diffForHumans(),
                ]),
        ]);
    }

    /**
     * The student's own warnings.
     *
     * Private to them. The trainer's note is not included: a private note is
     * for the people running the course, and showing it here would make it not
     * a private note.
     */
    public function warnings(Request $request): Response
    {
        $warnings = StudentWarning::query()
            ->where('user_id', $this->student($request)->id)
            ->with('batch:id,name')
            ->latest('id')
            ->get();

        return Inertia::render('student/Warnings', [
            'warnings' => $warnings->map(fn (StudentWarning $warning) => [
                'id' => $warning->id,
                'level' => $warning->level,
                'levelLabel' => $warning->levelLabel(),
                'reason' => $warning->reason,
                'batch' => $warning->batch?->name,
                'at' => $warning->created_at->format('j M Y'),
                'acknowledged' => $warning->acknowledged_at !== null,
                'resolved' => $warning->resolved_at !== null,
            ]),
            'unacknowledged' => $warnings->whereNull('acknowledged_at')->whereNull('resolved_at')->count(),
        ]);
    }

    public function acknowledgeWarning(Request $request, int $warning): RedirectResponse
    {
        $record = StudentWarning::query()
            ->where('user_id', $this->student($request)->id)
            ->findOrFail($warning);

        if ($record->acknowledged_at === null) {
            $record->forceFill(['acknowledged_at' => now()])->save();
        }

        return back()->with('success', 'Noted. Thank you for reading it.');
    }
}
