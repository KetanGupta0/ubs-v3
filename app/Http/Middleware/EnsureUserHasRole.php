<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restrict a route to one or more roles.
 *
 * Used as `role:admin` or `role:admin,client`. A signed in user who reaches a
 * dashboard that is not theirs is sent to their own rather than shown a 403,
 * because in practice that is a stale bookmark, not an attack.
 */
class EnsureUserHasRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            return redirect()->guest(route('login'));
        }

        if (! in_array($user->role->value, $roles, true)) {
            return $request->expectsJson()
                ? response()->json(['message' => 'This account cannot access that area.'], 403)
                : redirect($user->homeRoute());
        }

        return $next($request);
    }
}
