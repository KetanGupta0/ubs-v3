<?php

namespace App\Http\Controllers\Shop;

use App\Enums\AuthEvent;
use App\Enums\OtpChannel;
use App\Enums\OtpPurpose;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Admin\AccountCreator;
use App\Services\Auth\AuthAuditor;
use App\Services\Auth\LoginPipeline;
use App\Services\Auth\OtpService;
use App\Support\Identifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * A guest at checkout proves their email address with a code, and that is
 * their way into an account (CL6).
 *
 * The answer to asking for a code is the same whether or not the address has
 * an account: a code goes to it either way. Only once the code is typed, by
 * whoever holds the address, does anything depend on the account: a new
 * address gets a client account through `AccountCreator`; an existing client
 * is signed in through `LoginPipeline`, two-step sign in and suspension
 * included; a staff or student address is told it cannot buy.
 */
class CheckoutController extends Controller
{
    public const SESSION_KEY = 'checkout.buyer';

    public function sendCode(Request $request, OtpService $otp): RedirectResponse
    {
        $request->merge([
            'email' => mb_strtolower(trim((string) $request->input('email'))),
            'mobile' => $request->filled('mobile') ? Identifier::normaliseMobile((string) $request->input('mobile')) : null,
        ]);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'mobile' => ['nullable', 'string', 'regex:/^\+\d{10,15}$/'],
            'company' => ['nullable', 'string', 'max:160'],
            // A field a person never sees and a crude bot usually fills.
            'website' => ['prohibited'],
        ], [
            'mobile.regex' => 'Enter a valid mobile number, including the country code.',
            'website.prohibited' => 'That could not be accepted.',
        ]);

        if ($seconds = $otp->cooldownFor($validated['email'], OtpPurpose::Checkout)) {
            throw ValidationException::withMessages(['email' => "A code was just sent. You can ask for another in {$seconds} seconds."]);
        }

        if ($otp->hasReachedHourlyLimit($validated['email'], OtpPurpose::Checkout)) {
            throw ValidationException::withMessages(['email' => 'Too many codes asked for this address. Try again later.']);
        }

        // To the address, never to an account: the same message, sent the
        // same way, whoever it belongs to.
        $otp->issue(null, $validated['email'], OtpChannel::Email, OtpPurpose::Checkout, $request->ip());

        $request->session()->put(self::SESSION_KEY, [
            'name' => trim($validated['name']),
            'email' => $validated['email'],
            'mobile' => $validated['mobile'],
            'company' => isset($validated['company']) ? trim($validated['company']) : null,
        ]);

        return back()->with('checkoutCodeSentTo', Identifier::mask($validated['email']));
    }

    public function verify(
        Request $request,
        OtpService $otp,
        LoginPipeline $pipeline,
        AccountCreator $accounts,
        AuthAuditor $auditor,
    ): RedirectResponse {
        $buyer = $request->session()->get(self::SESSION_KEY);

        if (! is_array($buyer) || empty($buyer['email'])) {
            return back()->with('error', 'Start again: give us your email address and we will send a new code.');
        }

        $validated = $request->validate(['code' => ['required', 'string', 'size:6']]);
        $result = $otp->verify($buyer['email'], OtpPurpose::Checkout, $validated['code']);

        if (! $result->passed()) {
            $auditor->failure(AuthEvent::OtpFailed, $buyer['email'], $result->value);

            throw ValidationException::withMessages(['code' => $result->message()]);
        }

        $user = User::query()->where('email', $buyer['email'])->first();

        if ($user && ! $user->isClient()) {
            $request->session()->forget(self::SESSION_KEY);

            throw ValidationException::withMessages(['code' => 'This address belongs to a staff or learning account here, which cannot buy. Use the address you use for work.']);
        }

        if ($user && ($reason = $pipeline->refusalReason($user))) {
            throw ValidationException::withMessages(['code' => $reason]);
        }

        if (! $user) {
            $user = $accounts->createForBuyer($buyer);
        } elseif (! $user->hasVerifiedEmail()) {
            // The code just proved the address.
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        $request->session()->forget(self::SESSION_KEY);
        $auditor->record(AuthEvent::OtpVerified, $user, $buyer['email'], true, null, 'checkout');

        // Back to the cart afterwards, through the two-step challenge if they have it on.
        redirect()->setIntendedUrl('/cart');

        if ($pipeline->requiresTwoFactor($user)) {
            $pipeline->beginTwoFactorChallenge($user, 'checkout');

            return redirect()->route('two-factor.challenge');
        }

        $pipeline->complete($user, 'checkout');

        return redirect('/cart')->with('success', 'You are signed in. Check the details and place your order.');
    }

    /** Sign in with a password or Google instead, and come back to the cart. */
    public function signIn(): RedirectResponse
    {
        redirect()->setIntendedUrl('/cart');

        return redirect()->route('login');
    }

    /** Use a different address: forget the one waiting for its code. */
    public function restart(Request $request): RedirectResponse
    {
        $request->session()->forget(self::SESSION_KEY);

        return back();
    }
}
