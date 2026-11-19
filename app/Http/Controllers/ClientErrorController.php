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
 * A fault in somebody's browser, reported by the page itself.
 *
 * It comes through our own server rather than a script from Sentry, so the
 * page carries no key and loads nothing more, and only the fault is passed
 * on: the message, the stack, which component and which path, scrubbed like
 * every other report. Every bug so far has shown only in a browser (CLAUDE.md),
 * which is why this exists at all.
 */
class ClientErrorController extends Controller
{
    /** Reports passed on in a day from every browser together. */
    public const DAILY_LIMIT = 300;

    public function __invoke(Request $request): Response
    {
        $report = $request->validate([
            'message' => ['required', 'string', 'max:500'],
            'stack' => ['nullable', 'string', 'max:4000'],
            'where' => ['nullable', 'string', 'max:200'],
            'page' => ['nullable', 'string', 'max:300'],
        ]);

        // A broken release can make every visitor report the same fault; a
        // day's worth is plenty to find it, and the error plan's quota is not.
        $key = 'client-errors:'.today()->toDateString();
        Cache::add($key, 0, now()->endOfDay());

        if (Cache::increment($key) > self::DAILY_LIMIT) {
            return response()->noContent();
        }

        $message = 'In the browser: '.ErrorReports::scrub($report['message']);
        $context = [
            'page' => ErrorReports::path((string) ($report['page'] ?? '/')),
            'where' => ErrorReports::scrub((string) ($report['where'] ?? '')),
            'stack' => ErrorReports::scrub((string) ($report['stack'] ?? '')),
            'role' => $request->user()?->role?->value ?? 'visitor',
        ];

        Log::warning($message, $context);

        withScope(function (Scope $scope) use ($message, $context) {
            $scope->setTag('source', 'browser');
            $scope->setTag('role', $context['role']);
            $scope->setContext('browser_error', $context);
            $scope->setFingerprint(['browser', $context['where'] ?: 'window', $message]);
            captureMessage($message, Severity::error());
        });

        return response()->noContent();
    }
}
