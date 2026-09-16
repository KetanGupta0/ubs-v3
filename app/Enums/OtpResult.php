<?php

namespace App\Enums;

/**
 * Why a one time code check passed or failed.
 *
 * Only `Valid` and a deliberately vague message reach the user. The specific
 * reason is for the audit log, because telling an attacker the difference
 * between "no such code" and "wrong code" tells them whether the account and
 * the request were real.
 */
enum OtpResult: string
{
    case Valid = 'valid';
    case NotFound = 'not_found';
    case Expired = 'expired';
    case AlreadyUsed = 'already_used';
    case Incorrect = 'incorrect';
    case TooManyAttempts = 'too_many_attempts';

    public function passed(): bool
    {
        return $this === self::Valid;
    }

    /** What the person is told, regardless of which failure occurred. */
    public function message(): string
    {
        return match ($this) {
            self::Valid => 'Code accepted.',
            self::TooManyAttempts => 'Too many incorrect attempts. Request a new code.',
            default => 'That code is not valid. It may have expired, so try requesting a new one.',
        };
    }
}
