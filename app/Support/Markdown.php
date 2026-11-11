<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Markdown written in the admin panel, turned into HTML for the public site.
 *
 * Typed HTML is stripped rather than passed through, and links that could run
 * as script (javascript:, data: and friends) are refused, so whatever somebody
 * writes in the panel can only ever come out as text, emphasis, lists, quotes,
 * headings and ordinary links.
 */
class Markdown
{
    public static function html(?string $markdown): string
    {
        return Str::markdown((string) $markdown, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 10,
        ]);
    }

    /** Plain text of the first characters, for a search description. */
    public static function excerpt(?string $markdown, int $limit = 155): string
    {
        return Str::of(strip_tags(static::html($markdown)))->squish()->limit($limit)->toString();
    }
}
