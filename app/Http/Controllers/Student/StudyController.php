<?php

namespace App\Http\Controllers\Student;

use App\Models\Lesson;
use App\Models\LessonNote;
use App\Models\LessonVisit;
use App\Services\Auth\Impersonation;
use App\Services\Lms\ContentGate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A student's own study aids: time on a lesson, notes and bookmarks.
 *
 * Every lesson is reached through the student's own enrolment, and only an
 * open lesson takes a note or time, so neither is a way to learn about a
 * lesson the gate has not opened.
 */
class StudyController extends StudentController
{
    /** One beat of time from a lesson page that is open and visible. */
    public function time(Request $request, int $course, int $lesson): HttpResponse
    {
        $validated = $request->validate(['seconds' => ['required', 'integer', 'min:1', 'max:3600']]);
        $record = $this->openLesson($request, $course, $lesson);

        LessonVisit::query()
            ->where('user_id', $this->student($request)->id)
            ->where('lesson_id', $record->id)
            ->first()
            ?->addTime((int) $validated['seconds']);

        return response()->noContent();
    }

    /** Save the note and the bookmark together. Both empty removes the row. */
    public function note(Request $request, int $course, int $lesson): RedirectResponse
    {
        $validated = $this->validatedInput($request, [
            'body' => ['nullable', 'string', 'max:'.LessonNote::MAX_LENGTH],
            'bookmarked' => ['boolean'],
        ], ['body.max' => 'Keep a note under '.number_format(LessonNote::MAX_LENGTH).' characters.']);

        $record = $this->openLesson($request, $course, $lesson);
        $body = trim((string) $validated['body']);

        if ($body === '' && ! $validated['bookmarked']) {
            LessonNote::query()->ownedBy($this->student($request))->where('lesson_id', $record->id)->delete();

            return back();
        }

        LessonNote::query()->updateOrCreate(
            ['user_id' => $this->student($request)->id, 'lesson_id' => $record->id],
            ['course_id' => $record->course_id, 'body' => $body === '' ? null : $body, 'is_bookmarked' => $validated['bookmarked']],
        );

        return back();
    }

    /** Every note and bookmark, newest first, on the courses they are still on. */
    public function notes(Request $request): Response
    {
        $private = app(Impersonation::class)->active();

        $notes = $private ? collect() : LessonNote::query()
            ->ownedBy($this->student($request))
            ->whereIn('course_id', $this->enrolledCourseIds($request))
            ->with('lesson:id,title,course_module_id,course_id', 'lesson.module:id,title', 'course:id,title')
            ->latest('updated_at')
            ->get();

        return Inertia::render('student/Notes', [
            'private' => $private,
            'notes' => $notes->map(fn (LessonNote $note) => [
                'id' => $note->id,
                'course' => $note->course->title,
                'courseId' => $note->course_id,
                'module' => $note->lesson->module?->title,
                'lesson' => $note->lesson->title,
                'href' => "/student/courses/{$note->course_id}/lessons/{$note->lesson_id}",
                'body' => $note->body,
                'bookmarked' => $note->is_bookmarked,
                'updated' => $note->updated_at->format('j M Y'),
            ])->values(),
        ]);
    }

    protected function openLesson(Request $request, int $course, int $lesson): Lesson
    {
        $enrolment = $this->enrolment($request, $course);

        $record = Lesson::query()
            ->where('course_id', $course)
            ->where('is_published', true)
            ->findOrFail($lesson);

        abort_unless(ContentGate::for($this->student($request), $enrolment)->isOpen($record), 403);

        return $record;
    }
}
