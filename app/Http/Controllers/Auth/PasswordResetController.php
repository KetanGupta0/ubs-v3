<?php

namespace App\Http\Controllers\Auth;

use App\Enums\AuthEvent;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\AuthAuditor;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password as PasswordBroker;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Forgotten password, by emailed link.
 *
 * The request screen always reports success, for the same reason the one time
 * code screen does: a differentiated response turns the form into a way to
 * discover which email addresses hold accounts.
 */
class PasswordResetController extends Controller
{
    public function request(): Response
    {
        return Inertia::render('auth/ForgotPassword');
    }

    public function email(Request $request, AuthAuditor $auditor): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $email = mb_strtolower($validated['email']);

        PasswordBroker::sendResetLink(['email' => $email]);

        $auditor->record(AuthEvent::PasswordResetRequested, null, $email, true);

        return back()->with('status', 'If that address has an account, a reset link is on its way.');
    }

    public function edit(Request $request, string $token): Response
    {
        return Inertia::render('auth/ResetPassword', [
            'token' => $token,
            'email' => $request->query('email'),
        ]);
    }

    public function update(Request $request, AuthAuditor $auditor): RedirectResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $status = PasswordBroker::reset($validated, function (User $user, string $password) use ($auditor) {
            $user->forceFill([
                'password' => $password,
                'remember_token' => Str::random(60),
                // Resetting a password replaces an admin generated one, so the
                // forced change no longer applies.
                'must_change_password' => false,
            ])->save();

            // Every other session dies with the old password.
            $user->tokens()->delete();

            $auditor->success(AuthEvent::PasswordReset, $user);

            event(new PasswordReset($user));
        });

        if ($status !== PasswordBroker::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => 'That reset link is no longer valid. Request a new one.',
            ]);
        }

        return redirect()->route('login')->with('status', 'Your password has been reset. Sign in with it now.');
    }
}
