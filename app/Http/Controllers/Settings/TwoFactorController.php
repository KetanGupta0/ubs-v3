<?php

namespace App\Http\Controllers\Settings;

use App\Enums\AuthEvent;
use App\Http\Controllers\Controller;
use App\Services\Auth\AuthAuditor;
use App\Services\Auth\TwoFactorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Turning two factor authentication on and off.
 *
 * Both disabling and regenerating recovery codes ask for the password again.
 * Those two actions are exactly what someone who found an unlocked laptop
 * would reach for, and a password prompt is what stops them.
 */
class TwoFactorController extends Controller
{
    public function store(Request $request, TwoFactorService $twoFactor): RedirectResponse
    {
        $user = $request->user();

        if ($user->hasTwoFactorEnabled()) {
            return back()->with('info', 'Two factor authentication is already on.');
        }

        $record = $twoFactor->beginSetup($user);
        $uri = $twoFactor->provisioningUri($user, $record);

        return back()->with([
            'twoFactorSetup' => [
                'secret' => $record->secret,
                'qr' => $twoFactor->qrCodeSvg($uri),
                'uri' => $uri,
            ],
        ]);
    }

    public function confirm(Request $request, TwoFactorService $twoFactor, AuthAuditor $auditor): RedirectResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'size:6'],
        ]);

        $codes = $twoFactor->confirmSetup($request->user(), $validated['code']);

        if ($codes === null) {
            throw ValidationException::withMessages([
                'code' => 'That code did not match. Check your authenticator app is showing the current code.',
            ]);
        }

        $auditor->success(AuthEvent::TwoFactorEnabled, $request->user());

        return back()->with([
            'success' => 'Two factor authentication is on.',
            'recoveryCodes' => $codes,
        ]);
    }

    public function regenerate(Request $request, AuthAuditor $auditor): RedirectResponse
    {
        $this->confirmPassword($request);

        $record = $request->user()->twoFactorSecret;

        if (! $record?->isConfirmed()) {
            return back()->withErrors(['password' => 'Two factor authentication is not enabled.']);
        }

        $auditor->success(AuthEvent::TwoFactorEnabled, $request->user(), 'recovery_regenerated');

        return back()->with([
            'success' => 'New recovery codes generated. The old ones no longer work.',
            'recoveryCodes' => $record->regenerateRecoveryCodes(),
        ]);
    }

    public function destroy(Request $request, TwoFactorService $twoFactor, AuthAuditor $auditor): RedirectResponse
    {
        $this->confirmPassword($request);

        $twoFactor->disable($request->user());
        $auditor->success(AuthEvent::TwoFactorDisabled, $request->user());

        return back()->with('success', 'Two factor authentication is off.');
    }

    /**
     * Re-check the password before a sensitive change.
     *
     * An account with no password, created through Google, cannot be asked for
     * one, so it is sent to set a password first rather than being waved past.
     */
    protected function confirmPassword(Request $request): void
    {
        $user = $request->user();

        if (! $user->hasPassword()) {
            throw ValidationException::withMessages([
                'password' => 'Set a password on your account before changing these settings.',
            ]);
        }

        $validated = $request->validate([
            'password' => ['required', 'string'],
        ]);

        if (! Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'password' => 'That password is not correct.',
            ]);
        }
    }
}
