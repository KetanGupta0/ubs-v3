<?php

namespace App\Http\Controllers\Student;

use App\Models\Enrollment;
use App\Services\Lms\Performance;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** A student's own picture of how they are doing, one card per course. */
class PerformanceController extends StudentController
{
    public function __invoke(Request $request, Performance $performance): Response
    {
        $enrolments = Enrollment::query()
            ->forStudent($this->student($request))
            ->whereIn('status', ['active', 'completed'])
            ->with('course', 'batch', 'user')
            ->latest('enrolled_at')
            ->get();

        return Inertia::render('student/Performance', [
            'courses' => $enrolments->map(fn (Enrollment $enrolment) => $performance->forEnrolment($enrolment))->values(),
        ]);
    }
}
