<?php

namespace App\Http\Controllers\Student;

use App\Models\Attendance;
use App\Models\Enrollment;
use App\Models\LiveSession;
use App\Services\Lms\Activity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Live classes and attendance.
 *
 * Joining is recorded, but the register is the trainer's. A student marking
 * themselves present by opening a link is an attendance figure that means
 * nothing, so this records that they clicked and leaves the mark to a person.
 */
class ClassController extends StudentController
{
    public function index(Request $request): Response
    {
        $student = $this->student($request);
        $batchIds = $this->enrolledBatchIds($request);

        $upcoming = LiveSession::query()
            ->whereIn('batch_id', $batchIds)
            ->upcoming()
            ->with('batch.course:id,title')
            ->take(20)
            ->get();

        $past = LiveSession::query()
            ->whereIn('batch_id', $batchIds)
            ->past()
            ->with('batch.course:id,title')
            ->take(40)
            ->get();

        $attendance = Attendance::query()
            ->where('user_id', $student->id)
            ->whereIn('live_session_id', $past->pluck('id'))
            ->get()
            ->keyBy('live_session_id');

        return Inertia::render('student/classes/Index', [
            'upcoming' => $upcoming->map(fn (LiveSession $session) => [
                'id' => $session->id,
                'title' => $session->title,
                'agenda' => $session->agenda,
                'course' => $session->batch?->course?->title,
                'batch' => $session->batch?->name,
                'at' => $session->scheduled_at->format('D j M Y, g:i a'),
                'startsIn' => $session->scheduled_at->diffForHumans(),
                'duration' => $session->duration_minutes,
                'joinable' => $session->isJoinable(),
                'live' => $session->isLive(),
                'cancelled' => $session->status === 'cancelled',
            ]),

            'past' => $past->map(function (LiveSession $session) use ($attendance) {
                $record = $attendance[$session->id] ?? null;

                return [
                    'id' => $session->id,
                    'title' => $session->title,
                    'course' => $session->batch?->course?->title,
                    'at' => $session->scheduled_at->format('j M Y'),
                    'status' => $record?->status ?? 'not marked',
                    'attended' => (bool) $record?->counts(),
                    'recordingUrl' => $session->recording_url,
                ];
            }),

            'attendance' => $this->summary($request),
        ]);
    }

    /**
     * Open the room.
     *
     * Redirects rather than rendering the link, so the click is recorded and
     * the link is not sitting in the page source to be shared outside the
     * cohort.
     */
    public function join(Request $request, int $session, Activity $activity): RedirectResponse
    {
        $record = LiveSession::query()
            ->whereIn('batch_id', $this->enrolledBatchIds($request))
            ->findOrFail($session);

        abort_unless($record->isJoinable(), 403, 'That class is not open yet.');

        $activity->record($this->student($request), 'session.joined', $record, [
            'title' => $record->title,
        ], $record->batch?->course_id, $record->batch_id);

        return redirect()->away($record->link());
    }

    public function attendance(Request $request): Response
    {
        return Inertia::render('student/classes/Attendance', [
            'summary' => $this->summary($request),
        ]);
    }

    /**
     * Attendance per course, with the classes that were missed.
     *
     * A percentage alone cannot answer "which ones did I miss", which is the
     * only question anybody actually asks about attendance.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function summary(Request $request): array
    {
        $student = $this->student($request);

        return Enrollment::query()
            ->forStudent($student)
            ->whereNotNull('batch_id')
            ->with(['course:id,title,minimum_attendance', 'batch:id,name'])
            ->get()
            ->map(function (Enrollment $enrolment) use ($student) {
                $held = LiveSession::query()
                    ->where('batch_id', $enrolment->batch_id)
                    ->where('scheduled_at', '<', now())
                    ->whereNot('status', 'cancelled')
                    ->orderBy('scheduled_at')
                    ->get();

                $records = Attendance::query()
                    ->where('user_id', $student->id)
                    ->whereIn('live_session_id', $held->pluck('id'))
                    ->get()
                    ->keyBy('live_session_id');

                $attended = $held->filter(fn (LiveSession $s) => ($records[$s->id] ?? null)?->counts() ?? false);
                $percent = $held->isEmpty() ? null : round($attended->count() / $held->count() * 100, 1);
                $floor = $enrolment->course->minimum_attendance ?? 0;

                return [
                    'courseId' => $enrolment->course_id,
                    'course' => $enrolment->course->title,
                    'batch' => $enrolment->batch?->name,
                    'held' => $held->count(),
                    'attended' => $attended->count(),
                    'percent' => $percent,
                    'minimum' => $floor,
                    'short' => $percent !== null && $floor > 0 && $percent < $floor,
                    'missed' => $held
                        ->reject(fn (LiveSession $s) => ($records[$s->id] ?? null)?->counts() ?? false)
                        ->map(fn (LiveSession $s) => [
                            'id' => $s->id,
                            'title' => $s->title,
                            'on' => $s->scheduled_at->format('j M Y'),
                            'recordingUrl' => $s->recording_url,
                        ])
                        ->values(),
                ];
            })
            ->values()
            ->all();
    }
}
