<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Batch;
use App\Models\College;
use App\Models\Enrollment;
use App\Models\LiveSession;
use App\Models\Setting;
use App\Models\Submission;
use App\Models\User;
use App\Services\Admin\AccountCreator;
use App\Services\Admin\Auditor;
use App\Services\Lms\Enroller;
use App\Services\Lms\ResultCard;
use App\Support\Identifier;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * A college's own view: their students, and nothing else.
 *
 * This is what a coordinator needs to answer their department without emailing
 * us, and what we need in order to spot a student who has quietly stopped
 * turning up while it can still be fixed.
 */
class CollegeDeskController extends Controller
{
    public function show(Request $request, College $college, ResultCard $card): Response
    {
        $students = User::query()
            ->role(Role::Student)
            ->whereHas('profile', fn ($query) => $query->where('college_id', $college->id))
            ->with('profile')
            ->orderBy('name')
            ->get();

        $enrolments = Enrollment::query()
            ->whereIn('user_id', $students->pluck('id'))
            ->with(['course:id,title,type,minimum_attendance', 'batch:id,name,starts_on,ends_on'])
            ->get()
            ->groupBy('user_id');

        return Inertia::render('admin/colleges/Desk', [
            'college' => [
                'id' => $college->id,
                'slug' => $college->slug,
                'name' => $college->name,
                'city' => $college->city,
                'university' => $college->university,
                'coordinator' => $college->coordinator_name,
                'coordinatorEmail' => $college->coordinator_email,
                'mouEndsOn' => $college->mou_expires_on?->format('j M Y'),
                'mouExpiring' => $college->mouIsExpiring(),
                'mouExpired' => $college->mouHasExpired(),
            ],

            'students' => $students->map(function (User $student) use ($enrolments, $card) {
                $rows = $enrolments[$student->id] ?? collect();
                $primary = $rows->first();

                $result = $primary ? $card->for($primary) : null;

                return [
                    'id' => $student->id,
                    'name' => $student->name,
                    'email' => $student->email,
                    'enrollmentNumber' => $student->profile?->enrollment_number,
                    'courseOfStudy' => $student->profile?->course_of_study,
                    'semester' => $student->profile?->current_semester,
                    'enrolments' => $rows->count(),
                    'course' => $primary?->course?->title,
                    'batch' => $primary?->batch?->name,
                    'progress' => $primary?->progress_percent,
                    'attendance' => $result['attendance']['percent'] ?? null,
                    'overall' => $result['overall'] ?? null,
                    'passing' => $result['passing'] ?? null,
                    'atRisk' => $this->atRisk($result),
                    'lastSeen' => ($seen = $student->activity()->max('occurred_at'))
                        ? Carbon::parse($seen)->diffForHumans()
                        : null,
                ];
            }),

            'batches' => Batch::query()
                ->where('college_id', $college->id)
                ->orWhereHas('enrollments.user.profile', fn ($query) => $query->where('college_id', $college->id))
                ->with('course:id,title')
                ->distinct()
                ->get()
                ->map(fn (Batch $batch) => [
                    'value' => $batch->id,
                    'label' => $batch->name.' — '.$batch->course?->title,
                ]),
        ]);
    }

    /**
     * Enrol a whole list at once.
     *
     * Creating the accounts and enrolling them are one action because that is
     * how a coordinator thinks about it: here is the list, put them on the
     * batch. Anybody already on our books is matched by email rather than
     * duplicated.
     */
    public function bulkEnrol(
        Request $request,
        College $college,
        AccountCreator $creator,
        Enroller $enroller,
        Auditor $auditor,
    ): RedirectResponse {
        $validated = $this->validatedInput($request, [
            'batch_id' => ['required', 'integer', Rule::exists('batches', 'id')],
            'waive_fee' => ['boolean'],
            'students' => ['required', 'array', 'min:1', 'max:200'],
            'students.*.name' => ['required', 'string', 'max:120'],
            'students.*.email' => ['required', 'email', 'max:255'],
            'students.*.mobile' => ['nullable', 'string', 'max:20'],
            'students.*.enrollment_number' => ['nullable', 'string', 'max:60'],
            'students.*.course_of_study' => ['nullable', 'string', 'max:120'],
        ]);

        $batch = Batch::query()->with('course')->findOrFail($validated['batch_id']);

        $created = 0;
        $existing = 0;
        $failed = [];

        foreach ($validated['students'] as $row) {
            $email = mb_strtolower(trim($row['email']));

            try {
                DB::transaction(function () use (
                    $row, $email, $college, $batch, $creator, $enroller, $validated, &$created, &$existing
                ) {
                    $student = User::query()->where('email', $email)->first();

                    if ($student) {
                        $existing++;
                    } else {
                        $result = $creator->create([
                            'name' => $row['name'],
                            'email' => $email,
                            'mobile' => filled($row['mobile'] ?? null)
                                ? Identifier::normaliseMobile($row['mobile'])
                                : null,
                            'college_id' => $college->id,
                            'enrollment_number' => $row['enrollment_number'] ?? null,
                            'course_of_study' => $row['course_of_study'] ?? null,
                        ], Role::Student);

                        $student = $result['user'];
                        $created++;
                    }

                    // Keep the college on the profile even for an account we
                    // already had: that is how the coordinator view finds them.
                    $student->profile()->updateOrCreate([], array_filter([
                        'college_id' => $college->id,
                        'enrollment_number' => $row['enrollment_number'] ?? null,
                        'course_of_study' => $row['course_of_study'] ?? null,
                    ], fn ($value) => $value !== null));

                    $enroller->enrol(
                        $student,
                        $batch->course,
                        $batch,
                        source: 'college',
                        waiveFee: $validated['waive_fee'] ?? false,
                        notify: false,
                    );
                });
            } catch (\Throwable $e) {
                report($e);
                $failed[] = $email;
            }
        }

        $auditor->action('college.bulk_enrolled', $college, [
            'batch_id' => $batch->id,
            'created' => $created,
            'existing' => $existing,
            'failed' => count($failed),
        ], $college->name);

        $message = "{$created} new "
            .str('account')->plural($created)
            ." created, {$existing} already on our books, all put on {$batch->name}.";

        if ($failed !== []) {
            return back()
                ->with('warning', $message)
                ->withErrors(['students' => count($failed).' could not be added: '.implode(', ', $failed)]);
        }

        return back()->with('success', $message);
    }

