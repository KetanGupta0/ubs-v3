<?php

namespace App\Http\Middleware;

use App\Models\LegalDocument;
use App\Models\SiteMenu;
use App\Services\Auth\Impersonation;
use App\Services\Shop\CartStore;
use App\Support\PageCopy;
use App\Support\SiteIdentity;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
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

            /*
             * The public site's header and footer menus, as set in the admin
             * panel. One cached array, dropped whenever a menu is saved.
             */
            'menus' => fn () => SiteMenu::forLayout(),

            /*
             * The company's name and the contact details chosen to be shown on
             * the public site. Built from the cached settings map.
             */
            'site' => fn () => SiteIdentity::forPublic(),

            /*
             * How many products are in the cart, for the header (§4). Staff
             * and students cannot buy, so they are not asked about one.
             */
            'cart' => fn () => $request->user() && ! $request->user()->isClient()
                ? 0
                : app(CartStore::class)->count($request->user()),

            /* Staff looking at a fixed page's unpublished wording, so the page can say so. */
            'copyPreview' => fn () => PageCopy::previewing(),

            /*
             * Legal documents with a published version, for the footer of the
             * public site. A cached list of two or three links; a document
             * with nothing published is left out rather than linked to a 404.
             */
            'legal' => fn () => collect(LegalDocument::published())
                ->map(fn (array $document) => ['title' => $document['title'], 'href' => $document['href']])
                ->all(),

            /*
             * Set while a member of staff is viewing the platform as this
             * person, so every page can say so and offer the way back.
             */
            'viewingAs' => function () use ($request) {
                $impersonation = app(Impersonation::class);

                return $impersonation->active() ? [
                    'name' => $request->user()?->name,
                    'staff' => $impersonation->staff()?->name,
                    'endsAt' => $impersonation->expiresAt()->format('g:i a'),
                ] : null;
            },

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

                // A client's place in their organisation (CL8): the same
                // presentation-only courtesy; each action is refused on the
                // server for a role that may not do it.
                'client' => fn () => $request->user()?->isClient() ? [
                    'organisation' => $request->user()->organisationName(),
                    'role' => $request->user()->clientRole(),
                    'may' => collect(['pay', 'approve', 'sign', 'accept', 'api_keys', 'feedback', 'licences', 'manage_people'])
                        ->filter(fn (string $ability) => $request->user()->canAsClient($ability))
                        ->values(),
                    // Signing and paying wait for two-step sign in (CL20).
                    'needsTwoFactor' => $request->user()->needsTwoFactorToCommit(),
                ] : null,
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
                // Which response the messages came with. A partial reload keeps
                // the last page's flash without sending it again, and the
                // toaster shows a message only for an id it has not seen.
                'id' => fn () => collect(['success', 'error', 'info', 'warning'])->contains(fn (string $key) => $request->session()->has($key))
                    ? (string) Str::uuid()
                    : null,
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
                'registrationSent' => fn () => $request->session()->get('registrationSent'),
                'checkoutCodeSentTo' => fn () => $request->session()->get('checkoutCodeSentTo'),
                'issuedLicenceKey' => fn () => $request->session()->get('issuedLicenceKey'),
            ],

            'ziggy' => fn () => [
                'location' => $request->url(),
            ],
        ];
    }
}
