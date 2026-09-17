<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\Attendance;
use App\Models\Batch;
use App\Models\Enrollment;
use App\Models\LiveSession;
use App\Models\StudentWarning;
use App\Models\User;
use App\Services\Admin\Auditor;
use App\Services\Lms\Activity;
use App\Services\Lms\Enroller;
use App\Services\Lms\Warnings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Running a batch: the register, the schedule, who is on it.
 *
 * Attendance is marked here, and marking somebody present awards the points and
 * writes the activity entry in the same action, so the three can never disagree
 * about whether a student turned up.
 */
class BatchRunController extends Controller
{
    public function show(Request $request, Batch $batch): Response
    {
        $batch->load(['course:id,title,type,minimum_attendance', 'trainer:id,name', 'college:id,name']);

        $enrolments = $batch->enrollments()
            ->with('user:id,name,email,mobile')
            ->get();

        $studentIds = $enrolments->pluck('user_id');

        $held = LiveSession::query()
            ->where('batch_id', $batch->id)
            ->where('scheduled_at', '<', now())
            ->whereNot('status', 'cancelled')
            ->pluck('id');

        $attended = Attendance::query()
            ->whereIn('live_session_id', $held)
            ->whereIn('user_id', $studentIds)
            ->counted()
            ->selectRaw('user_id, count(*) as total')
            ->groupBy('user_id')
            ->pluck('total', 'user_id');

        $warnings = StudentWarning::query()
            ->where('batch_id', $batch->id)
            ->unresolved()
            ->selectRaw('user_id, count(*) as total')
            ->groupBy('user_id')
            ->pluck('total', 'user_id');

        return Inertia::render('admin/batches/Run', [
            'batch' => [
                'id' => $batch->id,
                'name' => $batch->name,
                'code' => $batch->code,
                'course' => $batch->course?->title,
                'courseId' => $batch->course_id,
                'type' => $batch->course?->type,
                'trainer' => $batch->trainer?->name,
                'college' => $batch->college?->name,
                'startsOn' => $batch->starts_on?->format('j M Y'),
                'endsOn' => $batch->ends_on?->format('j M Y'),
                'status' => $batch->status,
                'capacity' => $batch->capacity,
                'week' => $batch->weekNumber(),
                'meetLink' => $batch->meet_link,
                'minimumAttendance' => $batch->course?->minimum_attendance,
            ],

            'students' => $enrolments->map(function (Enrollment $enrolment) use ($held, $attended, $warnings) {
                $present = (int) ($attended[$enrolment->user_id] ?? 0);

                return [
                    'enrolmentId' => $enrolment->id,
                    'userId' => $enrolment->user_id,
                    'name' => $enrolment->user->name,
                    'email' => $enrolment->user->email,
                    'status' => $enrolment->status,
                    'progress' => $enrolment->progress_percent,
                    'hasPaid' => $enrolment->has_paid,
                    'attended' => $present,
                    'held' => $held->count(),
                    'attendance' => $held->isEmpty() ? null : (int) round($present / $held->count() * 100),
                    'warnings' => (int) ($warnings[$enrolment->user_id] ?? 0),
                ];
            })->sortBy('name')->values(),

            'sessions' => $batch->sessions()
                ->withCount(['attendances as present_count' => fn ($query) => $query->counted()])
                ->get()
                ->map(fn (LiveSession $session) => [
                    'id' => $session->id,
                    'title' => $session->title,
                    'agenda' => $session->agenda,
                    'at' => $session->scheduled_at->format('D j M Y, g:i a'),
                    'atValue' => $session->scheduled_at->format('Y-m-d\TH:i'),
                    'duration' => $session->duration_minutes,
                    'status' => $session->status,
                    'past' => $session->scheduled_at->isPast(),
                    'live' => $session->isLive(),
                    'present' => $session->present_count,
                    'meetLink' => $session->meet_link,
                    'recordingUrl' => $session->recording_url,
                    'marked' => $session->attendances()->exists(),
                ]),

            'announcements' => $batch->announcements()
                ->with('author:id,name')
                ->latest('id')
                ->take(20)
                ->get()
                ->map(fn (Announcement $announcement) => [
                    'id' => $announcement->id,
                    'title' => $announcement->title,
                    'body' => $announcement->body,
                    'pinned' => $announcement->is_pinned,
                    'author' => $announcement->author?->name,
                    'publishedAt' => $announcement->published_at?->format('j M Y'),
                ]),

            'candidates' => User::query()
                ->role(Role::Student)
                ->active()
                ->whereNotIn('id', $studentIds)
                ->orderBy('name')
                ->get(['id', 'name', 'email'])
                ->map(fn (User $user) => [
                    'value' => $user->id,
                    'label' => $user->name,
                    'description' => $user->email,
                ]),

            'trainers' => User::query()
                ->role(Role::Admin)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (User $user) => ['value' => $user->id, 'label' => $user->name]),
        ]);
    }

    /* --------------------------------------------------------- sessions */

    public function storeSession(Request $request, Batch $batch): RedirectResponse
    {
        $batch->sessions()->create($this->sessionRules($request) + [
            'trainer_id' => $batch->trainer_id,
        ]);

        return back()->with('success', 'Class scheduled.');
    }

    public function updateSession(Request $request, Batch $batch, LiveSession $session): RedirectResponse
    {
        abort_unless($session->batch_id === $batch->id, 404);

        $session->fill($this->sessionRules($request))->save();

        return back()->with('success', 'Class updated.');
    }

    public function destroySession(Batch $batch, LiveSession $session): RedirectResponse
    {
        abort_unless($session->batch_id === $batch->id, 404);

        // Cancelled rather than deleted where anybody was marked: an attendance
        // record with no class attached is worse than a cancelled class.
        if ($session->attendances()->exists()) {
            $session->forceFill(['status' => 'cancelled'])->save();

            return back()->with('success', 'Marked cancelled. The register is kept.');
        }

        $session->delete();

        return back()->with('success', 'Class removed.');
    }

    /* ------------------------------------------------------- attendance */

    public function register(Request $request, Batch $batch, LiveSession $session): Response
    {
        abort_unless($session->batch_id === $batch->id, 404);

        $enrolments = $batch->enrollments()->active()->with('user:id,name,email')->get();

        $marks = Attendance::query()
            ->where('live_session_id', $session->id)
            ->get()
            ->keyBy('user_id');

        return Inertia::render('admin/batches/Register', [
            'batch' => ['id' => $batch->id, 'name' => $batch->name, 'course' => $batch->course?->title],
            'session' => [
                'id' => $session->id,
                'title' => $session->title,
                'at' => $session->scheduled_at->format('D j M Y, g:i a'),
                'duration' => $session->duration_minutes,
                'recordingUrl' => $session->recording_url,
            ],
            'rows' => $enrolments->map(fn (Enrollment $enrolment) => [
                'userId' => $enrolment->user_id,
                'name' => $enrolment->user->name,
                'email' => $enrolment->user->email,
                'status' => $marks[$enrolment->user_id]->status ?? 'absent',
                'note' => $marks[$enrolment->user_id]->note ?? null,
                'nextWarningLevel' => StudentWarning::nextLevelFor($enrolment->user_id, $batch->id),
            ])->sortBy('name')->values(),
            'statuses' => Attendance::STATUSES,
        ]);
    }

    public function mark(Request $request, Batch $batch, LiveSession $session, Activity $activity): RedirectResponse
    {
        abort_unless($session->batch_id === $batch->id, 404);

        $validated = $this->validatedInput($request, [
            'marks' => ['required', 'array'],
            'marks.*.user_id' => ['required', 'integer'],
            'marks.*.status' => ['required', Rule::in(Attendance::STATUSES)],
            'marks.*.note' => ['nullable', 'string', 'max:200'],
        ]);

        $onBatch = $batch->enrollments()->pluck('user_id')->flip();

        foreach ($validated['marks'] as $mark) {
            if (! $onBatch->has($mark['user_id'])) {
                continue;
            }

            $attendance = Attendance::query()->updateOrCreate(
                ['live_session_id' => $session->id, 'user_id' => $mark['user_id']],
                [
                    'status' => $mark['status'],
                    'note' => $mark['note'] ?? null,
                    'marked_by' => $request->user()->id,
                    'marked_at' => now(),
                ],
            );

            // Points and the activity entry go with the mark, so the register,
            // the leaderboard and the student's own history cannot disagree.
            if (in_array($mark['status'], ['present', 'late'], true)) {
                $activity->did(
                    $attendance->user,
                    'session.attended',
                    $session,
                    ['title' => $session->title],
                    $batch->course_id,
                    $batch->id,
                );
            }
        }

        $session->forceFill(['status' => 'held'])->save();

        return back()->with('success', 'Register saved.');
    }

    /* --------------------------------------------------------- warnings */

    public function warn(Request $request, Batch $batch, Warnings $warnings, Auditor $auditor): RedirectResponse
    {
        $validated = $this->validatedInput($request, [
            'user_id' => ['required', 'integer'],
            'reason' => ['required', 'string', 'max:500'],
            'level' => ['nullable', Rule::in(StudentWarning::LEVELS)],
            'live_session_id' => ['nullable', 'integer'],
            'private_note' => ['nullable', 'string', 'max:1000'],
        ]);

        abort_unless($batch->enrollments()->where('user_id', $validated['user_id'])->exists(), 404);

        $student = User::query()->findOrFail($validated['user_id']);

        $warning = $warnings->issue(
            student: $student,
            reason: $validated['reason'],
            batch: $batch,
            session: $validated['live_session_id']
                ? LiveSession::query()->where('batch_id', $batch->id)->find($validated['live_session_id'])
                : null,
            issuedBy: $request->user(),
            level: $validated['level'],
            privateNote: $validated['private_note'],
        );

        $auditor->action('student.warned', $warning, [
            'level' => $warning->level,
            'user_id' => $student->id,
        ], $student->name);

        return back()->with('success', match ($warning->level) {
            'notice' => 'Noted, and they have been told privately.',
            'warning' => 'Formal warning sent.',
            default => 'Escalated. The guardian on file has been contacted.',
        });
    }

    public function warnings(Request $request, Batch $batch): Response
    {
        return Inertia::render('admin/batches/Warnings', [
            'batch' => ['id' => $batch->id, 'name' => $batch->name, 'course' => $batch->course?->title],
            'warnings' => StudentWarning::query()
                ->where('batch_id', $batch->id)
                ->with(['user:id,name', 'issuer:id,name', 'session:id,title'])
                ->latest('id')
                ->get()
                ->map(fn (StudentWarning $warning) => [
                    'id' => $warning->id,
                    'student' => $warning->user->name,
                    'userId' => $warning->user_id,
                    'level' => $warning->level,
                    'levelLabel' => $warning->levelLabel(),
                    'reason' => $warning->reason,
                    'privateNote' => $warning->private_note,
                    'session' => $warning->session?->title,
                    'issuedBy' => $warning->issuer?->name,
                    'at' => $warning->created_at->format('j M Y'),
                    'acknowledged' => $warning->acknowledged_at !== null,
                    'guardianNotified' => $warning->guardian_notified_at !== null,
                    'resolved' => $warning->resolved_at !== null,
                ]),
        ]);
    }

    public function resolveWarning(Batch $batch, StudentWarning $warning, Warnings $warnings): RedirectResponse
    {
        abort_unless($warning->batch_id === $batch->id, 404);

        $warnings->resolve($warning);

        return back()->with('success', 'Closed out.');
    }

    /* ------------------------------------------------------- enrolments */

    public function enrol(Request $request, Batch $batch, Enroller $enroller): RedirectResponse
    {
        $validated = $this->validatedInput($request, [
            'user_ids' => ['required', 'array'],
            'user_ids.*' => ['integer', Rule::exists('users', 'id')->where('role', Role::Student->value)],
            'waive_fee' => ['boolean'],
            'notify' => ['boolean'],
        ]);

        $students = User::query()->whereIn('id', $validated['user_ids'])->get();

        foreach ($students as $student) {
            $enroller->enrol(
                $student,
                $batch->course,
                $batch,
                source: 'admin',
                waiveFee: $validated['waive_fee'] ?? false,
                notify: $this->boolInput($request, 'notify', true),
            );
        }

        return back()->with('success', $students->count().' '
            .str('student')->plural($students->count()).' added to the batch.');
    }

    public function updateEnrolment(Request $request, Batch $batch, Enrollment $enrolment, Enroller $enroller): RedirectResponse
    {
        abort_unless($enrolment->batch_id === $batch->id, 404);

        $validated = $this->validatedInput($request, [
            'status' => ['sometimes', Rule::in(Enrollment::STATUSES)],
            'has_paid' => ['sometimes', 'boolean'],
        ]);

        if (($validated['status'] ?? null) === 'dropped') {
            $enroller->drop($enrolment);

            return back()->with('success', 'Removed from the batch.');
        }

        $enrolment->fill(array_filter($validated, fn ($value) => $value !== null))->save();
        $batch->recountSeats();

        return back()->with('success', 'Enrolment updated.');
    }

    /* ----------------------------------------------------- announcements */

    public function announce(Request $request, Batch $batch): RedirectResponse
    {
        $validated = $this->validatedInput($request, [
            'title' => ['required', 'string', 'max:160'],
            'body' => ['required', 'string', 'max:5000'],
            'is_pinned' => ['boolean'],
            'course_wide' => ['boolean'],
        ]);

        Announcement::query()->create([
            'course_id' => $batch->course_id,
            // Course wide reaches every batch of the course, which is the point
            // of having the distinction at all.
            'batch_id' => ($validated['course_wide'] ?? false) ? null : $batch->id,
            'author_id' => $request->user()->id,
            'title' => $validated['title'],
            'body' => $validated['body'],
            'audience' => ($validated['course_wide'] ?? false) ? 'course' : 'batch',
            'is_pinned' => $validated['is_pinned'] ?? false,
            'published_at' => now(),
        ]);

        return back()->with('success', 'Posted.');
    }

    public function destroyAnnouncement(Batch $batch, Announcement $announcement): RedirectResponse
    {
        abort_unless(
            $announcement->batch_id === $batch->id || $announcement->course_id === $batch->course_id,
            404,
        );

        $announcement->delete();

        return back()->with('success', 'Removed.');
    }

    /** @return array<string, mixed> */
    protected function sessionRules(Request $request): array
    {
        return $this->validatedInput($request, [
            'title' => ['required', 'string', 'max:160'],
            'agenda' => ['nullable', 'string', 'max:2000'],
            'scheduled_at' => ['required', 'date'],
            'duration_minutes' => ['required', 'integer', 'min:15', 'max:600'],
            'meet_link' => ['nullable', 'url', 'max:255'],
            'recording_url' => ['nullable', 'url', 'max:255'],
            'lesson_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(['scheduled', 'held', 'cancelled'])],
        ]);
    }
}
