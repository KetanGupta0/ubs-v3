<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Models\Batch;
use App\Models\Course;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * "Enrol now" on a public course page.
 *
 * It does not create accounts or take money itself. It remembers which
 * course somebody wanted and hands them to the doors that already exist:
 * a signed in student goes straight to the confirm step, and a visitor
 * registers or signs in first and lands on that same step afterwards. So
 * there is still one way into an account (the sign in pipeline), one way
 * onto a course (Enroller) and one place the fee is priced (the invoice).
 */
class EnrolController extends Controller
{
    public function __invoke(Request $request, Course $course): RedirectResponse
    {
        // Only what the public site lists can be enrolled on from it.
        abort_unless($course->is_published && $course->visibility === 'public', 404);

        $coursePage = $course->isInternship() ? "/internships/{$course->slug}" : "/training/{$course->slug}";

        // Closed between batches: the course page says so, and says when.
        if (! $course->is_enrollment_open) {
            return redirect($coursePage);
        }

        $batch = $request->filled('batch')
            ? Batch::query()
                ->where('course_id', $course->id)
                ->where('is_published', true)
                ->find((int) $request->query('batch'))
            : null;

        $confirm = '/student/catalogue?join='.$course->id.($batch ? '&batch='.$batch->id : '');
        $user = $request->user();

        if ($user?->isStudent()) {
            return redirect($confirm);
        }

        if ($user) {
            // A client or a member of staff. Courses are taken on a student account.
            return redirect($coursePage);
        }

        // Where sign in and registration send somebody once they are through.
        $request->session()->put('url.intended', url($confirm));
        $request->session()->put('enrol.course', $course->title);

        return redirect()->route('register');
    }
}
