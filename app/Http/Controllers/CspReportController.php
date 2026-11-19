<?php

namespace App\Http\Controllers;

use App\Support\Monitoring\ErrorReports;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Sentry\Severity;
use Sentry\State\Scope;

use function Sentry\captureMessage;
use function Sentry\withScope;

/**
 * Something the content security policy blocked, reported by the browser
 * (Step I). Most are an extension's doing; the one that matters is a service
 * we rely on, such as the payment window, which then shows in the log and in
 * Sentry the moment it first happens. Only where it happened and what was
 * blocked are kept: a path, and the origin of the blocked address.
 */
class CspReportController extends Controller
{
    public const DAILY_LIMIT = 300;

    public function __invoke(Request $request): Response
    {
        $report = json_decode($request->getContent(), true)['csp-report'] ?? null;

        if (! is_array($report)) {
            return response()->noContent();
        }

        $key = 'csp-reports:'.today()->toDateString();
        Cache::add($key, 0, now()->endOfDay());

        if (Cache::increment($key) > self::DAILY_LIMIT) {
            return response()->noContent();
        }

        $directive = str((string) ($report['effective-directive'] ?? $report['violated-directive'] ?? 'unknown'))->before(' ')->limit(60, '')->toString();
        $blocked = self::origin((string) ($report['blocked-uri'] ?? ''));

        // An extension injecting into the page is not ours to fix.
        if (preg_match('/^(chrome|moz|safari|ms-browser)-extension/', $blocked)) {
            return response()->noContent();
        }

        $context = [
            'directive' => $directive,
            'blocked' => $blocked,
            'page' => ErrorReports::path((string) ($report['document-uri'] ?? '/')),
            'source' => self::origin((string) ($report['source-file'] ?? '')),
            'disposition' => (string) ($report['disposition'] ?? 'enforce'),
        ];

        $message = "Content security policy blocked {$blocked} under {$directive}";
        Log::warning($message, $context);

        withScope(function (Scope $scope) use ($message, $context) {
            $scope->setTag('source', 'csp');
            $scope->setContext('csp', $context);
            $scope->setFingerprint(['csp', $context['directive'], $context['blocked']]);
            captureMessage($message, Severity::warning());
        });

        return response()->noContent();
    }

    /** The scheme and host of an address, or the keyword the browser gave (inline, eval, data). */
    protected static function origin(string $uri): string
    {
        $parts = parse_url($uri);

        if (! is_array($parts) || ! isset($parts['scheme'])) {
            return str($uri)->limit(20, '')->toString() ?: 'inline';
        }

        return $parts['scheme'].(isset($parts['host']) ? '://'.$parts['host'] : ':');
    }
}
