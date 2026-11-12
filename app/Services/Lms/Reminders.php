<?php

namespace App\Services\Lms;

use App\Models\Assignment;
use App\Models\Attendance;
use App\Models\Enrollment;
use App\Models\LiveSession;
use App\Models\Quiz;
use App\Models\User;
use App\Notifications\LearningReminder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Who is reminded of what, and the guarantee that it happens once.
 *
 * Run every five minutes from the scheduler (`lms:remind`), plus once when a
 * register is marked for the missed-classes reminder. Every reminder is
 * claimed in `reminders_sent` before it is sent, so two overlapping runs, or a
 * run repeated after a failure, cannot send the same one twice.
 */
class Reminders
{
    /** Every kind a student can switch off, with how it is described to them. */
    public const KINDS = [
        'class_day' => ['A class tomorrow', 'The day before each live class on your batch.'],
        'class_hour' => ['A class in an hour', 'An hour before each live class starts.'],
        'deadline' => ['Work closing soon', 'A day before an assignment is due or a quiz closes, if you have not done it yet.'],
        'missed' => ['Missed classes', 'When you have missed two classes in a row, with the recordings to catch up.'],
    ];

    /** @return array{class_day: int, class_hour: int, deadline: int} How many went out. */
    public function run(): array
    {
        return [
            ...$this->classes(),
            'deadline' => $this->deadlines(),
        ];
    }

    /** @return array{class_day: int, class_hour: int} */
    public function classes(): array
    {
        $sent = ['class_day' => 0, 'class_hour' => 0];

        $sessions = LiveSession::query()
            ->where('status', '!=', 'cancelled')
            ->whereBetween('scheduled_at', [now(), now()->addDay()])
            ->with('batch.course:id,title')
            ->get();

        foreach ($sessions as $session) {
            $minutes = now()->diffInMinutes($session->scheduled_at);

            // A class arranged at short notice gets the hour reminder only.
            $kind = match (true) {
                $minutes <= 60 => 'class_hour',
                $minutes >= 120 => 'class_day',
                default => null,
            };

            if (! $kind) {
                continue;
            }

            $when = $kind === 'class_hour'
                ? 'starts at '.$session->scheduled_at->format('g:i a')
                : 'is '.($session->scheduled_at->isTomorrow() ? 'tomorrow' : 'today').' at '.$session->scheduled_at->format('g:i a');

            foreach ($this->studentsOn($session->batch_id) as $student) {
                $sent[$kind] += (int) $this->once($student, $kind, $session, fn () => new LearningReminder(
                    $kind,
                    "{$session->title} {$when}",
                    [
                        "Your class **{$session->title}** ({$session->batch?->course?->title}) {$when}.",
                        $kind === 'class_hour' ? 'The join button opens shortly before it starts.' : 'It is on your Live classes page, with the link to join.',
                    ],
                    'Live classes',
                    '/student/classes',
                ));
            }
        }

        return $sent;
    }

    public function deadlines(): int
    {
        $sent = 0;

        $assignments = Assignment::query()
            ->published()
            ->whereBetween('due_at', [now(), now()->addDay()])
            ->with('course:id,title')
            ->get();

        foreach ($assignments as $assignment) {
            $done = $assignment->submissions()->whereNotNull('submitted_at')->pluck('user_id')->flip();

            foreach ($this->studentsOnCourse($assignment->course_id, $assignment->batch_id) as $student) {
                if ($done->has($student->id)) {
                    continue;
                }

                $sent += (int) $this->once($student, 'deadline', $assignment, fn () => new LearningReminder(
                    'deadline',
                    "Due {$this->relative($assignment->due_at)}: {$assignment->title}",
                    ["**{$assignment->title}** ({$assignment->course?->title}) is due {$this->relative($assignment->due_at)}, and you have not handed it in yet."],
                    'Open the assignment',
                    "/student/assignments/{$assignment->id}",
                ));
            }
        }

        $quizzes = Quiz::query()
            ->published()
            ->whereBetween('closes_at', [now(), now()->addDay()])
            ->where(fn ($query) => $query->whereNull('opens_at')->orWhere('opens_at', '<=', now()))
            ->with('course:id,title')
            ->get();

        foreach ($quizzes as $quiz) {
            foreach ($this->studentsOnCourse($quiz->course_id, $quiz->batch_id) as $student) {
                if ($quiz->attemptsUsedBy($student) > 0) {
                    continue;
                }

                $sent += (int) $this->once($student, 'deadline', $quiz, fn () => new LearningReminder(
                    'deadline',
                    "Closes {$this->relative($quiz->closes_at)}: {$quiz->title}",
                    ["The quiz **{$quiz->title}** ({$quiz->course?->title}) closes {$this->relative($quiz->closes_at)}, and you have not sat it yet."],
                    'Open the quiz',
                    "/student/quizzes/{$quiz->id}",
                ));
            }
        }

        return $sent;
    }

