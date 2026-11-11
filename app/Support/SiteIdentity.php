<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Who the public site says it is, and how to reach us.
 *
 * The company details are the ones the settings screen already holds for
 * invoices. What the public site shows of them is a separate choice, made
 * item by item, because a GSTIN on every invoice is not a reason to print a
 * phone number on every page. The PAN is never shown.
 */
class SiteIdentity
{
    /** The networks a social link may point at, and the addresses each accepts. */
    public const SOCIAL = [
        'linkedin' => ['label' => 'LinkedIn', 'hosts' => ['linkedin.com']],
        'instagram' => ['label' => 'Instagram', 'hosts' => ['instagram.com']],
        'youtube' => ['label' => 'YouTube', 'hosts' => ['youtube.com', 'youtu.be']],
        'facebook' => ['label' => 'Facebook', 'hosts' => ['facebook.com']],
        'x' => ['label' => 'X', 'hosts' => ['x.com', 'twitter.com']],
        'github' => ['label' => 'GitHub', 'hosts' => ['github.com']],
    ];

    /** The choices on the settings screen, as stored. */
    public static function settings(): array
    {
        return [
            'show_email' => (bool) Setting::get('site.show_email', false),
            'show_phone' => (bool) Setting::get('site.show_phone', false),
            'show_address' => (bool) Setting::get('site.show_address', false),
            'show_registration' => (bool) Setting::get('site.show_registration', false),
            'whatsapp' => Setting::get('site.whatsapp'),
            'social' => collect(self::SOCIAL)->mapWithKeys(fn ($network, $key) => [$key => Setting::get("site.social.{$key}")])->all(),
        ];
    }

    /**
     * What every public page gets: only what was chosen to be shown.
     *
     * Settings are one cached map, so this costs no query.
     */
    public static function forPublic(): array
    {
        $choices = self::settings();
        $company = fn (string $key) => filled($value = Setting::get("company.{$key}", config("company.{$key}"))) ? trim((string) $value) : null;

        $address = collect([
            $company('address'),
            collect([$company('city'), $company('state'), $company('postal_code')])->filter()->implode(', '),
        ])->filter()->implode("\n");

        $phone = $choices['show_phone'] ? $company('phone') : null;
        $whatsapp = preg_replace('/\D+/', '', (string) $choices['whatsapp']) ?: null;
        // Shown as it was typed, spaces and all; the link needs the digits only.
        $whatsappLabel = '+'.ltrim(trim((string) $choices['whatsapp']), '+');

        return [
            'name' => $company('name') ?? 'Unboundbyte Solutions',
            'legalName' => $company('legal_name') ?? 'Unboundbyte Solutions Private Limited',
            'email' => $choices['show_email'] ? $company('email') : null,
            'phone' => $phone,
            'phoneHref' => $phone ? 'tel:'.preg_replace('/[^\d+]+/', '', $phone) : null,
            'whatsapp' => $whatsapp ? ['label' => $whatsappLabel, 'href' => "https://wa.me/{$whatsapp}"] : null,
            'address' => $choices['show_address'] && $address !== '' ? $address : null,
            'registration' => $choices['show_registration']
                ? array_filter(['CIN' => $company('cin'), 'GSTIN' => $company('gstin')])
                : [],
            'social' => collect($choices['social'])
                ->filter()
                ->map(fn (string $url, string $key) => ['network' => $key, 'label' => self::SOCIAL[$key]['label'], 'url' => $url])
                ->values()
                ->all(),
        ];
    }

    /** Whether a link really goes to the network it is filed under. */
    public static function belongsTo(string $network, string $url): bool
    {
        if (! isset(self::SOCIAL[$network]) || ! str_starts_with($url, 'https://') || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return collect(self::SOCIAL[$network]['hosts'])
            ->contains(fn (string $allowed) => $host === $allowed || str_ends_with($host, ".{$allowed}"));
    }
}
