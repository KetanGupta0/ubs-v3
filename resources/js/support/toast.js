/**
 * Toast queue.
 *
 * A module level reactive array rather than a store library, because the only
 * thing that needs to share it is the single <UiToaster /> mounted in the app
 * shell. Any component can call `toast.success(...)` without prop drilling.
 */
import { reactive } from 'vue';

export const toasts = reactive([]);

let nextId = 0;

export function dismiss(id) {
    const index = toasts.findIndex((t) => t.id === id);
    if (index !== -1) toasts.splice(index, 1);
}

export function push(message, options = {}) {
    const id = ++nextId;
    const toast = {
        id,
        message,
        tone: options.tone ?? 'neutral',
        title: options.title ?? null,
        action: options.action ?? null,
        duration: options.duration ?? (options.tone === 'danger' ? 8000 : 5000),
    };

    toasts.push(toast);

    // Errors stay until dismissed when duration is explicitly 0.
    if (toast.duration > 0) {
        setTimeout(() => dismiss(id), toast.duration);
    }

    return id;
}

export const toast = {
    success: (message, options) => push(message, { ...options, tone: 'success' }),
    error: (message, options) => push(message, { ...options, tone: 'danger' }),
    warning: (message, options) => push(message, { ...options, tone: 'warning' }),
    info: (message, options) => push(message, { ...options, tone: 'brand' }),
    dismiss,
};
