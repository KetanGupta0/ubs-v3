/**
 * Locks body scroll while an overlay is open.
 *
 * Reference counted, so two stacked overlays do not fight over the style and
 * the first one to close does not unlock the page behind the second. The
 * scrollbar width is compensated to stop the layout jumping on desktop.
 */
import { watch, onScopeDispose } from 'vue';

let locks = 0;
let previousOverflow = '';
let previousPadding = '';

function lock() {
    if (locks++ > 0) return;

    const gap = window.innerWidth - document.documentElement.clientWidth;
    previousOverflow = document.body.style.overflow;
    previousPadding = document.body.style.paddingRight;
    document.body.style.overflow = 'hidden';
    if (gap > 0) {
        document.body.style.paddingRight = `${gap}px`;
    }
}

function unlock() {
    if (locks === 0) return;
    if (--locks > 0) return;

    document.body.style.overflow = previousOverflow;
    document.body.style.paddingRight = previousPadding;
}

export function useScrollLock(isOpen) {
    let held = false;

    const apply = (open) => {
        if (open && !held) {
            held = true;
            lock();
        } else if (!open && held) {
            held = false;
            unlock();
        }
    };

    watch(isOpen, apply, { immediate: true });

    onScopeDispose(() => apply(false));
}
