/**
 * The lockdown on a proctored quiz (§9.1a).
 *
 * A page cannot take control of a computer, so this is what a page can do:
 * keep itself in full screen, cover the questions the moment the student
 * leaves (full screen, the tab or the window), switch off copying, pasting,
 * the right click menu and the developer tools' shortcuts, and say each time
 * that it was noticed. Every one of those is reported, and the server counts
 * them; nothing here decides anything.
 *
 * In Safe Exam Browser the computer is locked already and the window is full
 * screen by itself, so full screen is not asked for there.
 */
import { onBeforeUnmount, onMounted, ref } from 'vue';

const SHORTCUTS = [
    // [test, label, what]
    [(e) => e.key === 'F12', 'F12', 'shortcut'],
    [(e) => (e.ctrlKey || e.metaKey) && e.shiftKey && ['i', 'j', 'c', 'k'].includes(e.key.toLowerCase()), (e) => `Ctrl+Shift+${e.key.toUpperCase()}`, 'shortcut'],
    [(e) => e.metaKey && e.altKey && ['i', 'j', 'c'].includes(e.key.toLowerCase()), (e) => `Cmd+Alt+${e.key.toUpperCase()}`, 'shortcut'],
    [(e) => (e.ctrlKey || e.metaKey) && ['u', 's'].includes(e.key.toLowerCase()), (e) => `Ctrl+${e.key.toUpperCase()}`, 'shortcut'],
    [(e) => (e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'p', 'Ctrl+P', 'print'],
    // Back and forward would leave the quiz without the page ever closing.
    [(e) => e.altKey && ['ArrowLeft', 'ArrowRight'].includes(e.key), (e) => `Alt+${e.key.replace('Arrow', '')}`, 'shortcut'],
    [(e) => ['BrowserBack', 'BrowserForward'].includes(e.key), (e) => e.key, 'shortcut'],
];

const WORDS = {
    copy: 'Copying is switched off in this quiz.',
    cut: 'Cutting is switched off in this quiz.',
    paste: 'Pasting is switched off in this quiz.',
    menu: 'The right click menu is switched off in this quiz.',
    drag: 'Dragging is switched off in this quiz.',
    shortcut: 'That shortcut is switched off in this quiz.',
    print: 'Printing is switched off in this quiz.',
};

export function useLockdown({ enabled, needsFullscreen = true, report, flush }) {
    const covered = ref(false);
    const why = ref(null);
    const warning = ref(null);

    let awaySince = null;
    let warningTimer = null;

    const inFullscreen = () => !needsFullscreen || Boolean(document.fullscreenElement);
    const reason = () => {
        if (!inFullscreen()) return 'fullscreen';
        if (document.visibilityState === 'hidden') return 'tab';
        if (!document.hasFocus()) return 'window';

        return null;
    };

    /** Where the page is now, sent with every heartbeat. */
    const state = () => ({ fullscreen: inFullscreen(), focused: document.visibilityState === 'visible' && document.hasFocus() });

    function check() {
        const now = reason();

        if (now && !covered.value) {
            covered.value = true;
            why.value = now;
            awaySince = Date.now();
            report({ type: 'focus_lost', why: now });
            flush(true);
        } else if (now) {
            why.value = now;
        } else if (covered.value) {
            covered.value = false;
            why.value = null;
            report({ type: 'focus_back', seconds: Math.round((Date.now() - (awaySince ?? Date.now())) / 1000) });
            awaySince = null;
            flush();
        }
    }

    function warn(what, key = null) {
        report(key ? { type: 'blocked', what, key } : { type: 'blocked', what });
        warning.value = `${WORDS[what]} It has been noted.`;
        clearTimeout(warningTimer);
        warningTimer = setTimeout(() => (warning.value = null), 4000);
    }

    /* Browsers allow full screen only from a click: the cover's button is that click. */
    async function returnToFullscreen() {
        try {
            if (needsFullscreen && !document.fullscreenElement) {
                await document.documentElement.requestFullscreen({ navigationUI: 'hide' });
            }
            // Chrome and Edge: Esc has to be held down to leave, rather than tapped.
            await navigator.keyboard?.lock?.(['Escape']);
        } catch {
            // Refused or not supported: the cover stays, and says so.
        }
        window.focus();
        check();
    }

    async function release() {
        detach();
        try {
            navigator.keyboard?.unlock?.();
            if (document.fullscreenElement) await document.exitFullscreen();
        } catch {
            // Already out.
        }
    }

    const onFullscreen = () => {
        report({ type: document.fullscreenElement ? 'fullscreen_enter' : 'fullscreen_exit' });
        check();
    };
    const onCopy = (event) => { event.preventDefault(); warn(event.type); };
    const onMenu = (event) => { event.preventDefault(); warn('menu'); };
    const onDrag = (event) => { event.preventDefault(); warn('drag'); };
    const onSelect = (event) => {
        // Their own answer can be selected to edit it; the questions cannot.
        if (!event.target.closest?.('textarea, input')) event.preventDefault();
    };
    const onKey = (event) => {
        for (const [test, label, what] of SHORTCUTS) {
            if (test(event)) {
                event.preventDefault();
                event.stopPropagation();
                warn(what, typeof label === 'function' ? label(event) : label);

                return;
            }
        }
    };
    // The mouse's own back and forward buttons.
    const onMouseBack = (event) => {
        if (event.button === 3 || event.button === 4) {
            event.preventDefault();
            warn('shortcut', event.button === 3 ? 'MouseBack' : 'MouseForward');
        }
    };
    const onLeave = (event) => {
        // The browser's own "Leave site?" question. Closing anyway is noticed
        // by the gap, and the clock carries on on the server.
        event.preventDefault();
        event.returnValue = '';
    };

    function attach() {
        document.addEventListener('fullscreenchange', onFullscreen);
        document.addEventListener('visibilitychange', check);
        window.addEventListener('blur', check);
        window.addEventListener('focus', check);
        document.addEventListener('copy', onCopy, true);
        document.addEventListener('cut', onCopy, true);
        document.addEventListener('paste', onCopy, true);
        document.addEventListener('contextmenu', onMenu, true);
        document.addEventListener('dragstart', onDrag, true);
        document.addEventListener('selectstart', onSelect, true);
        document.addEventListener('keydown', onKey, true);
        window.addEventListener('mouseup', onMouseBack, true);
        window.addEventListener('beforeunload', onLeave);
    }

    function detach() {
        document.removeEventListener('fullscreenchange', onFullscreen);
        document.removeEventListener('visibilitychange', check);
        window.removeEventListener('blur', check);
        window.removeEventListener('focus', check);
        document.removeEventListener('copy', onCopy, true);
        document.removeEventListener('cut', onCopy, true);
        document.removeEventListener('paste', onCopy, true);
        document.removeEventListener('contextmenu', onMenu, true);
        document.removeEventListener('dragstart', onDrag, true);
        document.removeEventListener('selectstart', onSelect, true);
        document.removeEventListener('keydown', onKey, true);
        window.removeEventListener('mouseup', onMouseBack, true);
        window.removeEventListener('beforeunload', onLeave);
        clearTimeout(warningTimer);
    }

    onMounted(() => {
        if (!enabled) return;
        attach();
        if (document.fullscreenElement) navigator.keyboard?.lock?.(['Escape'])?.catch?.(() => {});
        // Opened outside full screen (a reload, a crash, another computer): that is leaving too.
        check();
    });

    onBeforeUnmount(() => enabled && detach());

    return { covered, why, warning, state, returnToFullscreen, release };
}
