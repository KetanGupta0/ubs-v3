<?php

namespace App\Support\Monitoring;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Route;
use PDOException;
use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\EventHint;
use Sentry\Stacktrace;
use Throwable;

/**
 * What an error report may carry to Sentry: the fault, never the person.
 *
 * The configuration already leaves out the request body, cookies and the IP
 * address. This takes out what remains that could name somebody: the query
 * string, every header but the browser's name, values in SQL errors, the
 * arguments in each stack frame, addresses, long numbers and tokens in any
 * message, and route parameters that are not plain numbers (an unsubscribe
 * address, a reset token, a signature).
 */
class ErrorReports
{
    /** Headers worth keeping: which browser, and what kind of request. */
    protected const KEPT_HEADERS = ['user-agent', 'content-type', 'accept', 'x-inertia'];

    public static function beforeSend(Event $event, ?EventHint $hint = null): ?Event
    {
        $event->setUser(null);
        $event->setRequest(self::request($event->getRequest()));

        foreach ($event->getExceptions() as $exception) {
            $exception->setValue(self::scrub($exception->getValue()));

            if ($exception->getStacktrace()) {
                self::withoutArguments($exception->getStacktrace());
            }
        }

        if ($hint?->exception instanceof QueryException) {
            // The driver's message repeats the values ("Duplicate entry
            // 'Meera Iyer'"); the statement with its placeholders, the error
            // code and the names of keys and columns are enough to find it.
            foreach ($event->getExceptions() as $exception) {
                if ($exception->getType() === QueryException::class) {
                    $exception->setValue(self::query($hint->exception));
                } elseif (is_a($exception->getType(), PDOException::class, true)) {
                    $exception->setValue(self::withoutLiterals($exception->getValue()));
                }
            }
        }

        if ($event->getStacktrace()) {
            self::withoutArguments($event->getStacktrace());
        }

        if ($event->getMessage() !== null) {
            $event->setMessage(self::scrub($event->getMessage()), [], self::scrub((string) $event->getMessageFormatted()) ?: null);
        }

        $event->setExtra(self::scrubArray($event->getExtra()));

        return $event;
    }

    public static function beforeBreadcrumb(Breadcrumb $breadcrumb): ?Breadcrumb
    {
        if ($breadcrumb->getMessage() !== null) {
            $breadcrumb = $breadcrumb->withMessage(self::scrub($breadcrumb->getMessage()));
        }

        foreach ($breadcrumb->getMetadata() as $key => $value) {
            $breadcrumb = is_scalar($value)
                ? $breadcrumb->withMetadata($key, self::scrub((string) $value))
                : $breadcrumb->withoutMetadata($key);
        }

        return $breadcrumb;
    }

    /** Addresses, phone and card length numbers, and long tokens, taken out of free text. */
    public static function scrub(string $text): string
    {
        $text = preg_replace('/[^\s@<>()\[\]"\',;:]+@[^\s@<>()\[\]"\',;:]+\.[a-z]{2,}/i', '[email]', $text) ?? '';
        // A token: twenty or more letters, digits and the usual separators, with a digit in it.
        $text = preg_replace('/\b(?=[A-Za-z0-9_\-]*\d)[A-Za-z0-9_\-]{20,}\b/', '[token]', $text) ?? '';

        // A phone or card number: ten digits or more in a run, one written with
        // its country code, or a mobile as two groups of five. Dates are left.
        return preg_replace('/\+\d[\d\s\-]{8,}\d|\b\d{10,}\b|\b\d{5}\s\d{5}\b/', '[number]', $text) ?? '';
    }

    /**
     * A path a browser reported, with anything that looks like a token or an
     * encoded address replaced. Only the path: the query string never comes.
     */
    public static function path(string $path): string
    {
        $path = '/'.ltrim((string) parse_url($path, PHP_URL_PATH), '/');

        return collect(explode('/', $path))
            ->map(fn (string $segment) => strlen($segment) >= 16 && preg_match('/\d/', $segment) && preg_match('/[A-Za-z]/', $segment) ? '[token]' : $segment)
            ->map(fn (string $segment) => str_contains($segment, '@') ? '[email]' : $segment)
            ->implode('/');
    }

    /** @param  array<string, mixed>  $request */
    protected static function request(array $request): array
    {
        if ($request === []) {
            return [];
        }

        $kept = ['method' => $request['method'] ?? null, 'url' => self::url((string) ($request['url'] ?? ''))];

        $headers = collect($request['headers'] ?? [])
            ->filter(fn ($value, string $name) => in_array(strtolower($name), self::KEPT_HEADERS, true))
            ->all();

        if ($headers) {
            $kept['headers'] = $headers;
        }

        return array_filter($kept, fn ($value) => $value !== null && $value !== '');
    }

    /**
     * The address without its query, and with any route parameter that is
     * not a plain number put back as its name: /unsubscribe/{address}, not
     * the address itself.
     */
    protected static function url(string $url): string
    {
        if ($url === '') {
            return '';
        }

        $parts = parse_url($url);
        $base = isset($parts['host']) ? ($parts['scheme'] ?? 'https').'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '') : '';
        $path = $parts['path'] ?? '/';

        try {
            $route = Route::current();
        } catch (Throwable) {
            $route = null;
        }

        if ($route) {
            foreach ($route->originalParameters() as $name => $value) {
                if (is_string($value) && $value !== '' && ! ctype_digit($value)) {
                    $path = str_replace('/'.$value, '/{'.$name.'}', $path);
                }
            }
        }

        return $base.self::path($path);
    }

    /** Quoted values out, quoted names (a key, a column, a table) kept. */
    protected static function withoutLiterals(string $text): string
    {
        return preg_replace_callback(
            "/'([^']*)'/",
            fn (array $match) => preg_match('/^[A-Za-z_][A-Za-z0-9_.]*$/', $match[1]) ? $match[0] : "'?'",
            $text,
        ) ?? '';
    }

    protected static function query(QueryException $e): string
    {
        $code = $e->errorInfo[0] ?? $e->getCode();

        return trim('SQLSTATE['.$code.'] '.($e->errorInfo[1] ?? '')).' on: '.self::scrub($e->getSql());
    }

    protected static function withoutArguments(Stacktrace $stacktrace): void
    {
        foreach ($stacktrace->getFrames() as $frame) {
            $frame->setVars([]);
        }
    }

    /**
     * @param  array<mixed>  $values
     * @return array<mixed>
     */
    protected static function scrubArray(array $values): array
    {
        return collect($values)
            ->map(fn ($value) => match (true) {
                is_string($value) => self::scrub($value),
                is_array($value) => self::scrubArray($value),
                default => $value,
            })
            ->all();
    }
}
