/**
 * Theme handling.
 *
 * Three states are supported: 'light', 'dark' and 'system'. The choice is kept
 * in localStorage so it survives navigation, and a matchMedia listener keeps
 * 'system' honest when the operating system flips theme while the tab is open.
 */
const STORAGE_KEY = 'ubs.theme';

export function storedPreference() {
    try {
        return localStorage.getItem(STORAGE_KEY) || 'system';
    } catch {
        // Private browsing or blocked storage. Fall back to following the OS.
        return 'system';
    }
}

export function resolveTheme(preference) {
    if (preference === 'light' || preference === 'dark') {
        return preference;
    }

    return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
}

export function applyTheme(preference) {
    const resolved = resolveTheme(preference);
    const root = document.documentElement;

    root.classList.toggle('dark', resolved === 'dark');
    root.style.colorScheme = resolved;

    const meta = document.querySelector('meta[name="theme-color"]');
    if (meta) {
        meta.setAttribute('content', resolved === 'dark' ? '#0B1020' : '#FFFFFF');
    }

    return resolved;
}

export function setPreference(preference) {
    try {
        localStorage.setItem(STORAGE_KEY, preference);
    } catch {
        // Storage unavailable. The theme still applies for this page view.
    }

    return applyTheme(preference);
}

export function applyStoredTheme() {
    const preference = storedPreference();
    applyTheme(preference);

    window
        .matchMedia('(prefers-color-scheme: dark)')
        .addEventListener('change', () => {
            if (storedPreference() === 'system') {
                applyTheme('system');
            }
        });
}
