<?php

use App\Http\Controllers\HealthController;
use App\Http\Middleware\EnsurePasswordIsOwned;
use App\Http\Middleware\EnsureUserHasPermission;
use App\Http\Middleware\EnsureUserHasRole;
use App\Http\Middleware\GuardImpersonation;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RecordLastSeen;
use App\Http\Middleware\RedirectIfAuthenticated;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\ThrottleRequests;
use App\Models\Redirect;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;
use Sentry\Laravel\Integration;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
        // For an uptime monitor: the database, the scheduler, the queue and
        // storage, outside every middleware group so it needs no session,
        // and so a database that is down answers 503 rather than a crash.
        then: function () {
            Route::get('health', HealthController::class)->name('health');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // On every response, the API and /health included (Step I).
        $middleware->append(SecurityHeaders::class);

        $middleware->web(append: [
            // Before anything renders: a member of staff viewing as somebody
            // else may look and nothing more.
            GuardImpersonation::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            RecordLastSeen::class,
        ]);

        $middleware->api(append: [
            RecordLastSeen::class,
        ]);

        $middleware->alias([
            'role' => EnsureUserHasRole::class,
            'password.owned' => EnsurePasswordIsOwned::class,
            'permission' => EnsureUserHasPermission::class,
            'guest' => RedirectIfAuthenticated::class,

            // Sanctum does not check token abilities on its own. Without these
            // the short lived two factor challenge token would open the whole
            // API rather than just the challenge endpoint.
            'abilities' => CheckAbilities::class,
            'ability' => CheckForAnyAbility::class,

            // An unnamed throttle counts per route rather than sharing one
            // count with every other unnamed throttle (Step I).
            'throttle' => ThrottleRequests::class,
        ]);

        // Unauthenticated web requests land on the single sign in form.
        $middleware->redirectGuestsTo(fn () => route('login'));

        // A mail app's own unsubscribe button posts here with no session (RFC
        // 8058). The link's signature is what protects it (§8).
        // The browser posts what the content security policy blocked the
        // same way, with nothing to sign it (Step I).
        $middleware->validateCsrfTokens(except: ['unsubscribe/*', 'csp-reports']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // To Sentry once SENTRY_LARAVEL_DSN is set; config/sentry.php says
        // what a report may carry, which is the fault and never the person.
        Integration::handles($exceptions);

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // An old address with a new home is sent on rather than lost.
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            try {
                return Redirect::forMissing($request);
            } catch (Throwable) {
                return null;
            }
        });
    })->create();
