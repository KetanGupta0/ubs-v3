<?php

namespace App\Support;

/**
 * An address on this site as a document prints it.
 *
 * Paper cannot be corrected, so certificates and internship documents print
 * the site's permanent address (company.printed_url) rather than whichever
 * one the page was asked on: a certificate drawn while the site still answered
 * on a temporary domain would otherwise carry that domain in its QR code for
 * good. With none set, it is the address the site is reached at.
 */
class Printed
{
    public static function url(string $path = '/'): string
    {
        $base = rtrim((string) config('company.printed_url'), '/');

        return $base === '' ? url($path) : $base.'/'.ltrim($path, '/');
    }

    /** Whether an address is a hosting provider's temporary one, which goes when the domain is connected. */
    public static function isTemporary(?string $url): bool
    {
        $host = strtolower((string) parse_url((string) $url, PHP_URL_HOST));

        return $host !== '' && (bool) preg_match('/(^|\.)(hostingersite\.com|hostingerapp\.com)$/', $host);
    }
}
