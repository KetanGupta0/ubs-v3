<?php

namespace App\Support;

use App\Models\User;

/**
 * The single login field accepts either an email address or a mobile number.
 *
 * Normalising here means a person who stored "9876543210" can later sign in
 * with "+91 98765 43210" and reach the same account.
 */
class Identifier
{
    public const DEFAULT_COUNTRY_CODE = '+91';

    public static function isEmail(string $value): bool
    {
        return str_contains($value, '@');
    }

    /** Normalise whichever kind of identifier this is. */
    public static function normalise(string $value): string
    {
        $value = trim($value);

        return self::isEmail($value)
            ? mb_strtolower($value)
            : self::normaliseMobile($value);
    }

    /**
     * Convert a mobile number to E.164.
     *
     * A bare ten digit number is assumed to be Indian, which is where the
     * business operates. Anything already carrying a country code is left with
     * that country code.
     */
    public static function normaliseMobile(string $value): string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';

        if ($digits === '') {
            return '';
        }

        if (str_starts_with(trim($value), '+')) {
            return '+'.$digits;
        }

        // 0XXXXXXXXXX, the Indian trunk prefix.
        if (strlen($digits) === 11 && str_starts_with($digits, '0')) {
            return self::DEFAULT_COUNTRY_CODE.substr($digits, 1);
        }

        // 91XXXXXXXXXX, country code typed without a plus.
        if (strlen($digits) === 12 && str_starts_with($digits, '91')) {
            return '+'.$digits;
        }

        if (strlen($digits) === 10) {
            return self::DEFAULT_COUNTRY_CODE.$digits;
        }

        return '+'.$digits;
    }

    /** Find the account this identifier belongs to, if any. */
    public static function resolve(string $value): ?User
    {
        $normalised = self::normalise($value);

        if ($normalised === '') {
            return null;
        }

        return User::query()
            ->where(self::isEmail($normalised) ? 'email' : 'mobile', $normalised)
            ->first();
    }

    /** Partially hidden, for showing where a code was sent. */
    public static function mask(string $value): string
    {
        if (self::isEmail($value)) {
            [$local, $domain] = explode('@', $value, 2);
            $keep = min(2, strlen($local));

            return substr($local, 0, $keep).str_repeat('•', max(strlen($local) - $keep, 2))."@{$domain}";
        }

        return str_repeat('•', max(strlen($value) - 4, 3)).substr($value, -4);
    }
}
