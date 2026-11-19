<?php

namespace App\Support\Security;

use Illuminate\Support\Facades\Vite;

/**
 * Where a page may load scripts, frames and connections from (Step I).
 *
 * Scripts come from this site, from a nonce on the two inline ones (the theme,
 * set before first paint, and the route list), and from the two services the
 * pages load on purpose: Razorpay's checkout and Cloudflare's human check.
 * Frames are lesson videos, the checkout and the human check. Styles may be
 * inline, because Vue binds them and the checkout injects its own; the risk
 * that guards against is small next to scripts. Every violation is reported
 * to /csp-reports, so a blocked payment window shows up straight away.
 */
class ContentSecurityPolicy
{
    public const REPORT_PATH = '/csp-reports';

    public static function header(string $nonce): string
    {
        $self = "'self'";

        $directives = [
            'default-src' => [$self],
            'base-uri' => [$self],
            'object-src' => ["'none'"],
            'frame-ancestors' => [$self],
            'form-action' => [$self, 'https://accounts.google.com', 'https://*.razorpay.com'],
            'script-src' => [$self, "'nonce-{$nonce}'", 'https://*.razorpay.com', 'https://challenges.cloudflare.com'],
            'style-src' => [$self, "'unsafe-inline'"],
            'img-src' => [$self, 'data:', 'blob:', 'https:'],
            'font-src' => [$self, 'data:'],
            'media-src' => [$self, 'blob:', 'data:'],
            'connect-src' => [$self, 'https://*.razorpay.com', 'https://*.pusher.com', 'wss://*.pusher.com', ...self::sockets()],
            'frame-src' => [
                $self,
                'https://www.youtube.com',
                'https://www.youtube-nocookie.com',
                'https://player.vimeo.com',
                'https://*.razorpay.com',
                'https://challenges.cloudflare.com',
            ],
            'worker-src' => [$self, 'blob:'],
            'manifest-src' => [$self],
            'report-uri' => [self::REPORT_PATH],
        ];

        // In development the scripts and styles come from Vite's own server.
        if (Vite::isRunningHot()) {
            $hot = rtrim((string) file_get_contents(Vite::hotFile()));
            $directives['script-src'][] = $hot;
            $directives['style-src'][] = $hot;
            $directives['connect-src'][] = $hot;
            $directives['connect-src'][] = preg_replace('#^http#', 'ws', $hot);
        }

        $policy = collect($directives)
            ->map(fn (array $sources, string $name) => trim($name.' '.implode(' ', array_unique($sources))))
            ->values();

        if (app()->isProduction()) {
            $policy->push('upgrade-insecure-requests');
        }

        return $policy->implode('; ');
    }

    /** The socket server when it is Reverb (development), not the hosted service. @return array<int, string> */
    protected static function sockets(): array
    {
        if (config('broadcasting.default') !== 'reverb') {
            return [];
        }

        $options = config('broadcasting.connections.reverb.options', []);
        $host = $options['host'] ?? null;

        if (! $host) {
            return [];
        }

        $scheme = ($options['scheme'] ?? 'https') === 'https' ? 'wss' : 'ws';
        $port = $options['port'] ?? null;

        return [$scheme.'://'.$host.($port ? ':'.$port : '')];
    }
}
