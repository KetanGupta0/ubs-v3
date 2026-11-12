<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * An old address sent on, permanently, to where the page lives now: the old
 * site's links, bookmarks and search results. Consulted only when the site
 * would otherwise answer 404 (bootstrap/app.php).
 */
class Redirect extends Model
{
    protected $guarded = ['id'];

    /**
     * Where an address the site would otherwise answer with a 404 now lives,
     * if anywhere.
     *
     * Asked only for a GET that was about to be a 404, so a redirect can
     * never hide a real page, and every other method still gets the 404 it
     * always did. The old site's /enroll/{slug} goes to /enrol/{slug}, which
     * already checks what the course is and whether it is listed, through
     * any course redirect for that slug.
     */
    public static function forMissing(Request $request): ?RedirectResponse
    {
        if (! $request->isMethod('GET') && ! $request->isMethod('HEAD')) {
            return null;
        }

        $path = static::normalise($request->path());

        if (preg_match('#^/enroll/([a-z0-9-]+)$#', $path, $match)) {
            $moved = static::query()->where('from_path', "/training/{$match[1]}")->value('to_path');

            return redirect('/enrol/'.($moved ? basename($moved) : $match[1]), 301);
        }

        $redirect = static::query()->where('from_path', $path)->first();

        if (! $redirect) {
            return null;
        }

        $redirect->increment('hits', 1, ['last_hit_at' => now()]);
        $query = $request->getQueryString();

        return redirect($redirect->to_path.($query ? "?{$query}" : ''), 301);
    }

    /** A path as stored: leading slash, no trailing one, no query string. */
    public static function normalise(string $path): string
    {
        return '/'.trim((string) parse_url($path, PHP_URL_PATH), '/');
    }
}
