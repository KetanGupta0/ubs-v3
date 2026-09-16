/**
 * Keeps Tab focus inside an open overlay and restores it on close.
 *
 * Written by hand rather than pulled from a dependency because the behaviour
 * is small and every modal, drawer and command palette in the app needs it.
 */
import { watch, nextTick, onScopeDispose } from 'vue';

const FOCUSABLE = [
    'a[href]',
    'button:not([disabled])',
    'input:not([disabled]):not([type="hidden"])',
    'select:not([disabled])',
    'textarea:not([disabled])',
    '[tabindex]:not([tabindex="-1"])',
].join(',');

export function useFocusTrap(containerRef, isOpen) {
    let restoreTo = null;

    const focusable = () => {
        const root = containerRef.value;
        if (!root) return [];

        return Array.from(root.querySelectorAll(FOCUSABLE)).filter(
            (el) => el.offsetParent !== null || el === document.activeElement,
        );
    };

    const onKeydown = (event) => {
        if (event.key !== 'Tab') return;

        const items = focusable();
        if (items.length === 0) {
            event.preventDefault();
            return;
        }

        const first = items[0];
        const last = items[items.length - 1];
        const active = document.activeElement;

        if (event.shiftKey && (active === first || !containerRef.value?.contains(active))) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && active === last) {
            event.preventDefault();
            first.focus();
        }
    };

    const stop = () => {
        document.removeEventListener('keydown', onKeydown, true);
        restoreTo?.focus?.();
        restoreTo = null;
    };

    watch(isOpen, async (open) => {
        if (open) {
            restoreTo = document.activeElement;
            document.addEventListener('keydown', onKeydown, true);
            await nextTick();
            (focusable()[0] ?? containerRef.value)?.focus?.();
        } else {
            stop();
        }
    });

    onScopeDispose(stop);
}
