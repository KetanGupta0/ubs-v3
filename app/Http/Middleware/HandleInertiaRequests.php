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
            ],

            // One shot feedback surfaced by the toast system in the app shell.
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'info' => fn () => $request->session()->get('info'),
                'warning' => fn () => $request->session()->get('warning'),
            ],

            'ziggy' => fn () => [
                'location' => $request->url(),
            ],
        ];
    }
}
