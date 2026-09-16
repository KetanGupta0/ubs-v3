<?php

namespace App\Enums;

enum OtpPurpose: string
{
    case Login = 'login';
    case VerifyMobile = 'verify_mobile';
    case VerifyEmail = 'verify_email';
    case ResetPassword = 'reset_password';

    /** How long a code stays usable. */
    public function lifetimeMinutes(): int
    {
        return match ($this) {
            self::Login => 10,
            self::VerifyMobile, self::VerifyEmail => 15,
            self::ResetPassword => 15,
        };
    }
}
