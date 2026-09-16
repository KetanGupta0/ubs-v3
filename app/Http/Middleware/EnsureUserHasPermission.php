<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restrict a route to holders of a permission.
 *
 * Used as `permission:leads.view`. Full administrators hold everything
 * implicitly, so in practice this only bites on staff accounts that were given
 * a narrower set, which is the point: a sub admin can run the leads inbox
 * without also being handed billing.
 */
class EnsureUserHasPermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();

        if (! $user) {
            return redirect()->guest(route('login'));
        }

        foreach ($permissions as $permission) {
            if ($user->hasPermission($permission)) {
                return $next($request);
            }
        }

        abort(403, 'Your account does not have access to that.');
    }
}
