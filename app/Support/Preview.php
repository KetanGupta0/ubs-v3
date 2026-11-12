<?php

namespace App\Support;

use Inertia\Inertia;

/**
 * Staff looking at a catalogue item that is not published yet.
 *
 * An unpublished solution, service, course or internship is not found for
 * anybody else, so its address gives nothing away. For staff who may see the
 * catalogue it opens as it would look, says so across the top, and tells
 * search engines not to keep it.
 */
class Preview
{
    /** Whether this unpublished item may be shown to the person asking; 404 for everybody else. */
    public static function unpublished(bool $isPublished, string $permission = 'catalogue.view'): bool
    {
        if ($isPublished) {
            return false;
        }

        abort_unless((bool) request()->user()?->hasPermission($permission), 404);

        Inertia::share('previewNotice', 'Not published. Only staff can see this page, and search engines are told not to index it.');

        return true;
    }

    /** @param  array<string, mixed>  $seo */
    public static function seo(array $seo, bool $previewing): array
    {
        return $previewing ? [...$seo, 'noindex' => true] : $seo;
    }
}
