<?php

namespace App\Http\Controllers\Student;

use App\Models\Lesson;
use App\Models\LessonDoubt;
use App\Services\Lms\ContentGate;
use App\Services\Lms\Doubts;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Asking a question on a lesson, and following one up.
 *
 * Only on a lesson the gate has opened, and only into the student's own
 * batch's thread; a follow-up only on a question they asked themselves.
 */
class DoubtController extends StudentController
{
    public function store(Request $request, int $course, int $lesson, Doubts $doubts): RedirectResponse
    {
        $validated = $request->validate([
            'body' => ['required', 'string', 'min:5', 'max:'.LessonDoubt::MAX_LENGTH],
        ], ['body.min' => 'Say a little more, so it can be answered.']);

        $enrolment = $this->enrolment($request, $course, activeOnly: true);
        $record = Lesson::query()->where('course_id', $course)->where('is_published', true)->findOrFail($lesson);
        abort_unless(ContentGate::for($this->student($request), $enrolment)->isOpen($record), 403);

        $doubts->ask($this->student($request), $enrolment, $record, $validated['body']);

        return back()->with('success', 'Asked. Your trainer has been told, and the answer will show here for your whole batch.');
    }

    public function reply(Request $request, int $course, int $lesson, int $doubt, Doubts $doubts): RedirectResponse
    {
        $validated = $request->validate([
            'body' => ['required', 'string', 'max:'.LessonDoubt::MAX_LENGTH],
        ]);

        $this->enrolment($request, $course, activeOnly: true);

        $record = LessonDoubt::query()
            ->where('course_id', $course)
            ->where('lesson_id', $lesson)
            ->where('user_id', $this->student($request)->id)
            ->where('status', '!=', 'hidden')
            ->findOrFail($doubt);

        $doubts->follow($record, $this->student($request), $validated['body']);

        return back()->with('success', 'Added to your question.');
    }
}
