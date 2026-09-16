<?php

namespace App\Http\Controllers\Auth;

use App\Enums\AuthEvent;
use App\Enums\OtpChannel;
use App\Enums\OtpPurpose;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\AuthAuditor;
use App\Services\Auth\LoginPipeline;
use App\Services\Auth\OtpService;
use App\Support\Identifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Signing in with a code sent to a registered email address or mobile number.
 *
 * The response is identical whether or not the identifier matches an account.
 * An endpoint that says "no such user" is a free membership checker, and this
 * one is reachable without any credential at all.
 */
class OtpLoginController extends Controller
{
    public function request(Request $request, OtpService $otp, AuthAuditor $auditor): RedirectResponse
    {
        $validated = $request->validate([
            'identifier' => ['required', 'string', 'max:255'],
        ]);

        $identifier = Identifier::normalise($validated['identifier']);
        $user = Identifier::resolve($identifier);

        if ($seconds = $otp->cooldownFor($identifier, OtpPurpose::Login)) {
            throw ValidationException::withMessages([
                'identifier' => "A code was just sent. You can ask for another in {$seconds} seconds.",
            ]);
        }

        if ($otp->hasReachedHourlyLimit($identifier, OtpPurpose::Login)) {
            $auditor->failure(AuthEvent::OtpRequested, $identifier, 'hourly_limit');

            throw ValidationException::withMessages([
                'identifier' => 'Too many codes requested for this account. Try again later.',
            ]);
        }

        // Only send when the account exists and may sign in, but always report
        // the same outcome to the browser.
        if ($user && $user->canSignIn()) {
            $channel = Identifier::isEmail($identifier) ? OtpChannel::Email : OtpChannel::Sms;

            $otp->issue($user, $identifier, $channel, OtpPurpose::Login, $request->ip());
            $auditor->record(AuthEvent::OtpRequested, $user, $identifier, true, null, $channel->value);
        } else {
            $auditor->failure(AuthEvent::OtpRequested, $identifier, 'no_such_account');
        }

        return back()->with([
            'otpSentTo' => Identifier::mask($identifier),
            'otpIdentifier' => $identifier,
        ]);
    }

    public function verify(
        Request $request,
        OtpService $otp,
        LoginPipeline $pipeline,
        AuthAuditor $auditor,
    ): RedirectResponse {
        $validated = $request->validate([
            'identifier' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'size:6'],
        ]);

        $identifier = Identifier::normalise($validated['identifier']);
        $result = $otp->verify($identifier, OtpPurpose::Login, $validated['code']);

        if (! $result->passed()) {
            $auditor->failure(AuthEvent::OtpFailed, $identifier, $result->value);

            throw ValidationException::withMessages(['code' => $result->message()]);
        }

        $user = $otp->userFor($identifier, OtpPurpose::Login);

        if (! $user || ! $user->canSignIn()) {
            $auditor->failure(AuthEvent::OtpFailed, $identifier, 'account_unavailable');

            throw ValidationException::withMessages([
                'code' => 'That code is not valid. It may have expired, so try requesting a new one.',
            ]);
        }

        $auditor->record(AuthEvent::OtpVerified, $user, $identifier, true, null, 'otp');

        // Signing in by code also proves the address or number works, so mark
        // it verified rather than asking the person to do it again separately.
        $this->markDestinationVerified($user, $identifier);

        if ($pipeline->requiresTwoFactor($user)) {
            $pipeline->beginTwoFactorChallenge($user, 'otp');

            return redirect()->route('two-factor.challenge');
        }

        $pipeline->complete($user, 'otp');

        return redirect()->intended($pipeline->destinationFor($user));
    }

    protected function markDestinationVerified(User $user, string $identifier): void
    {
        if (Identifier::isEmail($identifier) && ! $user->hasVerifiedEmail()) {
            $user->forceFill(['email_verified_at' => now()])->save();

            return;
        }

        if (! Identifier::isEmail($identifier) && $user->mobile_verified_at === null) {
            $user->forceFill(['mobile_verified_at' => now()])->save();
        }
    }
}
