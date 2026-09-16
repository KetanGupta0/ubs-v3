<?php

namespace App\Http\Controllers\Auth;

use App\Enums\AuthEvent;
use App\Enums\Role;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Auth\AuthAuditor;
use App\Services\Auth\LoginPipeline;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

/**
 * Signing in with Google.
 *
 * Linking by email address is the risky part of any social sign in, because it
 * hands over an existing account to whoever controls that mailbox at Google.
 * So an unverified Google email is refused outright, and only a student
 * account is ever created automatically. Clients are created by an
 * administrator, by design, and Google must never become a side door around
 * that.
 */
class GoogleOAuthController extends Controller
{
    public function redirect(): RedirectResponse
    {
        abort_unless(filled(config('services.google.client_id')), 404);

        return Socialite::driver('google')->redirect();
    }

    public function callback(LoginPipeline $pipeline, AuthAuditor $auditor): RedirectResponse
    {
        abort_unless(filled(config('services.google.client_id')), 404);

        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (Throwable $e) {
            Log::warning('Google sign in failed', ['message' => $e->getMessage()]);
            $auditor->failure(AuthEvent::SocialLoginFailed, null, 'provider_error', null, 'google');

            return redirect()->route('login')
                ->withErrors(['identifier' => 'Google sign in did not complete. Try again, or use your password.']);
        }

        $email = $googleUser->getEmail() ? mb_strtolower($googleUser->getEmail()) : null;
        $emailVerified = (bool) ($googleUser->user['email_verified'] ?? false);

        if (! $email || ! $emailVerified) {
            $auditor->failure(AuthEvent::SocialLoginFailed, $email, 'email_not_verified', null, 'google');

            return redirect()->route('login')->withErrors([
                'identifier' => 'That Google account has no verified email address, so it cannot be used to sign in.',
            ]);
        }

        $user = DB::transaction(function () use ($googleUser, $email, $auditor) {
            $link = SocialAccount::query()
                ->where('provider', 'google')
                ->where('provider_user_id', $googleUser->getId())
                ->first();

            if ($link) {
                return $link->user;
            }

            $existing = User::query()->where('email', $email)->first();

            if ($existing) {
                $this->link($existing, $googleUser, $email);
                $auditor->success(AuthEvent::SocialLinked, $existing, 'google');

                return $existing;
            }

            // No account yet. Only the self registerable role is created here.
            $created = User::query()->create([
                'name' => $googleUser->getName() ?: str($email)->before('@')->headline()->toString(),
                'email' => $email,
                'password' => null,
                'role' => Role::Student,
                'status' => UserStatus::Active,
            ]);

            $created->forceFill(['email_verified_at' => now()])->save();
            $created->profile()->create([]);

            $this->link($created, $googleUser, $email);

            $auditor->success(AuthEvent::Registered, $created, 'google');

            return $created;
        });

        if ($reason = $pipeline->refusalReason($user)) {
            $auditor->failure(AuthEvent::SocialLoginFailed, $email, 'suspended', $user, 'google');

            return redirect()->route('login')->withErrors(['identifier' => $reason]);
        }

        if ($pipeline->requiresTwoFactor($user)) {
            $pipeline->beginTwoFactorChallenge($user, 'google');

            return redirect()->route('two-factor.challenge');
        }

        $auditor->success(AuthEvent::SocialLoginSucceeded, $user, 'google');
        $pipeline->complete($user, 'google');

        return redirect()->intended($pipeline->destinationFor($user));
    }

    protected function link(User $user, $googleUser, string $email): void
    {
        SocialAccount::query()->updateOrCreate(
            ['provider' => 'google', 'provider_user_id' => $googleUser->getId()],
            [
                'user_id' => $user->id,
                'email' => $email,
                'avatar' => $googleUser->getAvatar(),
                'linked_at' => now(),
            ],
        );
    }
}
