<?php

namespace App\Http\Controllers\Student;

use App\Models\Batch;
use App\Models\Course;
use App\Models\Enrollment;
use App\Services\Lms\Enroller;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Courses a student can join from inside the LMS.
 *
 * Shows both publicly listed courses and the ones marked LMS only, because a
 * student who is already signed in is exactly the audience the second kind
 * exists for. The public site still never sees them.
 */
class CatalogueController extends StudentController
{
    public function index(Request $request): Response
    {
        $enrolled = $this->enrolledCourseIds($request);

        $courses = Course::query()
            ->where('is_published', true)
            ->with(['batches' => fn ($query) => $query->running()->orderBy('starts_on')])
            ->orderBy('sort_order')
            ->get();

        return Inertia::render('student/Catalogue', [
            'courses' => $courses->map(fn (Course $course) => [
                'id' => $course->id,
                'title' => $course->title,
                'tagline' => $course->tagline,
                'summary' => $course->summary,
                'type' => $course->type,
                'level' => $course->level,
                'accent' => $course->accent,
                'duration' => $course->durationLabel(),
                'mode' => $course->mode,
                'price' => $course->isFree() ? 'Included' : Money::display($course->effectivePrice()),
                'free' => $course->isFree(),
                'lmsOnly' => $course->visibility === 'lms_only',
                'enrolled' => in_array($course->id, $enrolled, true),
                'batches' => $course->batches->map(fn (Batch $batch) => [
                    'id' => $batch->id,
                    'name' => $batch->name,
                    'startsOn' => $batch->starts_on?->format('j M Y'),
                    'schedule' => $batch->schedule ?? [],
                    'seatsLeft' => $batch->seatsLeft(),
                    'full' => $batch->seatsLeft() === 0,
                ]),
            ]),
        ]);
    }

    /**
     * Join a course.
     *
     * A paid course enrols unpaid and raises the fee, so the student can see
     * the syllabus and schedule while it is settled. Only the lessons marked as
     * needing payment stay sealed.
     */
    public function enrol(Request $request, Enroller $enroller): RedirectResponse
    {
        $validated = $this->validatedInput($request, [
            'course_id' => ['required', 'integer'],
            'batch_id' => ['nullable', 'integer'],
        ]);

        $course = Course::query()
            ->where('is_published', true)
            ->findOrFail($validated['course_id']);

        $batch = $validated['batch_id']
            ? Batch::query()->where('course_id', $course->id)->find($validated['batch_id'])
            : null;

        if ($batch && $batch->seatsLeft() === 0) {
            return back()->withErrors(['batch_id' => 'That batch is full. Pick another one.']);
        }

        $existing = Enrollment::query()
            ->where('user_id', $this->student($request)->id)
            ->where('course_id', $course->id)
            ->first();

        if ($existing && $existing->status !== 'dropped') {
            return redirect()->route('student.courses.show', $course->id);
        }

        $enrolment = $enroller->enrol($this->student($request), $course, $batch, source: 'self');

        if (! $enrolment->has_paid && $enrolment->payment_request_id) {
            return redirect()
                ->route('student.payments.show', $enrolment->payment_request_id)
                ->with('info', 'You are enrolled. Settle the fee to open the full course.');
        }

        return redirect()
            ->route('student.courses.show', $course->id)
            ->with('success', 'You are on it. Good luck.');
    }
}
