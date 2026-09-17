<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\Enrollment;
use Illuminate\Http\Request;

/**
 * Shared ground for everything a student sees.
 *
 * Same rule as the client portal: every read starts from this student's own
 * enrolments, so somebody else's course is not found rather than found and
 * refused. A 403 would confirm the record exists.
 */
abstract class StudentController extends Controller
{
    protected function student(Request $request)
    {
        return $request->user();
    }

    /** This student's enrolment on a course, or a 404 that says nothing. */
    protected function enrolment(Request $request, int $courseId, bool $activeOnly = false): Enrollment
    {
        return Enrollment::query()
            ->where('user_id', $this->student($request)->id)
            ->where('course_id', $courseId)
            ->when($activeOnly, fn ($query) => $query->active())
            ->with(['course', 'batch'])
            ->firstOrFail();
    }

    /** @return array<int, int> */
    protected function enrolledCourseIds(Request $request): array
    {
        return Enrollment::query()
            ->where('user_id', $this->student($request)->id)
            ->pluck('course_id')
            ->all();
    }

    /** @return array<int, int> */
    protected function enrolledBatchIds(Request $request): array
    {
        return Enrollment::query()
            ->where('user_id', $this->student($request)->id)
            ->whereNotNull('batch_id')
            ->pluck('batch_id')
            ->all();
    }
}