    /**
     * After a register is marked: anybody absent from this class and from the
     * batch's previous class hears about it, and so does the trainer, who
     * decides whether it needs a notice on the warnings ladder. Nothing here
     * issues a warning itself.
     */
    public function afterRegister(LiveSession $session): int
    {
        $previous = LiveSession::query()
            ->where('batch_id', $session->batch_id)
            ->where('id', '!=', $session->id)
            ->where('status', '!=', 'cancelled')
            ->where('scheduled_at', '<', $session->scheduled_at)
            ->whereHas('attendances')
            ->latest('scheduled_at')
            ->first();

        if (! $previous) {
            return 0;
        }

        $absentBoth = Attendance::query()
            ->where('live_session_id', $session->id)
            ->where('status', 'absent')
            ->whereIn('user_id', Attendance::query()
                ->where('live_session_id', $previous->id)
                ->where('status', 'absent')
                ->select('user_id'))
            ->with('user')
            ->get()
            ->pluck('user')
            ->filter();

        $session->loadMissing('batch.trainer');
        $sent = 0;

        foreach ($absentBoth as $student) {
            $sent += (int) $this->once($student, 'missed', $session, fn () => new LearningReminder(
                'missed',
                'You have missed your last two classes',
                [
                    "You were not at **{$previous->title}** or **{$session->title}**.",
                    'The recordings are on your Live classes page as soon as they are up. If something is getting in the way, tell your trainer; that is what they are there for.',
                ],
                'Catch up',
                '/student/classes',
            ));

            $trainer = $session->batch?->trainer;

            if ($trainer) {
                $this->once($trainer, "missed:{$student->id}", $session, fn () => new LearningReminder(
                    'missed_staff',
                    "{$student->name} has missed two classes in a row",
                    ["{$student->name} was absent from **{$previous->title}** and **{$session->title}** on {$session->batch->name}.", 'Whether it needs a notice on the warnings ladder is your call.'],
                    'Open their record',
                    "/admin/students/{$student->id}",
                ), respectPreferences: false);
            }
        }

        return $sent;
    }

    /* -------------------------------------------------------------------- */

    /**
     * Claim the reminder, then send it. A claim that already exists means it
     * went before (or another run has it), so nothing is sent.
     */
    protected function once(User $user, string $kind, Model $subject, callable $notification, bool $respectPreferences = true): bool
    {
        $preference = str_contains($kind, ':') ? null : $kind;

        if ($respectPreferences && $preference && ! $user->wantsReminder($preference)) {
            return false;
        }

        try {
            DB::table('reminders_sent')->insert([
                'user_id' => $user->id,
                'kind' => $kind,
                'subject_type' => $subject::class,
                'subject_id' => $subject->getKey(),
                'sent_at' => now(),
            ]);
        } catch (QueryException) {
            return false;
        }

        $user->notify($notification());

        return true;
    }

    /** @return Collection<int, User> */
    protected function studentsOn(?int $batchId): Collection
    {
        if (! $batchId) {
            return collect();
        }

        return User::query()
            ->whereIn('id', Enrollment::query()->where('batch_id', $batchId)->active()->select('user_id'))
            ->get();
    }

    /** @return Collection<int, User> */
    protected function studentsOnCourse(int $courseId, ?int $batchId): Collection
    {
        return User::query()
            ->whereIn('id', Enrollment::query()
                ->where('course_id', $courseId)
                ->when($batchId, fn ($query) => $query->where('batch_id', $batchId))
                ->active()
                ->select('user_id'))
            ->get();
    }

    protected function relative(\DateTimeInterface $at): string
    {
        $at = Carbon::instance($at);

        return ($at->isToday() ? 'today' : 'tomorrow').' at '.$at->format('g:i a');
    }
}