    /**
     * A monthly report a department can file.
     *
     * Deliberately a PDF: a coordinator needs something to attach to an email
     * or print, and a link into our application is not that.
     */
    public function report(Request $request, College $college, ResultCard $card): HttpResponse
    {
        $month = $request->date('month') ?? now()->startOfMonth();
        $from = $month->copy()->startOfMonth();
        $to = $month->copy()->endOfMonth();

        $students = User::query()
            ->role(Role::Student)
            ->whereHas('profile', fn ($query) => $query->where('college_id', $college->id))
            ->with('profile')
            ->orderBy('name')
            ->get();

        $rows = $students->map(function (User $student) use ($card, $from, $to) {
            $enrolment = Enrollment::query()
                ->where('user_id', $student->id)
                ->with(['course', 'batch'])
                ->first();

            if (! $enrolment) {
                return null;
            }

            $result = $card->for($enrolment);

            $held = LiveSession::query()
                ->where('batch_id', $enrolment->batch_id)
                ->whereBetween('scheduled_at', [$from, $to])
                ->whereNot('status', 'cancelled')
                ->pluck('id');

            $present = Attendance::query()
                ->where('user_id', $student->id)
                ->whereIn('live_session_id', $held)
                ->counted()
                ->count();

            $submitted = Submission::query()
                ->where('user_id', $student->id)
                ->whereBetween('submitted_at', [$from, $to])
                ->count();

            return [
                'name' => $student->name,
                'enrollmentNumber' => $student->profile?->enrollment_number,
                'course' => $enrolment->course->title,
                'batch' => $enrolment->batch?->name,
                'monthHeld' => $held->count(),
                'monthAttended' => $present,
                'monthSubmissions' => $submitted,
                'progress' => $enrolment->progress_percent,
                'attendance' => $result['attendance']['percent'],
                'overall' => $result['overall'],
                'grade' => $result['grade'],
                'atRisk' => $this->atRisk($result),
            ];
        })->filter()->values();

        $pdf = Pdf::loadView('reports.college-monthly', [
            'college' => $college,
            'month' => $from,
            'rows' => $rows,
            'company' => [
                'name' => Setting::get('company.legal_name', config('company.legal_name')),
                'trading' => Setting::get('company.name', config('company.name')),
                'email' => Setting::get('company.email', config('company.email')),
            ],
        ])->setPaper('a4', 'landscape');

        return $pdf->download(
            str($college->slug)->append('-', $from->format('Y-m'), '.pdf')->toString(),
        );
    }

    /**
     * Whether somebody needs chasing.
     *
     * Early enough to fix rather than after the internship has failed, which is
     * the whole point of telling the coordinator at all.
     */
    protected function atRisk(?array $result): bool
    {
        if ($result === null) {
            return false;
        }

        /*
         * Marks only count once something has actually been marked. Early in a
         * course every student is below the pass mark, because the work that
         * would lift them is not due yet, and a flag that is on for everybody
         * is not a flag.
         */
        $assessed = ($result['quizzes']['taken'] ?? 0) > 0
            || collect($result['assignments']['rows'] ?? [])->contains(fn (array $row) => $row['marks'] !== null);

        // And a missing hand in only counts once the deadline has gone: work
        // that is not due yet is not late.
        $missedADeadline = collect($result['assignments']['rows'] ?? [])
            ->contains(fn (array $row) => $row['overdue']);

        return $result['shortOnAttendance']
            || $missedADeadline
            || ($assessed
                && $result['overall'] !== null
                && $result['overall'] < ($result['passMark'] ?? 50));
    }
}
