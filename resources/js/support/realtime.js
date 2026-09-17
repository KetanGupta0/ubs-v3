/**
 * The socket, if there is one.
 *
 * Echo and pusher-js are loaded on demand rather than in the app bundle,
 * because most screens are not chat and a websocket client is not free. The
 * import happens the first time somebody opens a conversation.
 *
 * Everything here is written so that failing is normal. A hotel network, a
 * corporate proxy or a plain missing Reverb server all end in the same place:
 * `live` stays false and the caller polls instead. Chat that degrades to a
 * three second delay is chat; chat that throws is not.
 */
import { ref } from 'vue';

/** Whether the socket is connected right now. Screens read this to show a dot. */
export const live = ref(false);

let echo = null;
let starting = null;

export function isConfigured() {
    return Boolean(import.meta.env.VITE_REVERB_APP_KEY);
}

export async function connect() {
    if (echo) return echo;
    if (!isConfigured()) return null;
    if (starting) return starting;

    starting = (async () => {
        try {
            const [{ default: Echo }, { default: Pusher }] = await Promise.all([
                import('laravel-echo'),
                import('pusher-js'),
            ]);

            window.Pusher = Pusher;

            echo = new Echo({
                broadcaster: 'reverb',
                key: import.meta.env.VITE_REVERB_APP_KEY,
                wsHost: import.meta.env.VITE_REVERB_HOST || window.location.hostname,
                wsPort: Number(import.meta.env.VITE_REVERB_PORT || 8080),
                wssPort: Number(import.meta.env.VITE_REVERB_PORT || 443),
                forceTLS: (import.meta.env.VITE_REVERB_SCHEME || 'http') === 'https',
                enabledTransports: ['ws', 'wss'],
                // One reconnect storm on a flaky network is enough.
                activityTimeout: 30000,
                pongTimeout: 10000,
            });

            const connection = echo.connector?.pusher?.connection;

            connection?.bind('connected', () => { live.value = true; });
            connection?.bind('disconnected', () => { live.value = false; });
            connection?.bind('unavailable', () => { live.value = false; });
            connection?.bind('failed', () => { live.value = false; });
            connection?.bind('error', () => { live.value = false; });

            return echo;
        } catch (error) {
            // Deliberately quiet. The poll covers for it, and a console full of
            // red on a screen that is working is its own kind of bug report.
            live.value = false;
            echo = null;

            return null;
        }
    })();

    return starting;
}

export function leave(channelName) {
    echo?.leave(channelName);
}
