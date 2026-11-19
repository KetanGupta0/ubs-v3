<?php

namespace App\Http\Middleware;

use App\Support\Security\ContentSecurityPolicy;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * The headers every response carries (Step I): no sniffing a file's type, no
 * framing by another site, a short referrer, no location, camera and
 * microphone for this site only (proctoring and voice notes), HTTPS remembered
 * in production, and on every page the content security policy.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        // Before anything renders, so the page's own tags carry it.
        $nonce = Vite::useCspNonce();

        $response = $next($request);
        $headers = $response->headers;

        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('X-Frame-Options', 'SAMEORIGIN');
        $headers->set('Permissions-Policy', 'camera=(self), microphone=(self), geolocation=(), usb=()');
        $headers->set('Cross-Origin-Opener-Policy', 'same-origin-allow-popups');

        if (app()->isProduction() && $request->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000');
        }

        if ($this->wantsPolicy($response)) {
            $headers->set(
                config('company.security.csp_report_only') ? 'Content-Security-Policy-Report-Only' : 'Content-Security-Policy',
                ContentSecurityPolicy::header($nonce),
            );
        }

        return $response;
    }

    /**
     * Pages only: a PDF or a download is left alone, and so is the debug page
     * that explains a fault in development, which runs its own inline scripts.
     */
    protected function wantsPolicy(Response $response): bool
    {
        if (! config('company.security.csp')) {
            return false;
        }

        if (config('app.debug') && $response->getStatusCode() >= 500) {
            return false;
        }

        return str_starts_with((string) $response->headers->get('Content-Type'), 'text/html');
    }
}
