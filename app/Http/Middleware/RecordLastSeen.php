<?php

namespace App\Http\Middleware;

use App\Services\Auth\Impersonation;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Notes when somebody was last here, for "online now".
 *
 * After the response, so the guard the route used (session or API token) has
 * already said who it is. At most one write a minute per person, straight to
 * the column so nothing else about the row is touched. Staff viewing the
 * platform as somebody are not that somebody being online.
 */
class RecordLastSeen
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $user = $request->user();

        if (! $user || ($user->last_seen_at && $user->last_seen_at->gt(now()->subMinute()))) {
            return $response;
        }

        if (app(Impersonation::class)->active()) {
            return $response;
        }

        DB::table('users')->where('id', $user->id)->update(['last_seen_at' => now()]);

        return $response;
    }
}
