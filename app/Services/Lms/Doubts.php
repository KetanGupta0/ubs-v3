<?php

namespace App\Services\Lms;

use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\LessonDoubt;
use App\Models\User;
use App\Notifications\DoubtAnswered;
use App\Notifications\DoubtAsked;
use App\Services\Admin\Auditor;
use Illuminate\Support\Facades\DB;

/**
 * Questions on lessons, and the answers to them.
 *
 * A student's question goes to their batch's trainer; an answer from the team
 * goes to whoever asked. Both are in the app and by email. The thread is
 * readable by the asker's whole batch, which is the point of it.
 */
class Doubts
{
    public function __construct(protected Activity $activity, protected Auditor $auditor) {}

    public function ask(User $student, Enrollment $enrolment, Lesson $lesson, string $body): LessonDoubt
    {
        $doubt = LessonDoubt::query()->create([
            'lesson_id' => $lesson->id,
            'course_id' => $lesson->course_id,
            'batch_id' => $enrolment->batch_id,
            'user_id' => $student->id,
            'body' => trim($body),
            'status' => 'open',
            'last_activity_at' => now(),
        ]);

        $this->activity->record($student, 'doubt.asked', $lesson, ['title' => $lesson->title], $lesson->course_id, $enrolment->batch_id);

        $trainer = $enrolment->batch?->trainer;

        if ($trainer && $trainer->id !== $student->id) {
            $trainer->notify(new DoubtAsked($doubt));
        }

        return $doubt;
    }

    /** The asker adds to their own question, which puts it back in the queue. */
    public function follow(LessonDoubt $doubt, User $student, string $body): void
    {
        DB::transaction(function () use ($doubt, $student, $body) {
            $doubt->replies()->create(['user_id' => $student->id, 'body' => trim($body), 'from_staff' => false]);
            $doubt->forceFill(['status' => 'open', 'last_activity_at' => now()])->save();
        });

        $trainer = $doubt->batch?->trainer;

        if ($trainer) {
            $trainer->notify(new DoubtAsked($doubt, followUp: true));
        }
    }

    public function answer(LessonDoubt $doubt, User $staff, string $body): void
    {
        DB::transaction(function () use ($doubt, $staff, $body) {
            $doubt->replies()->create(['user_id' => $staff->id, 'body' => trim($body), 'from_staff' => true]);
            $doubt->forceFill(['status' => 'answered', 'answered_at' => now(), 'last_activity_at' => now()])->save();
        });

        $doubt->asker?->notify(new DoubtAnswered($doubt));
    }

    /** Take a question down, or put it back. The thread is kept either way. */
    public function setHidden(LessonDoubt $doubt, User $staff, bool $hidden): void
    {
        $doubt->status = $hidden
            ? 'hidden'
            : ($doubt->replies()->where('from_staff', true)->exists() ? 'answered' : 'open');

        $this->auditor->actingAs($staff)->updated($doubt, label: 'Question on '.$doubt->lesson->title);
        $doubt->save();
    }
}
