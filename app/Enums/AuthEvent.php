<?php

namespace App\Enums;

enum AuthEvent: string
{
    case LoginSucceeded = 'login.succeeded';
    case LoginFailed = 'login.failed';
    case LoginThrottled = 'login.throttled';
    case LoggedOut = 'logout';

    case OtpRequested = 'otp.requested';
    case OtpVerified = 'otp.verified';
    case OtpFailed = 'otp.failed';

    case TwoFactorChallenged = 'two_factor.challenged';
    case TwoFactorPassed = 'two_factor.passed';
    case TwoFactorFailed = 'two_factor.failed';
    case TwoFactorEnabled = 'two_factor.enabled';
    case TwoFactorDisabled = 'two_factor.disabled';
    case RecoveryCodeUsed = 'two_factor.recovery_used';

    case SocialLinked = 'social.linked';
    case SocialLoginSucceeded = 'social.login.succeeded';
    case SocialLoginFailed = 'social.login.failed';

    case PasswordResetRequested = 'password.reset_requested';
    case PasswordReset = 'password.reset';
    case PasswordChanged = 'password.changed';

    case EmailVerified = 'email.verified';
    case MobileVerified = 'mobile.verified';

    case Registered = 'account.registered';
    case SessionRevoked = 'session.revoked';
    case TokenIssued = 'token.issued';
    case TokenRevoked = 'token.revoked';
}
