/**
 * One open conversation, kept current.
 *
 * Two ways in and one way through: a websocket when there is one, a poll when
 * there is not, and a single `apply` that both feed. Anything else and the two
 * paths disagree about what a message looks like, which shows up as "the image
 * only renders after a refresh".
 *
 * Created once, for the life of the screen, and told which room it is on. The
 * room changes when somebody picks another thread; the subscription and the
 * poll are torn down and rebuilt here rather than by the page, so a person who
 * clicks through six conversations does not end up holding six of each.
 */
import { ref, computed, onBeforeUnmount, watch } from 'vue';
import { connect, live, leave } from '@/support/realtime';

/** How often to ask, when asking is all we have. */
const POLL_MS = 3000;

/** And how often when the socket is up, purely as a safety net. */
const SLOW_POLL_MS = 30000;

/**
 * @param {() => number|null} currentId  which conversation is on screen
 * @param {() => Array} initialMessages  what the page was rendered with
 * @param {() => number|null} me  the signed in user's id
 */
export function useConversation(currentId, initialMessages, me) {
    const messages = ref([...initialMessages()]);
    const typing = ref([]);
    const present = ref([]);
    const hasMore = ref(true);
    const loadingOlder = ref(false);

    let channelName = null;
    let channel = null;
    let poller = null;
    let typingTimers = {};

    const lastId = computed(() =>
        messages.value.length ? Math.max(...messages.value.map((m) => m.id)) : 0,
    );

    /** Add or replace, never duplicate: the socket and the poll can both bring the same one. */
    function apply(incoming) {
        for (const message of incoming) {
            const at = messages.value.findIndex((m) => m.id === message.id);

            if (at === -1) {
                messages.value.push({ ...message, mine: message.sender.id === me() });
            } else {
                // Keep the ticks we already know about; a broadcast cannot know
                // who has read a message it is in the middle of delivering.
                messages.value[at] = {
                    ...messages.value[at],
                    ...message,
                    mine: message.sender.id === me(),
                    readBy: message.readBy || messages.value[at].readBy,
                    deliveredTo: message.deliveredTo || messages.value[at].deliveredTo,
                };
            }
        }

        messages.value.sort((a, b) => a.id - b.id);
    }

    function markRemoved(id) {
        const at = messages.value.findIndex((m) => m.id === id);

        if (at !== -1) {
            messages.value[at] = { ...messages.value[at], removed: true, body: null, mediaUrl: null };
        }
    }

    function markRead(payload) {
        if (payload.userId === me()) return;

        for (const id of payload.messageIds) {
            const at = messages.value.findIndex((m) => m.id === id);

            if (at !== -1 && messages.value[at].mine) {
                messages.value[at] = {
                    ...messages.value[at],
                    readBy: (messages.value[at].readBy || 0) + 1,
                };
            }
        }
    }

    async function poll() {
        const id = currentId();

        if (!id) return;

        try {
            const response = await fetch(`/chat/${id}/messages?after=${lastId.value}`, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            });

            if (response.ok && currentId() === id) {
                apply((await response.json()).messages || []);
            }
        } catch {
            // A failed poll is a network blip. The next one covers it.
        }
    }

    function schedule() {
        clearInterval(poller);

        if (currentId()) {
            poller = setInterval(poll, live.value ? SLOW_POLL_MS : POLL_MS);
        }
    }

    async function loadOlder() {
        const id = currentId();

        if (loadingOlder.value || !id || !messages.value.length) return;

        loadingOlder.value = true;

        try {
            const response = await fetch(`/chat/${id}/messages?before=${messages.value[0].id}`, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });

            if (response.ok) {
                const data = await response.json();
                apply(data.messages || []);
                hasMore.value = Boolean(data.hasMore);
            }
        } finally {
            loadingOlder.value = false;
        }
    }

    async function tellThemIAmReading() {
        const id = currentId();

        if (!id) return;

        try {
            await fetch(`/chat/${id}/read`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                },
                credentials: 'same-origin',
            });
        } catch {
            // Nothing to do. The next open marks it again.
        }
    }

    /** Tell the room somebody is typing. A whisper never reaches the server. */
    function whisperTyping(name) {
        channel?.whisper?.('typing', { id: me(), name });
    }

    function noteTyping({ id, name }) {
        if (id === me()) return;

        if (!typing.value.some((person) => person.id === id)) {
            typing.value.push({ id, name });
        }

        clearTimeout(typingTimers[id]);

        // Nobody types for four seconds without sending something, and a
        // typing indicator that sticks is worse than none.
        typingTimers[id] = setTimeout(() => {
            typing.value = typing.value.filter((person) => person.id !== id);
        }, 4000);
    }

    function unsubscribe() {
        if (channelName) leave(channelName);

        channel = null;
        channelName = null;
        present.value = [];
        typing.value = [];
        Object.values(typingTimers).forEach(clearTimeout);
        typingTimers = {};
    }

    async function subscribe(id) {
        const echo = await connect();

        // Somebody may have clicked another thread while the import was in
        // flight, in which case this subscription is already stale.
        if (!echo || currentId() !== id) return;

        channelName = `conversation.${id}`;

        channel = echo.join(channelName)
            .here((people) => { present.value = people; })
            .joining((person) => { present.value = [...present.value, person]; })
            .leaving((person) => {
                present.value = present.value.filter((p) => p.id !== person.id);
            })
            .listen('.message.sent', (payload) => {
                apply([payload.message]);
                tellThemIAmReading();
            })
            .listen('.message.read', markRead)
            .listen('.message.removed', (payload) => markRemoved(payload.messageId))
            .listenForWhisper('typing', noteTyping);
    }

    function open(id, seed) {
        unsubscribe();

        messages.value = [...(seed ?? [])];
        hasMore.value = true;

        schedule();

        if (id) subscribe(id);
    }

    // The room on screen changed: rebuild everything around it.
    watch(currentId, (id) => open(id, initialMessages()), { immediate: true });

    // The poll slows down when the socket comes up and speeds up when it drops.
    watch(live, schedule);

    onBeforeUnmount(() => {
        clearInterval(poller);
        unsubscribe();
    });

    return {
        messages,
        typing,
        present,
        hasMore,
        loadingOlder,
        live,
        apply,
        loadOlder,
        markRead: tellThemIAmReading,
        whisperTyping,
    };
}
