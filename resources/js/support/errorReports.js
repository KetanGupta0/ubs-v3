/**
 * A fault in this browser, reported to our own server (Step I), which logs it
 * and passes it to Sentry once Sentry is set up. No third party script and no
 * key in the page, and only the fault goes: the message, the stack, which
 * component, and the path without its query. Nothing anybody typed.
 *
 * A page load reports a handful at most, each once, and reporting can never
 * break the page it is reporting on.
 */
const MAX_PER_PAGE_LOAD = 5;
const reported = new Set();

/* Faults that are the network or the visitor's own browser, not our code. */
const NOT_OURS = [
    /Failed to fetch/i,
    /NetworkError/i,
    /Load failed/i,
    /AbortError/i,
    /ResizeObserver loop/i,
    /Importing a module script failed/i,
];

function xsrfToken() {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);

    return match ? decodeURIComponent(match[1]) : '';
}

export function reportError(error, where = null) {
    try {
        const message = String(error?.message ?? error ?? 'Unknown error').slice(0, 500);

        if (NOT_OURS.some((pattern) => pattern.test(message))) return;

        const key = `${message}|${where ?? ''}`;
        if (reported.has(key) || reported.size >= MAX_PER_PAGE_LOAD) return;
        reported.add(key);

        fetch('/client-errors', {
            method: 'POST',
            keepalive: true,
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': xsrfToken(),
            },
            body: JSON.stringify({
                message,
                stack: String(error?.stack ?? '').slice(0, 4000),
                where: where ? String(where).slice(0, 200) : null,
                page: window.location.pathname,
            }),
        }).catch(() => {});
    } catch {
        // Reporting a fault must never become one.
    }
}

export function installErrorReports(app) {
    app.config.errorHandler = (error, instance, info) => {
        const component = instance?.$options?.__name ?? instance?.$options?.name ?? 'a component';
        reportError(error, `${component} (${info})`);
        console.error(error);
    };

    window.addEventListener('error', (event) => {
        // Only scripts from this site: an extension's fault is not ours.
        if (event.error && (!event.filename || event.filename.startsWith(window.location.origin))) {
            reportError(event.error, 'page');
        }
    });

    window.addEventListener('unhandledrejection', (event) => {
        if (event.reason instanceof Error) reportError(event.reason, 'promise');
    });
}
