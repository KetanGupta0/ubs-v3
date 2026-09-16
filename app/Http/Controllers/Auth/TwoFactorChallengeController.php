<?php

namespace App\Http\Controllers\Auth;

use App\Enums\AuthEvent;
use App\Http\Controllers\Controller;
use App\Services\Auth\AuthAuditor;
use App\Services\Auth\LoginPipeline;
use App\Services\Auth\TwoFactorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The second factor asked for during sign in.
 *
 * The user is not signed in while this screen is open. They are parked in the
 * session by id only, so an abandoned challenge leaves no usable session
 * behind.
 */
class TwoFactorChallengeController extends Controller
{
    protected const MAX_ATTEMPTS = 5;

    public function create(LoginPipeline $pipeline): Response|RedirectResponse
    {
        $user = $pipeline->pendingUser();

        if (! $user) {
            return redirect()->route('login');
        }

        return Inertia::render('auth/TwoFactorChallenge', [
            'recoveryCodesRemaining' => $user->twoFactorSecret?->remainingRecoveryCodes() ?? 0,
        ]);
    }

    public function store(
        Request $request,
        LoginPipeline $pipeline,
        TwoFactorService $twoFactor,
        AuthAuditor $auditor,
    ): RedirectResponse {
        $user = $pipeline->pendingUser();

        if (! $user) {
            return redirect()->route('login');
        }

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:20'],
        ]);

        $key = 'two-factor:'.$user->getKey().'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            $seconds = RateLimiter::availableIn($key);

            $auditor->failure(AuthEvent::TwoFactorFailed, $user->email, 'rate_limited', $user);

            throw ValidationException::withMessages([
                'code' => "Too many attempts. Try again in {$seconds} seconds.",
            ]);
        }

        $isRecovery = $twoFactor->isRecoveryCode($validated['code']);

        if (! $twoFactor->challenge($user, $validated['code'])) {
            RateLimiter::hit($key, 300);

            $auditor->failure(AuthEvent::TwoFactorFailed, $user->email, 'incorrect_code', $user);

            throw ValidationException::withMessages([
                'code' => 'That code was not accepted. Check your authenticator app and try again.',
            ]);
        }

        RateLimiter::clear($key);

        $auditor->success(
            $isRecovery ? AuthEvent::RecoveryCodeUsed : AuthEvent::TwoFactorPassed,
            $user,
        );

        $pipeline->complete($user, $pipeline->pendingMethod().'+2fa');

        return redirect()->intended($pipeline->destinationFor($user));
    }

    /** Abandon the challenge and go back to the sign in screen. */
    public function destroy(LoginPipeline $pipeline): RedirectResponse
    {
        $pipeline->forgetPendingChallenge();

        return redirect()->route('login');
    }
}
