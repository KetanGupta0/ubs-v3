<script setup>
/**
 * Chat, for whoever is signed in.
 *
 * One screen for all three panels. A client sees their threads, a student sees
 * their batches and an administrator sees everything they are allowed to
 * answer — the same page, because the difference is in who is asking and not
 * in what a conversation looks like.
 *
 * On a phone it is one pane at a time, list then thread, which is what every
 * messaging application does and what a back button expects.
 */
import { ref, computed, watch, nextTick, onMounted } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { ArrowLeft, Images, Users, Wifi, WifiOff, MessagesSquare, Megaphone, Pin, X, Bell, BellOff } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import ConversationList from '@/components/Chat/ConversationList.vue';
import MessageBubble from '@/components/Chat/MessageBubble.vue';
import Composer from '@/components/Chat/Composer.vue';
import MediaGallery from '@/components/Chat/MediaGallery.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiEmptyState from '@/components/UI/UiEmptyState.vue';
import UiAvatar from '@/components/UI/UiAvatar.vue';
import UiModal from '@/components/UI/UiModal.vue';
import UiFormField from '@/components/UI/UiFormField.vue';
import UiSelect from '@/components/UI/UiSelect.vue';
import UiTextarea from '@/components/UI/UiTextarea.vue';
import { useConversation } from '@/composables/useConversation';
import { usePage } from '@inertiajs/vue3';
import { toast } from '@/support/toast';

const props = defineProps({
    conversations: { type: Array, default: () => [] },
    conversation: { type: Object, default: null },
    messages: { type: Array, default: () => [] },
    participants: { type: Array, default: () => [] },
});

const page = usePage();
const me = computed(() => page.props.auth?.user?.id ?? null);
const isStaff = computed(() => page.props.auth?.user?.role === 'admin');

const scroller = ref(null);
const replyTo = ref(null);
const galleryOpen = ref(false);

/*
 * Created once and told which room is on screen. The composable holds the
 * socket subscription and the poll, and rebuilds them itself when the room
 * changes, so clicking through six threads does not leave six of each behind.
 */
const room = useConversation(
    () => props.conversation?.id ?? null,
    () => props.messages,
    () => me.value,
    () => ({
        announcementOnly: props.conversation?.announcementOnly ?? false,
        pinned: props.conversation?.pinned ?? null,
        blockedReason: props.conversation?.blockedReason ?? null,
    }),
);

/* ------------------------------------------------------ running a group */

/** A moderation request: JSON in and out, so the thread stays where it is. */
async function act(method, path, body = {}) {
    try {
        const response = await fetch(`/chat/${props.conversation.id}${path}`, {
            method,
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
            },
            body: method === 'GET' ? undefined : JSON.stringify(body),
        });
        const data = await response.json().catch(() => ({}));

        if (!response.ok) {
            toast.error(data.message || 'That did not work. Try again.');
            return false;
        }

        room.applyRoom(data.room);
        if (data.message) toast.success(data.message);

        return true;
    } catch {
        toast.error('That did not work. Check the connection and try again.');
        return false;
    }
}

const pin = (message) => act('POST', '/pin', { message_id: message?.id ?? null });
const toggleAnnouncement = () => act('PUT', '/announcement', { on: !room.room.value.announcementOnly });

const muted = ref(props.conversation?.muted ?? false);
watch(() => props.conversation?.id, () => { muted.value = props.conversation?.muted ?? false; });

async function toggleMute() {
    if (await act('PUT', '/mute', { muted: !muted.value })) muted.value = !muted.value;
}

function showPinned() {
    const id = room.room.value.pinned?.id;
    const el = id ? document.getElementById(`message-${id}`) : null;

    if (el) {
        el.scrollIntoView({ behavior: 'smooth', block: 'center' });
    } else {
        toast.info('That message is further back. Scroll up to load it.');
    }
}

const reporting = ref(null);
const reportReason = ref('');

function openReport(message) {
    reporting.value = message;
    reportReason.value = '';
}

async function sendReport() {
    if (await act('POST', `/messages/${reporting.value.id}/report`, { reason: reportReason.value })) {
        reporting.value = null;
    }
}

const silencing = ref(null);
const silenceFor = ref('1d');
const silenceReason = ref('');

function openSilence(person) {
    silencing.value = person;
    silenceFor.value = '1d';
    silenceReason.value = '';
}

