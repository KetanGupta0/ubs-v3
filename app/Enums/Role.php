<?php

namespace App\Enums;

enum Role: string
{
    case Admin = 'admin';
    case Client = 'client';
    case Student = 'student';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Administrator',
            self::Client => 'Client',
            self::Student => 'Student',
        };
    }

    /** Where this role lands after signing in. */
    public function home(): string
    {
        return match ($this) {
            self::Admin => '/admin',
            self::Client => '/client',
            self::Student => '/student',
        };
    }

    /** Only students may create their own account. */
    public function selfRegisterable(): bool
    {
        return $this === self::Student;
    }
}
