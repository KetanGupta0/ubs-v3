<?php

namespace App\Http\Controllers\Auth;

use App\Enums\AuthEvent;
use App\Http\Controllers\Controller;
use App\Services\Auth\AuthAuditor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Changing your own password, and the forced first change.
 *
 * A client whose account was created by an administrator arrives here before
 * anything else, because until they replace it their password exists in an
 * email, an SMS and a delivery log.
 */
class PasswordChangeController extends Controller
{
    public function edit(Request $request): Response
    {
        return Inertia::render('auth/ChangePassword', [
            'forced' => (bool) $request->user()->must_change_password,
            'hasPassword' => $request->user()->hasPassword(),
        ]);
    }

    public function update(Request $request, AuthAuditor $auditor): RedirectResponse
    {
        $user = $request->user();

        $rules = [
            'password' => ['required', 'confirmed', Password::defaults()],
        ];

        // Someone who signed up through Google has no password to confirm.
        if ($user->hasPassword()) {
            $rules['current_password'] = ['required', 'string'];
        }

        $validated = $request->validate($rules);

        if ($user->hasPassword() && ! Hash::check($validated['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => 'That is not your current password.',
            ]);
        }

        if ($user->hasPassword() && Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'password' => 'Choose a password you have not used here before.',
            ]);
        }

        $user->forceFill([
            'password' => $validated['password'],
            'must_change_password' => false,
        ])->save();

        // Other browsers and devices holding the old credential are cut off.
        $user->tokens()->delete();
        $request->session()->regenerate();

        $auditor->success(AuthEvent::PasswordChanged, $user);

        return redirect($user->homeRoute())->with('success', 'Password updated.');
    }
}
