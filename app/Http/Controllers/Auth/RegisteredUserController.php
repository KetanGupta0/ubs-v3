<?php

namespace App\Http\Controllers\Auth;

use App\Enums\AuthEvent;
use App\Enums\Role;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Rules\PersonName;
use App\Services\Auth\AuthAuditor;
use App\Services\Auth\LoginPipeline;
use App\Support\HumanCheck;
use App\Support\Identifier;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Student self registration.
 *
 * Only students may create their own account. The role is set here, never read
 * from the request, so posting `role=admin` at this endpoint achieves nothing.
 */
class RegisteredUserController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('auth/Register', [
            'googleEnabled' => filled(config('services.google.client_id')),
            // Set by "Enrol now" on a course page, so the form can say what
            // the account is for.
            'enrolling' => session('enrol.course'),
            'humanCheck' => HumanCheck::siteKey(),
        ]);
    }

    public function store(Request $request, LoginPipeline $pipeline, AuthAuditor $auditor): RedirectResponse
    {
        $request->merge([
            'email' => mb_strtolower(trim((string) $request->input('email'))),
            'mobile' => Identifier::normaliseMobile((string) $request->input('mobile')),
        ]);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120', new PersonName],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            'mobile' => ['required', 'string', 'regex:/^\+\d{10,15}$/', Rule::unique('users', 'mobile')],
            'password' => ['required', 'confirmed', Password::defaults()],
            'terms' => ['accepted'],
            // A field a person never sees and a crude bot usually fills. Until
            // November 2026 this form, alone of those that make accounts, had
            // neither it nor the human check.
            'website' => ['prohibited'],
        ], [
            'mobile.regex' => 'Enter a valid mobile number, including the country code.',
            'terms.accepted' => 'Please accept the terms to continue.',
            'website.prohibited' => 'That could not be accepted.',
        ]);

        if (! HumanCheck::passes($request)) {
            throw ValidationException::withMessages([
                'human' => 'Please complete the check that you are a person, then try again.',
            ]);
        }

        $user = DB::transaction(function () use ($validated) {
            $user = User::query()->create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'mobile' => $validated['mobile'],
                'password' => $validated['password'],
                'role' => Role::Student,
                'status' => UserStatus::Active,
            ]);

            $user->profile()->create([]);

            return $user;
        });

        event(new Registered($user));

        $auditor->success(AuthEvent::Registered, $user, 'self');

        $pipeline->complete($user, 'registration');

        // Somebody who came from "Enrol now" goes on to confirm the course
        // they chose; everybody else to their dashboard.
        $request->session()->forget('enrol.course');

        return redirect()->intended($pipeline->destinationFor($user))
            ->with('success', 'Welcome aboard. Check your email to confirm your address.');
    }
}
