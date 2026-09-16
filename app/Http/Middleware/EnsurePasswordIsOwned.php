<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Force a password change before anything else.
 *
 * Client accounts are created by an administrator, and their first password was
 * generated and sent over email and SMS. Until the person replaces it, that
 * credential exists in two inboxes and a message log, so the account is not yet
 * really theirs and should not be usable for anything else.
 */
class EnsurePasswordIsOwned
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user?->must_change_password && ! $this->isExempt($request)) {
            return $request->expectsJson()
                ? response()->json(['message' => 'Set your own password before continuing.'], 423)
                : redirect()->route('password.change');
        }

        return $next($request);
    }

    /** Routes that must stay reachable, or the user could never get out. */
    protected function isExempt(Request $request): bool
    {
        return $request->routeIs('password.change', 'password.change.store', 'logout', 'verification.*');
    }
}
