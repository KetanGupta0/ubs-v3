<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AuthEvent;
use App\Enums\OtpChannel;
use App\Enums\OtpPurpose;
use App\Enums\OtpResult;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\AuthAuditor;
use App\Services\Auth\LoginPipeline;
use App\Services\Auth\OtpService;
use App\Services\Auth\TwoFactorService;
use App\Support\Identifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Authentication for the mobile applications.
 *
 * The same rules as the web: identical failure messages whichever thing was
 * wrong, one time codes only to registered destinations, and two factor
 * enforced when the account has it on.
 *
 * Two factor over an API needs somewhere to hold the half finished sign in, and
 * a session is not available. So a short lived token limited to the ability
 * `two-factor` is issued instead: enough to answer the challenge, useless for
 * anything else, and discarded the moment the challenge is answered.
 */
class AuthController extends Controller
{
    protected const CHALLENGE_ABILITY = 'two-factor';

    /**
     * The ability a normal access token carries.
     *
     * Full tokens are issued with `*`, which satisfies any ability check, so
     * this exists to be the thing a challenge token demonstrably lacks.
     */
    public const ACCESS_ABILITY = 'api-access';

    protected const CHALLENGE_MINUTES = 10;

    protected const MAX_ATTEMPTS = 5;

    public function login(Request $request, AuthAuditor $auditor, LoginPipeline $pipeline): JsonResponse
    {
        $validated = $request->validate($this->deviceRules() + [
            'identifier' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
        ]);

        $key = 'api-login:'.sha1(Identifier::normalise($validated['identifier']).'|'.$request->ip());

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            $auditor->failure(AuthEvent::LoginThrottled, $validated['identifier'], 'rate_limited', null, 'api');

            return response()->json([
                'message' => 'Too many attempts. Try again shortly.',
                'retry_after' => RateLimiter::availableIn($key),
            ], 429);
        }

        $user = Identifier::resolve($validated['identifier']);

        if (! $user || ! $user->hasPassword() || ! Hash::check($validated['password'], $user->password)) {
            RateLimiter::hit($key);
            $auditor->failure(AuthEvent::LoginFailed, $validated['identifier'], 'invalid_credentials', $user, 'api');

            throw ValidationException::withMessages([
                'identifier' => 'Those details do not match any account.',
            ]);
        }

        RateLimiter::clear($key);

        if ($refusal = $pipeline->refusalReason($user)) {
            $auditor->failure(AuthEvent::LoginFailed, $user->email, 'suspended', $user, 'api');

            return response()->json(['message' => $refusal, 'code' => 'account_suspended'], 403);
        }

        if ($pipeline->requiresTwoFactor($user)) {
            return $this->challengeResponse($user, $validated, $auditor);
        }

