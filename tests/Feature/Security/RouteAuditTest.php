<?php

use App\Http\Middleware\EnsureUserHasPermission;
use App\Http\Middleware\ThrottleRequests;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Routing\Middleware\ValidateSignature;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;

/*
 * Step I: rules about every route, read from the router, so a route added
 * later without its guard fails here rather than in production.
 */

/** @return array<int, string> Middleware class names as the router resolves them. */
function auditMiddleware(RoutingRoute $route): array
{
    return collect(Route::gatherRouteMiddleware($route))
        ->map(fn ($middleware) => is_string($middleware) ? $middleware : get_class($middleware))
        ->all();
}

function auditUses(RoutingRoute $route, string $class): bool
{
    return collect(auditMiddleware($route))->contains(fn (string $middleware) => $middleware === $class || str_starts_with($middleware, $class.':'));
}

function auditChanges(RoutingRoute $route): bool
{
    return collect($route->methods())->diff(['GET', 'HEAD', 'OPTIONS'])->isNotEmpty();
}

it('gives every admin route a permission, apart from the dashboard and reports, which decide for themselves', function () {
    // Reports are listed and opened by ReportLibrary, which checks each one's permission (CLAUDE.md).
    $decideForThemselves = ['admin', 'admin/reports', 'admin/reports/{report}', 'admin/reports/{report}/schedule', 'admin/reports/schedules/{schedule}'];

    $missing = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RoutingRoute $route) => str_starts_with($route->uri(), 'admin'))
        ->reject(fn (RoutingRoute $route) => in_array($route->uri(), $decideForThemselves, true))
        ->reject(fn (RoutingRoute $route) => auditUses($route, EnsureUserHasPermission::class))
        ->map(fn (RoutingRoute $route) => implode('|', $route->methods()).' '.$route->uri())
        ->values()
        ->all();

    expect($missing)->toBe([]);
});

it('throttles or signs every route that changes something without a sign in', function () {
    // Broadcasting checks the session itself and answers 403 to a stranger.
    $checksItself = ['broadcasting/auth'];

    $open = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RoutingRoute $route) => auditChanges($route))
        ->reject(fn (RoutingRoute $route) => auditUses($route, Authenticate::class))
        ->reject(fn (RoutingRoute $route) => auditUses($route, ThrottleRequests::class) || auditUses($route, ValidateSignature::class))
        ->reject(fn (RoutingRoute $route) => in_array($route->uri(), $checksItself, true))
        ->map(fn (RoutingRoute $route) => implode('|', $route->methods()).' '.$route->uri())
        ->values()
        ->all();

    expect($open)->toBe([]);
});

it('opens the API only to a full token, apart from signing in and the licence check', function () {
    $public = ['api/v1/auth/login', 'api/v1/auth/code', 'api/v1/auth/code/verify', 'api/v1/licences/check'];
    // The two factor step takes the short lived challenge token, and nothing else does.
    $challenge = ['api/v1/auth/two-factor'];

    $routes = collect(Route::getRoutes()->getRoutes())->filter(fn (RoutingRoute $route) => str_starts_with($route->uri(), 'api/'));

    expect($routes)->not->toBeEmpty();

    foreach ($routes as $route) {
        $uri = $route->uri();

        if (in_array($uri, $public, true)) {
            expect(auditUses($route, ThrottleRequests::class))->toBeTrue("{$uri} is open to anybody and must be throttled");

            continue;
        }

        expect(auditUses($route, Authenticate::class))->toBeTrue("{$uri} must need a token");

        if (! in_array($uri, $challenge, true)) {
            expect(collect(auditMiddleware($route))->contains(CheckAbilities::class.':api-access'))->toBeTrue("{$uri} must need abilities:api-access");
        }
    }
});

it('serves nothing from storage by a route of its own', function () {
    expect(collect(Route::getRoutes()->getRoutes())->filter(fn (RoutingRoute $route) => str_starts_with($route->uri(), 'storage/'))->all())->toBe([]);
});
