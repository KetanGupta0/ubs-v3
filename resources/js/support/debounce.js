/**
 * Call a function once the caller has stopped calling it for `wait` ms.
 *
 * Used for search boxes that reload on typing: without it, "invoice" is seven
 * requests, six of which are already stale by the time they answer.
 */
export function debounce(fn, wait = 300) {
    let timer;

    return (...args) => {
        clearTimeout(timer);
        timer = setTimeout(() => fn(...args), wait);
    };
}