async function sendSilence() {
    if (await act('POST', `/members/${silencing.value.id}/silence`, { for: silenceFor.value, reason: silenceReason.value })) {
        silencing.value = null;
    }
}

onMounted(() => nextTick(scrollToEnd));

watch(() => props.conversation?.id, () => nextTick(scrollToEnd));

// New arrivals scroll into view, unless the reader has scrolled up to read
// something older, in which case yanking them back would be rude.
watch(
    () => room.messages.value.length,
    () => {
        if (nearTheBottom()) nextTick(scrollToEnd);
    },
);

function nearTheBottom() {
    const el = scroller.value;

    if (!el) return true;

    // Generous, because an image that has not finished loading yet makes the
    // reader look further from the bottom than they are.
    return el.scrollHeight - el.scrollTop - el.clientHeight < 400;
}

function scrollToEnd() {
    const el = scroller.value;

    if (el) el.scrollTop = el.scrollHeight;
}

function onScroll() {
    if (scroller.value?.scrollTop === 0 && room.hasMore.value) {
        const before = scroller.value.scrollHeight;

        room.loadOlder().then(() =>
            nextTick(() => {
                // Keep the reader looking at the same message rather than
                // teleporting them to the top of the new page.
                scroller.value.scrollTop = scroller.value.scrollHeight - before;
            }),
        );
    }
}

function onSent(message) {
    room.apply([message]);
    nextTick(scrollToEnd);
}

/**
 * A photo that has just finished loading is taller than the gap it was given,
 * so a thread that scrolled to the bottom a moment ago is no longer there.
 */
function onMediaLoaded() {
    if (nearTheBottom()) nextTick(scrollToEnd);
}

let typingSentAt = 0;

function onTyping() {
    const now = Date.now();

    // One whisper a second is plenty; a keystroke each would be a small flood.
    if (now - typingSentAt < 1000) return;

    typingSentAt = now;
    room.whisperTyping(page.props.auth?.user?.name ?? 'Somebody');
}

function remove(message) {
    if (!confirm('Remove this message for everybody?')) return;

    router.delete(`/chat/${props.conversation.id}/messages/${message.id}`, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            const at = room.messages.value.findIndex((m) => m.id === message.id);

            if (at !== -1) {
                room.messages.value[at] = {
                    ...room.messages.value[at],
                    removed: true,
                    body: null,
                    mediaUrl: null,
                };
            }
        },
    });
}

/** Day separators, and whether to repeat a sender's name. */
const rendered = computed(() => {
    const list = room.messages.value;
    const out = [];
    let day = null;
    let lastSender = null;

    for (const message of list) {
        if (message.dayLabel !== day) {
            day = message.dayLabel;
            lastSender = null;
            out.push({ separator: day, key: `day-${message.id}` });
        }

        out.push({
            message,
            key: `m-${message.id}`,
            showSender: message.sender.id !== lastSender,
        });

        lastSender = message.sender.id;
    }

    return out;
});

const typingLine = computed(() => {
    const people = room.typing.value;

    if (!people.length) return null;
    if (people.length === 1) return `${people[0].name} is typing…`;

    return `${people.length} people are typing…`;
});

const othersHere = computed(() =>
    room.present.value.filter((person) => person.id !== me.value),
);
</script>

