<?php

namespace App\Http\Controllers\Student;

use App\Services\Lms\Reminders;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Which reminders a student gets, and what the platform records about their
 * studying, said plainly in one place.
 */
class PreferencesController extends StudentController
{
    public function show(Request $request): Response
    {
        $student = $this->student($request);

        return Inertia::render('student/Preferences', [
            'reminders' => collect(Reminders::KINDS)->map(fn (array $text, string $kind) => [
                'kind' => $kind,
                'label' => $text[0],
                'description' => $text[1],
                'on' => $student->wantsReminder($kind),
            ])->values(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $this->validatedInput($request, [
            'on' => ['array'],
            'on.*' => [Rule::in(array_keys(Reminders::KINDS))],
        ]);

        $off = array_values(array_diff(array_keys(Reminders::KINDS), $validated['on'] ?? []));

        $this->student($request)->forceFill([
            'reminder_preferences' => $off ? ['off' => $off] : null,
        ])->save();

        return back()->with('success', 'Saved. Reminders follow this from now on.');
    }
}
