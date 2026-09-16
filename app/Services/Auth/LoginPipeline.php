<?php

namespace App\Services\Auth;

use App\Enums\AuthEvent;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * What happens once a person has proven who they are.
 *
 * Every route into the application converges here: password, one time code,
 * Google, and the mobile API. That is deliberate. Suspension checks, the two
 * factor gate and the audit trail have to be identical on all of them, and the
 * reliable way to guarantee that is to have exactly one place that does it.
 */
class LoginPipeline
{
    /** Session key holding the user awaiting a two factor challenge. */
    public const PENDING_KEY = 'auth.two_factor.pending';

    /** Session key recording how that pending user authenticated. */
    public const PENDING_METHOD_KEY = 'auth.two_factor.method';

    public function __construct(
        protected AuthAuditor $auditor,
        protected Request $request,
    ) {}

    /**
     * Whether this account is allowed in at all.
     *
     * Returns the reason it is not, or null when it may proceed.
     */
    public function refusalReason(User $user): ?string
    {
        if (! $user->canSignIn()) {
            return 'This account has been suspended. Contact us if you think that is a mistake.';
        }

        return null;
    }

    /**
     * True when the person still owes a second factor.
     *
     * A one time code proves control of a registered email or phone, which is
     * possession, not a second factor on top of possession. So when someone has
     * turned on an authenticator app, they are challenged no matter which route
     * they took to get here.
     */
    public function requiresTwoFactor(User $user): bool
    {
        return $user->hasTwoFactorEnabled();
    }

    /** Park the user in the session until they answer the challenge. */
    public function beginTwoFactorChallenge(User $user, string $method): void
    {
        $this->request->session()->put(self::PENDING_KEY, $user->getKey());
        $this->request->session()->put(self::PENDING_METHOD_KEY, $method);

        $this->auditor->record(AuthEvent::TwoFactorChallenged, $user, $user->email, true, null, $method);
    }

    public function pendingUser(): ?User
    {
        $id = $this->request->session()->get(self::PENDING_KEY);

        return $id ? User::query()->find($id) : null;
    }

    public function pendingMethod(): string
    {
        return (string) $this->request->session()->get(self::PENDING_METHOD_KEY, 'password');
    }

    public function forgetPendingChallenge(): void
    {
        $this->request->session()->forget([self::PENDING_KEY, self::PENDING_METHOD_KEY]);
    }

    /**
     * Sign the user in for real.
     *
     * The session id is regenerated so a fixation attempt cannot ride an
     * attacker chosen session into an authenticated one.
     */
    public function complete(User $user, string $method, bool $remember = false): void
    {
        Auth::login($user, $remember);

        $this->request->session()->regenerate();
        $this->forgetPendingChallenge();

        $user->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $this->request->ip(),
        ])->save();

        $this->auditor->record(AuthEvent::LoginSucceeded, $user, $user->email, true, null, $method);
    }

    /**
     * Where to send this person next.
     *
     * A forced password change outranks everything, because an account still
     * holding an admin generated password is not yet fully theirs.
     */
    public function destinationFor(User $user): string
    {
        if ($user->must_change_password) {
            return route('password.change');
        }

        return $user->homeRoute();
    }
}
