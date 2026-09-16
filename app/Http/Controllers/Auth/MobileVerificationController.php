<?php

namespace App\Http\Controllers\Auth;

use App\Enums\AuthEvent;
use App\Enums\OtpChannel;
use App\Enums\OtpPurpose;
use App\Http\Controllers\Controller;
use App\Services\Auth\AuthAuditor;
use App\Services\Auth\OtpService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Confirming a mobile number with a one time code.
 *
 * Unlike the sign in code endpoint, this one is behind authentication and the
 * number is read from the account rather than the request, so it cannot be
 * used to send messages to an arbitrary number.
 */
class MobileVerificationController extends Controller
{
    public function send(Request $request, OtpService $otp, AuthAuditor $auditor): RedirectResponse
    {
        $user = $request->user();

        if (blank($user->mobile)) {
            throw ValidationException::withMessages([
                'mobile' => 'Add a mobile number to your profile first.',
            ]);
        }

        if ($user->mobile_verified_at) {
            return back()->with('info', 'That number is already confirmed.');
        }

        if ($seconds = $otp->cooldownFor($user->mobile, OtpPurpose::VerifyMobile)) {
            throw ValidationException::withMessages([
                'code' => "A code was just sent. You can ask for another in {$seconds} seconds.",
            ]);
        }

        $otp->issue($user, $user->mobile, OtpChannel::Sms, OtpPurpose::VerifyMobile, $request->ip());
        $auditor->record(AuthEvent::OtpRequested, $user, $user->mobile, true, null, 'sms');

        return back()->with('success', 'Code sent to your mobile.');
    }

    public function confirm(Request $request, OtpService $otp, AuthAuditor $auditor): RedirectResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'code' => ['required', 'string', 'size:6'],
        ]);

        $result = $otp->verify($user->mobile, OtpPurpose::VerifyMobile, $validated['code']);

        if (! $result->passed()) {
            $auditor->failure(AuthEvent::OtpFailed, $user->mobile, $result->value, $user);

            throw ValidationException::withMessages(['code' => $result->message()]);
        }

        $user->forceFill(['mobile_verified_at' => now()])->save();
        $auditor->success(AuthEvent::MobileVerified, $user);

        return back()->with('success', 'Mobile number confirmed.');
    }
}