        return $this->issueToken($user, $validated, 'api.password', $pipeline);
    }

    public function requestCode(Request $request, OtpService $otp, AuthAuditor $auditor): JsonResponse
    {
        $validated = $request->validate([
            'identifier' => ['required', 'string', 'max:255'],
        ]);

        $identifier = Identifier::normalise($validated['identifier']);

        if ($seconds = $otp->cooldownFor($identifier, OtpPurpose::Login)) {
            return response()->json([
                'message' => 'A code was sent recently.',
                'retry_after' => $seconds,
            ], 429);
        }

        if ($otp->hasReachedHourlyLimit($identifier, OtpPurpose::Login)) {
            return response()->json(['message' => 'Too many codes requested. Try again later.'], 429);
        }

        $user = Identifier::resolve($identifier);

        if ($user && $user->canSignIn()) {
            $otp->issue(
                $user,
                $identifier,
                Identifier::isEmail($identifier) ? OtpChannel::Email : OtpChannel::Sms,
                OtpPurpose::Login,
                $request->ip(),
            );

            $auditor->record(AuthEvent::OtpRequested, $user, $identifier, true, null, 'api');
        }

        // Same answer either way, so this cannot be used to test which
        // addresses and numbers are registered.
        return response()->json([
            'message' => 'If that account exists, a code is on its way.',
            'sent_to' => Identifier::mask($identifier),
        ]);
    }

    public function verifyCode(Request $request, OtpService $otp, AuthAuditor $auditor, LoginPipeline $pipeline): JsonResponse
    {
        $validated = $request->validate($this->deviceRules() + [
            'identifier' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'size:6'],
        ]);

        $identifier = Identifier::normalise($validated['identifier']);
        $result = $otp->verify($identifier, OtpPurpose::Login, $validated['code']);

        if (! $result->passed()) {
            $auditor->failure(AuthEvent::OtpFailed, $identifier, $result->value, null, 'api');

            throw ValidationException::withMessages(['code' => $result->message()]);
        }

        $user = $otp->userFor($identifier, OtpPurpose::Login);

        if (! $user || $pipeline->refusalReason($user)) {
            throw ValidationException::withMessages(['code' => OtpResult::Incorrect->message()]);
        }

        $auditor->record(AuthEvent::OtpVerified, $user, $identifier, true, null, 'api');

        if ($pipeline->requiresTwoFactor($user)) {
            return $this->challengeResponse($user, $validated, $auditor);
        }

        return $this->issueToken($user, $validated, 'api.otp', $pipeline);
    }

    /**
     * Answer the two factor challenge, authenticated by the challenge token.
     */
    public function twoFactor(Request $request, TwoFactorService $twoFactor, AuthAuditor $auditor, LoginPipeline $pipeline): JsonResponse
    {
        $user = $request->user();
        $token = $user->currentAccessToken();

        // Only a challenge token: can() is true of a full token too, which
        // holds every ability, and that is not a challenge to answer.
        abort_unless($token && in_array(self::CHALLENGE_ABILITY, $token->abilities ?? [], true), 403, 'This token cannot answer a challenge.');

        $validated = $request->validate($this->deviceRules() + [
            'code' => ['required', 'string', 'max:20'],
        ]);

        $key = 'api-2fa:'.$user->getKey().'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            return response()->json([
                'message' => 'Too many attempts.',
                'retry_after' => RateLimiter::availableIn($key),
            ], 429);
        }

        if (! $twoFactor->challenge($user, $validated['code'])) {
            RateLimiter::hit($key, 300);
            $auditor->failure(AuthEvent::TwoFactorFailed, $user->email, 'incorrect_code', $user, 'api');

            throw ValidationException::withMessages(['code' => 'That code was not accepted.']);
        }

        RateLimiter::clear($key);
        $auditor->success(AuthEvent::TwoFactorPassed, $user, 'api');

        // The challenge token has done its job.
        $token->delete();

        // Suspended while the challenge was open: refused like any sign in.
        if ($refusal = $pipeline->refusalReason($user)) {
            return response()->json(['message' => $refusal, 'code' => 'account_suspended'], 403);
        }

        return $this->issueToken($user, $validated, 'api.2fa', $pipeline);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->loadMissing('profile');

        return response()->json(['data' => $this->userPayload($user)]);
    }

    /**
     * Store the push notification token for this device.
     *
     * Kept on the access token rather than the user, so it dies when the device
     * is signed out and we stop pushing to a handset that no longer has access.
     */
    public function registerPushToken(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'push_token' => ['required', 'string', 'max:500'],
            'platform' => ['sometimes', 'string', 'in:ios,android,web'],
        ]);

        $token = $request->user()->currentAccessToken();

        $token->forceFill(array_filter([
            'push_token' => $validated['push_token'],
            'platform' => $validated['platform'] ?? null,
        ]))->save();

        return response()->json(['message' => 'Push token registered.']);
    }

    /**
     * Choose a password, as the web forces after a temporary one (Step I).
     * Until this is done every other endpoint answers 423
     * (password_change_required). The device that changed it stays signed
     * in; every other device holding the old credential is signed out.
     */
    public function changePassword(Request $request, AuthAuditor $auditor): JsonResponse
    {
        $user = $request->user();

        $rules = ['password' => ['required', 'confirmed', Password::defaults()]];

        // An account made through Google has no password to confirm.
        if ($user->hasPassword()) {
            $rules['current_password'] = ['required', 'string'];
        }

        $validated = $request->validate($rules);

        if ($user->hasPassword() && ! Hash::check($validated['current_password'], $user->password)) {
            throw ValidationException::withMessages(['current_password' => 'That is not your current password.']);
        }

        if ($user->hasPassword() && Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages(['password' => 'Choose a password you have not used here before.']);
        }

        $user->forceFill(['password' => $validated['password'], 'must_change_password' => false])->save();

        $current = $user->currentAccessToken();
        $user->tokens()->whereKeyNot($current->getKey())->delete();

        $auditor->success(AuthEvent::PasswordChanged, $user, 'api');

        return response()->json(['message' => 'Password updated.', 'data' => $this->userPayload($user->fresh())]);
    }

    /**
     * A new token for this device in place of the one presented (Step I), so
     * an app can rotate its credential without signing in again. The old one
     * stops working at once; the device's details and push token carry over.
     */
    public function refresh(Request $request, AuthAuditor $auditor): JsonResponse
    {
        $user = $request->user();
        $old = $user->currentAccessToken();

        $new = $user->createToken($old->name, ['*']);
        $new->accessToken->forceFill([
            'device_identifier' => $old->device_identifier,
            'platform' => $old->platform,
            'app_version' => $old->app_version,
            'push_token' => $old->push_token,
        ])->save();

        $old->delete();
        $auditor->success(AuthEvent::TokenRevoked, $user, 'api.refresh');

        return response()->json(['token' => $new->plainTextToken]);
    }

    /** The devices signed in to this account, this one marked (Step I). */
    public function devices(Request $request): JsonResponse
    {
        $current = $request->user()->currentAccessToken();

        return response()->json([
            'data' => $request->user()->tokens()
                ->latest('last_used_at')
                ->get()
                // A half finished two-step sign in is not a device.
                ->reject(fn (PersonalAccessToken $token) => in_array(self::CHALLENGE_ABILITY, $token->abilities ?? [], true))
                ->map(fn (PersonalAccessToken $token) => [
                    'id' => $token->id,
                    'name' => $token->name,
                    'platform' => $token->platform,
                    'appVersion' => $token->app_version,
                    'lastUsedAt' => $token->last_used_at?->toIso8601String(),
                    'signedInAt' => $token->created_at->toIso8601String(),
                    'current' => $token->is($current),
                ])
                ->values(),
        ]);
    }

    /** Sign a device out (Step I). Somebody else's device is not found. */
    public function revokeDevice(Request $request, int $device, AuthAuditor $auditor): JsonResponse
    {
        $token = $request->user()->tokens()->whereKey($device)->firstOrFail();
        $token->delete();

        $auditor->success(AuthEvent::TokenRevoked, $request->user(), 'api.device');

        return response()->json(['message' => 'Signed out on that device.']);
    }

    public function logout(Request $request, AuthAuditor $auditor): JsonResponse
    {
        $auditor->success(AuthEvent::LoggedOut, $request->user(), 'api');

        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Signed out.']);
    }

    /** Sign out every device at once. */
    public function logoutAll(Request $request, AuthAuditor $auditor): JsonResponse
    {
        $auditor->success(AuthEvent::TokenRevoked, $request->user(), 'api.all');

        $request->user()->tokens()->delete();

        return response()->json(['message' => 'Signed out on every device.']);
    }

    /* ------------------------------------------------------------- internals */

    protected function deviceRules(): array
    {
        return [
            'device_name' => ['required', 'string', 'max:120'],
            'device_identifier' => ['sometimes', 'string', 'max:120'],
            'platform' => ['sometimes', 'string', 'in:ios,android,web'],
            'app_version' => ['sometimes', 'string', 'max:20'],
        ];
    }

    protected function challengeResponse(User $user, array $validated, AuthAuditor $auditor): JsonResponse
    {
        $auditor->record(AuthEvent::TwoFactorChallenged, $user, $user->email, true, null, 'api');

        $token = $user->createToken(
            $validated['device_name'].' (challenge)',
            [self::CHALLENGE_ABILITY],
            now()->addMinutes(self::CHALLENGE_MINUTES),
        );

        return response()->json([
            'two_factor_required' => true,
            'challenge_token' => $token->plainTextToken,
            'expires_in' => self::CHALLENGE_MINUTES * 60,
        ], 202);
    }

    protected function issueToken(User $user, array $validated, string $method, LoginPipeline $pipeline): JsonResponse
    {
        $token = $pipeline->completeWithToken($user, $method, $validated);

        return response()->json([
            'token' => $token,
            'must_change_password' => (bool) $user->must_change_password,
            'data' => $this->userPayload($user),
        ]);
    }

    protected function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'mobile' => $user->mobile,
            'role' => $user->role->value,
            'status' => $user->status->value,
            'avatar_url' => $user->avatar_url,
            'email_verified' => $user->hasVerifiedEmail(),
            'mobile_verified' => $user->mobile_verified_at !== null,
            'two_factor_enabled' => $user->hasTwoFactorEnabled(),
        ];
    }
}
