<?php

namespace App\Enums;

enum UserStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    /** Created but never signed in, or awaiting verification. */
    case Pending = 'pending';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function canSignIn(): bool
    {
        return $this !== self::Suspended;
    }
}
