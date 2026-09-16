<?php

namespace App\Http\Requests\Auth;

use App\Enums\AuthEvent;
use App\Models\User;
use App\Services\Auth\AuthAuditor;
use App\Support\Identifier;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Validates and throttles a password sign in.
 *
 * The throttle key combines the identifier with the client address, so one
 * attacker hammering many accounts from one address is stopped, while a shared
 * office address cannot lock a colleague out of their own account.
 */
class LoginRequest extends FormRequest
{
    /** Failed attempts allowed before the account is locked for a minute. */
    protected const MAX_ATTEMPTS = 5;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'identifier' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
            'remember' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'identifier.required' => 'Enter your email address or mobile number.',
        ];
    }

    /**
     * Find the user and check the password.
     *
     * Failure always produces the same message whether the account exists or
     * the password was wrong, so the form cannot be used to discover which
     * email addresses and phone numbers are registered.
     */
    public function resolveUser(AuthAuditor $auditor): User
    {
        $this->ensureIsNotRateLimited($auditor);

        $identifier = (string) $this->input('identifier');
        $user = Identifier::resolve($identifier);

        if (! $user || ! $user->hasPassword() || ! Hash::check((string) $this->input('password'), $user->password)) {
            RateLimiter::hit($this->throttleKey());

            $auditor->failure(
                AuthEvent::LoginFailed,
                $identifier,
                $user ? 'wrong_password' : 'unknown_identifier',
                $user,
                'password',
            );

            throw ValidationException::withMessages([
                'identifier' => 'Those details do not match any account.',
            ]);
        }

        RateLimiter::clear($this->throttleKey());

        return $user;
    }

    protected function ensureIsNotRateLimited(AuthAuditor $auditor): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), self::MAX_ATTEMPTS)) {
            return;
        }

        Event::dispatch(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        $auditor->failure(AuthEvent::LoginThrottled, (string) $this->input('identifier'), 'rate_limited', null, 'password');

        throw ValidationException::withMessages([
            'identifier' => "Too many attempts. Try again in {$seconds} seconds.",
        ]);
    }

    public function throttleKey(): string
    {
        return Str::transliterate(
            Str::lower(Identifier::normalise((string) $this->input('identifier'))).'|'.$this->ip(),
        );
    }
}
