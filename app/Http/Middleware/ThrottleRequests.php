<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests as Throttle;
use Symfony\Component\HttpFoundation\Response;

/**
 * A throttle without a name of its own counts for its route alone (Step I).
 *
 * Laravel's counts every unnamed throttle together, per person or per
 * address, so nine searches used up most of a support form's ten a minute,
 * and applying two codes in the cart used up the attempts at asking for a
 * checkout code (§4). A name is still how routes share a count on purpose,
 * and a named limiter (throttle:registrations) works as before.
 */
class ThrottleRequests extends Throttle
{
    public function handle($request, Closure $next, $maxAttempts = 60, $decayMinutes = 1, $prefix = ''): Response
    {
        if (is_string($maxAttempts) && func_num_args() === 3 && $this->limiter->limiter($maxAttempts) !== null) {
            return parent::handle($request, $next, $maxAttempts);
        }

        if ($prefix === '') {
            $prefix = 'route:'.$this->routeKey($request).':';
        }

        return parent::handle($request, $next, $maxAttempts, $decayMinutes, $prefix);
    }

    protected function routeKey(Request $request): string
    {
        $route = $request->route();

        return $route?->getName() ?? implode('|', $route?->methods() ?? []).' '.($route?->uri() ?? $request->path());
    }
}