<template>
    <Head :title="conversation ? conversation.title : 'Chat'" />

    <AppLayout title="Chat" :breadcrumbs="[{ label: 'Chat' }]">
        <div
            class="mx-auto flex h-[calc(100dvh-10rem)] max-w-6xl overflow-hidden rounded-[var(--radius-card)] border"
            style="border-color: var(--border-subtle); background: var(--surface)"
        >
            <!-- ------------------------------------------------ the list -->
            <aside
                class="w-full shrink-0 border-r sm:w-72 lg:w-80"
                :class="conversation ? 'hidden sm:block' : 'block'"
                style="border-color: var(--border-subtle)"
            >
                <header class="border-b px-3 py-3" style="border-color: var(--border-subtle)">
                    <h1 class="text-sm font-semibold">Conversations</h1>
                    <p class="text-xs" style="color: var(--text-muted)">
                        {{ isStaff ? 'Every thread you can answer' : 'Yours' }}
                    </p>
                </header>

                <ConversationList
                    :conversations="conversations"
                    :active-id="conversation?.id ?? null"
                    :staff-view="isStaff"
                    class="h-[calc(100%-3.75rem)]"
                />
            </aside>

            <!-- ---------------------------------------------- the thread -->
            <section class="flex min-w-0 flex-1 flex-col" :class="conversation ? 'flex' : 'hidden sm:flex'">
                <template v-if="conversation">
                    <header
                        class="flex items-center gap-3 border-b px-3 py-2.5 sm:px-4"
                        style="border-color: var(--border-subtle)"
                    >
                        <UiButton href="/chat" variant="ghost" size="xs" class="sm:hidden" aria-label="Back">
                            <ArrowLeft class="h-4 w-4" />
                        </UiButton>

                        <span class="min-w-0 flex-1">
                            <span class="flex items-center gap-2">
                                <span class="truncate text-sm font-semibold">{{ conversation.title }}</span>

                                <span
                                    v-if="room.live.value"
                                    class="inline-flex items-center gap-1 text-[0.65rem]"
                                    style="color: var(--text-success)"
                                    title="Live"
                                >
                                    <Wifi class="h-3 w-3" />
                                </span>
                                <span
                                    v-else
                                    class="inline-flex items-center gap-1 text-[0.65rem]"
                                    style="color: var(--text-muted)"
                                    title="Not live — messages arrive within a few seconds"
                                >
                                    <WifiOff class="h-3 w-3" />
                                </span>
                            </span>

                            <span class="block truncate text-xs" style="color: var(--text-muted)">
                                <template v-if="typingLine">{{ typingLine }}</template>
                                <template v-else-if="othersHere.length">
                                    {{ othersHere.map((p) => p.name).join(', ') }} here now
                                </template>
                                <template v-else>{{ conversation.subtitle }}</template>
                            </span>
                        </span>

                        <span v-if="othersHere.length" class="hidden shrink-0 items-center -space-x-2 sm:flex">
                            <UiAvatar
                                v-for="person in othersHere.slice(0, 3)"
                                :key="person.id"
                                :name="person.name"
                                :src="person.avatarUrl"
                                size="xs"
                            />
                        </span>

                        <UiButton
                            v-if="conversation.canModerate"
                            variant="ghost"
                            size="xs"
                            :aria-pressed="room.room.value.announcementOnly"
                            :aria-label="room.room.value.announcementOnly ? 'Announcements only is on. Let everybody post again' : 'Announcements only: just the trainer and the team can post'"
                            :title="room.room.value.announcementOnly ? 'Announcements only (on)' : 'Announcements only (off)'"
                            @click="toggleAnnouncement"
                        >
                            <Megaphone class="h-4 w-4" :class="room.room.value.announcementOnly ? 'text-brand-500' : ''" />
                        </UiButton>

                        <UiButton
                            v-if="conversation.isParticipant"
                            variant="ghost"
                            size="xs"
                            :aria-pressed="muted"
                            :aria-label="muted ? 'Unmute this conversation' : 'Mute this conversation'"
                            :title="muted ? 'Muted: not counted in your unread messages' : 'Mute'"
                            @click="toggleMute"
                        >
                            <component :is="muted ? BellOff : Bell" class="h-4 w-4" />
                        </UiButton>

                        <UiButton variant="ghost" size="xs" aria-label="Shared media" @click="galleryOpen = true">
                            <Images class="h-4 w-4" />
                        </UiButton>
                    </header>

                    <!-- what is pinned, for everybody in the group -->
                    <div
                        v-if="room.room.value.pinned"
                        class="flex items-center gap-2 border-b px-3 py-2 text-xs sm:px-4"
                        style="border-color: var(--border-subtle); background: var(--surface-sunken)"
                    >
                        <Pin class="h-3.5 w-3.5 shrink-0 text-brand-500" />
                        <button type="button" class="min-w-0 flex-1 truncate text-left" @click="showPinned">
                            <span class="font-medium" style="color: var(--text-strong)">{{ room.room.value.pinned.sender }}:</span>
                            {{ room.room.value.pinned.preview }}
                        </button>
                        <button
                            v-if="conversation.canModerate"
                            type="button"
                            class="rounded p-1 transition hover:bg-[var(--surface)]"
                            style="color: var(--text-muted)"
                            aria-label="Unpin"
                            @click="pin(null)"
                        >
                            <X class="h-3.5 w-3.5" />
                        </button>
                    </div>

                    <p
                        v-if="room.room.value.announcementOnly"
                        class="flex items-center gap-2 border-b px-3 py-1.5 text-xs sm:px-4"
                        style="border-color: var(--border-subtle); color: var(--text-muted)"
                    >
                        <Megaphone class="h-3.5 w-3.5 shrink-0" />
                        Announcements only: just the trainer and the team can post.
                    </p>

                    <div ref="scroller" class="min-h-0 flex-1 space-y-2.5 overflow-y-auto px-3 py-4 sm:px-4" @scroll="onScroll">
                        <p
                            v-if="room.loadingOlder.value"
                            class="text-center text-xs"
                            style="color: var(--text-muted)"
                        >
                            Loading earlier messages…
                        </p>

                        <template v-for="row in rendered" :key="row.key">
                            <p v-if="row.separator" class="py-1 text-center text-[0.68rem]" style="color: var(--text-muted)">
                                {{ row.separator }}
                            </p>

                            <MessageBubble
                                v-else
                                :id="`message-${row.message.id}`"
                                :message="row.message"
                                :show-sender="conversation.isGroup && row.showSender"
                                :can-remove="conversation.canRemoveAny"
                                :can-moderate="conversation.canModerate"
                                :can-report="conversation.canReport"
                                :pinned="room.room.value.pinned?.id === row.message.id"
                                @reply="replyTo = $event"
                                @remove="remove"
                                @pin="pin"
                                @report="openReport"
                                @silence="openSilence"
                                @media-loaded="onMediaLoaded"
                            />
                        </template>

                        <p
                            v-if="!rendered.length"
                            class="py-10 text-center text-sm"
                            style="color: var(--text-muted)"
                        >
                            No messages yet. Say hello.
                        </p>
                    </div>

                    <Composer
                        :conversation-id="conversation.id"
                        :reply-to="replyTo"
                        :blocked-reason="room.room.value.blockedReason"
                        @sent="onSent"
                        @typing="onTyping"
                        @cancel-reply="replyTo = null"
                    />
                </template>

                <UiEmptyState
                    v-else
                    class="m-auto"
                    :icon="MessagesSquare"
                    title="Pick a conversation"
                    description="Threads with clients and batch groups live here."
                />
            </section>
        </div>

        <UiModal :open="reporting !== null" title="Report this message" description="It goes to the people who run this group. Nothing is taken down until one of them looks." @close="reporting = null">
            <form id="report-form" class="space-y-3" @submit.prevent="sendReport">
                <p v-if="reporting" class="rounded-lg px-3 py-2 text-sm" style="background: var(--surface-sunken)">
                    <span class="font-medium">{{ reporting.sender.name }}:</span> {{ reporting.body || 'A photo or voice note' }}
                </p>
                <UiFormField label="What is wrong with it" hint="Optional, but it helps whoever looks.">
                    <UiTextarea v-model="reportReason" :rows="3" />
                </UiFormField>
            </form>
            <template #footer>
                <UiButton variant="ghost" @click="reporting = null">Cancel</UiButton>
                <UiButton type="submit" form="report-form">Report</UiButton>
            </template>
        </UiModal>

        <UiModal :open="silencing !== null" :title="silencing ? `Stop ${silencing.name} posting for a while` : ''" description="They can still read the group, and they are told until when." @close="silencing = null">
            <form id="silence-form" class="space-y-3" @submit.prevent="sendSilence">
                <UiFormField label="For">
                    <UiSelect v-model="silenceFor" :options="conversation?.silenceOptions ?? []" />
                </UiFormField>
                <UiFormField label="Reason" hint="Optional. It is sent to them, and kept on the record.">
                    <UiTextarea v-model="silenceReason" :rows="2" />
                </UiFormField>
            </form>
            <template #footer>
                <UiButton variant="ghost" @click="silencing = null">Cancel</UiButton>
                <UiButton type="submit" form="silence-form">Stop them posting</UiButton>
            </template>
        </UiModal>

        <MediaGallery
            v-if="conversation"
            :open="galleryOpen"
            :conversation-id="conversation.id"
            @close="galleryOpen = false"
        />
    </AppLayout>
</template>
