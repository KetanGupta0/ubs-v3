<?php

namespace App\Enums;

enum OtpChannel: string
{
    case Email = 'email';
    case Sms = 'sms';

    public function label(): string
    {
        return $this === self::Email ? 'Email' : 'SMS';
    }
}
