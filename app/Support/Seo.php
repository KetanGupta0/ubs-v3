<?php

namespace App\Support;

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

        return [
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

    /** The organisation block, repeated on every page for search engines. */
    public static function organisation(): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'Organization',
            'name' => 'Unboundbyte Solutions Private Limited',
            'alternateName' => 'Unboundbyte',
            'url' => url('/'),
            'logo' => url('/brand/icon-512.png'),
            'description' => 'Custom software development, maintenance and live technical training.',
            'address' => ['@type' => 'PostalAddress', 'addressCountry' => 'IN'],
        ];
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
