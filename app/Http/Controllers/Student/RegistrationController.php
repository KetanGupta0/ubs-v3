<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\Registration;
use App\Services\Lms\Registrar;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * A student's registrations made through public forms.
 *
 * When somebody types an existing student's address into a form, this is
 * where that student says whether it was them. Nothing goes on their record
 * until they do.
 */
class RegistrationController extends Controller
{
    public function index(Request $request): Response
    {
        $registrations = Registration::query()
            ->ownedBy($request->user())
            ->with('form.course', 'batch:id,name', 'form.batch:id,name')
            ->latest()
            ->get();

        return Inertia::render('student/registrations/Index', [
            'registrations' => $registrations->map(fn (Registration $registration) => [
                'id' => $registration->id,
                'course' => $registration->form->course->title,
                'courseId' => $registration->form->course_id,
                'tagline' => $registration->form->course->tagline,
                'batch' => ($registration->batch ?? $registration->form->batch)?->name,
                'fee' => $registration->form->feeLabel(),
                'reviewed' => $registration->form->approval === 'review',
                'status' => $registration->status,
                'statusLabel' => $registration->statusLabel(),
                // A reason the team wrote for the student; notes about the
                // account itself are for staff.
                'reason' => $registration->status === 'rejected' ? $registration->note : null,
                'submitted' => $registration->created_at->format('j M Y'),
                'enrolled' => $registration->status === 'approved' && $registration->enrollment_id !== null,
            ]),
        ]);
    }

    public function confirm(Request $request, int $registration, Registrar $registrar): RedirectResponse
    {
        $record = $this->own($request, $registration);

        try {
            $registrar->confirm($record);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        $record->refresh();

        if ($record->status === 'approved' && $record->enrollment && ! $record->enrollment->has_paid && $record->enrollment->payment_request_id) {
            return redirect()
                ->route('student.payments.show', $record->enrollment->payment_request_id)
                ->with('info', 'You are on the course. Settle the fee to open the lessons that need it.');
        }

        if ($record->status === 'approved') {
            return redirect()
                ->route('student.courses.show', $record->form->course_id)
                ->with('success', 'Confirmed. You are on the course.');
        }

        return back()->with('success', 'Confirmed. The team will look at it and write to you once it is decided.');
    }

    public function decline(Request $request, int $registration, Registrar $registrar): RedirectResponse
    {
        try {
            $registrar->decline($this->own($request, $registration));
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Done. Nothing has been added to your account.');
    }

    protected function own(Request $request, int $id): Registration
    {
        return Registration::query()->ownedBy($request->user())->whereKey($id)->firstOrFail();
    }
}
