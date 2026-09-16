<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The Blade template that boots the Vue application.
     */
    protected $rootView = 'app';

    /**
     * Cache bust the client bundle whenever the built assets change.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Props shared with every Inertia response.
     *
     * Keep this lean. Anything added here is serialised on every single page
     * load, so per page data belongs in the controller, not in here. Closures
     * are evaluated lazily, so `auth.user` costs nothing on guest pages.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),

            'app' => [
                'name' => config('app.name'),
                'env' => app()->environment(),
            ],

            'auth' => [
                'user' => fn () => $request->user()?->only([
                    'id', 'name', 'email', 'mobile', 'role', 'avatar_url',
                ]),

                /*
                 * What this account may do, so the navigation can leave out
                 * what it cannot. This is presentation only; every route is
                 * gated on the server regardless of what the client was sent.
                 */
                'permissions' => fn () => $request->user()?->isAdmin()
                    ? $request->user()->permissionKeys()
                    : [],
            ],

            /*
             * One shot data flashed for the next render.
             *
             * The first four are picked up by the toaster in the app shell. The
             * rest are payloads a screen needs exactly once and must never
             * survive a refresh: a two factor secret, a set of recovery codes,
             * where a one time code was sent. Sharing them here is what makes a
             * redirect back able to carry them, and flashing is what stops them
             * lingering.
             */
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'info' => fn () => $request->session()->get('info'),
                'warning' => fn () => $request->session()->get('warning'),
                'status' => fn () => $request->session()->get('status'),

                'otpSentTo' => fn () => $request->session()->get('otpSentTo'),
                'otpIdentifier' => fn () => $request->session()->get('otpIdentifier'),
                'twoFactorSetup' => fn () => $request->session()->get('twoFactorSetup'),
                'recoveryCodes' => fn () => $request->session()->get('recoveryCodes'),
                'issuedApiKey' => fn () => $request->session()->get('issuedApiKey'),
            ],

            'ziggy' => fn () => [
                'location' => $request->url(),
            ],
        ];
    }
}
