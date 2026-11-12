<?php

namespace App\Support;

use App\Models\SeoOverride;
use Illuminate\Support\Facades\Storage;

/**
 * Page metadata handed to the client.
 *
 * Kept in one place so every public page emits the same shape: a title, a
 * description, an Open Graph image and structured data. A page that forgets one
 * of them is a page that renders badly when someone shares it.
 */
class Seo
{
    public static function for(
        string $title,
        string $description,
        ?string $path = null,
        ?array $structuredData = null,
        ?string $image = null,
    ): array {
        $url = $path ? url($path) : url()->current();

        // Wording set in the admin panel for this page wins over the page's own.
        $override = SeoOverride::forPath('/'.trim((string) parse_url($url, PHP_URL_PATH), '/'));

        if ($override) {
            $title = $override['title'] ?: $title;
            $description = $override['description'] ?: $description;
            $image = $override['image_path'] ? Storage::disk('public')->url($override['image_path']) : $image;
        }

        return [
            // Staff previewing unpublished wording see a page search engines must never keep.
            'noindex' => (bool) ($override['noindex'] ?? false) || PageCopy::previewing(),
            'title' => $title,
            /*
             * Search engines cut a description off around 160 characters.
             * Str::limit appends its ending on top of the length given, so the
             * budget here is 157 plus a one character ellipsis.
             */
            'description' => str($description)->squish()->limit(157, '…')->toString(),
            'url' => $url,
            'image' => $image ?? url('/brand/og-default.png'),
            'structuredData' => $structuredData,
        ];
    }

    /**
     * The organisation block for search engines.
     *
     * Contact details and profiles appear here only when the settings screen
     * chose to show them on the site, so structured data never says more than
     * the page itself does.
     */
    public static function organisation(): array
    {
        $site = SiteIdentity::forPublic();

        return array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'Organization',
            'name' => $site['name'],
            'legalName' => $site['legalName'],
            'alternateName' => 'Unboundbyte',
            'url' => url('/'),
            'logo' => url('/brand/icon-512.png'),
            'description' => 'Custom software development, maintenance and live technical training.',
            'email' => $site['email'],
            'telephone' => $site['phone'],
            'address' => array_filter([
                '@type' => 'PostalAddress',
                'streetAddress' => $site['address'] ? str_replace("\n", ', ', $site['address']) : null,
                'addressCountry' => 'IN',
            ]),
            'sameAs' => collect($site['social'])->pluck('url')->all() ?: null,
        ], fn ($value) => $value !== null);
    }

    public static function breadcrumbs(array $crumbs): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => collect($crumbs)->values()->map(fn ($crumb, $index) => [
                '@type' => 'ListItem',
                'position' => $index + 1,
                'name' => $crumb['label'],
                'item' => url($crumb['href']),
            ])->all(),
        ];
    }
}
