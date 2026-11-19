<script setup>
/**
 * The proctor's console (§9.1a): everybody sitting the quiz, live.
 *
 * Each tile says whether the student is in full screen and in the quiz, how
 * often they left and what they tried, how far they are and how long they
 * have. A fresh violation moves its tile to the top. Updates arrive over the
 * socket as they happen; the page also asks every few seconds, so a socket
 * that is down costs speed and nothing else.
 *
 * Nothing here sees a screen or a camera, and nothing is recorded.
 */
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import { CircleStop, MessageSquareText, MonitorCheck, TimerReset, WifiOff } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiCard from '@/components/UI/UiCard.vue';
import UiEmptyState from '@/components/UI/UiEmptyState.vue';
import UiFormField from '@/components/UI/UiFormField.vue';
import UiInput from '@/components/UI/UiInput.vue';
import UiModal from '@/components/UI/UiModal.vue';
import UiTextarea from '@/components/UI/UiTextarea.vue';
import { blankForm } from '@/support/forms';
import { connect, leave, live } from '@/support/realtime';

const props = defineProps({
    quiz: { type: Object, required: true },
    sitting: { type: Object, default: null },
    tiles: { type: Array, default: () => [] },
    waiting: { type: Array, default: () => [] },
    now: { type: String, required: true },
    quietSeconds: { type: Number, default: 30 },
    maxExtraMinutes: { type: Number, default: 60 },
});

/* ------------------------------------------------------------ the tiles */

const tiles = ref([]);
const waiting = ref(props.waiting);
const clock = ref(Date.now());
let offset = 0; // the server's clock less this one's

function take(list, serverNow) {
    offset = Date.parse(serverNow) - Date.now();
    tiles.value = list.map((tile) => ({ ...tile, heardAt: Date.now() }));
}

function upsert(tile) {
    const fresh = { ...tile, heardAt: Date.now() };
    const index = tiles.value.findIndex((item) => item.id === tile.id);
    index === -1 ? tiles.value.push(fresh) : tiles.value.splice(index, 1, fresh);
}

take(props.tiles, props.now);
// An action reloads the page's props; take them.
watch(() => props.tiles, (list) => take(list, props.now));
watch(() => props.waiting, (list) => (waiting.value = list));

const serverNow = () => clock.value + offset;
const secondsSince = (iso) => (iso ? Math.max(0, Math.round((serverNow() - Date.parse(iso)) / 1000)) : null);
const fresh = (tile) => tile.status !== 'finished' && tile.lastViolationAt && secondsSince(tile.lastViolationAt) < 120;
const quiet = (tile) => tile.status !== 'finished' && (secondsSince(tile.seenAt) ?? Infinity) > props.quietSeconds;
const away = (tile) => tile.status !== 'finished' && !quiet(tile) && tile.reported && (!tile.fullscreen || !tile.focused);

function timeLeft(tile) {
    if (tile.secondsRemaining === null || tile.secondsRemaining === undefined) return null;
    const left = Math.max(0, tile.secondsRemaining - Math.round((clock.value - tile.heardAt) / 1000));

    return `${Math.floor(left / 60)}:${String(left % 60).padStart(2, '0')}`;
}

// A fresh violation first, then whoever is away or silent, then the rest; finished last.
const rank = (tile) => (tile.status === 'finished' ? 4 : fresh(tile) ? 0 : away(tile) ? 1 : quiet(tile) ? 2 : 3);
const sorted = computed(() => [...tiles.value].sort((a, b) => rank(a) - rank(b) || a.student.localeCompare(b.student)));

const counts = computed(() => ({
    sitting: tiles.value.filter((tile) => tile.status !== 'finished').length,
    finished: tiles.value.filter((tile) => tile.status === 'finished').length,
    attention: tiles.value.filter((tile) => rank(tile) < 3).length,
}));

function statusOf(tile) {
    if (tile.status === 'finished') return [tile.endedReason ? `Ended at ${tile.finishedAt}` : `Finished at ${tile.finishedAt}`, 'neutral'];
    if (quiet(tile)) return [`Not heard from for ${secondsSince(tile.seenAt) ?? '?'} s`, 'warning'];
    if (!tile.reported) return ['Opening the quiz', 'neutral'];
    if (!tile.fullscreen) return ['Out of full screen', 'danger'];
    if (!tile.focused) return ['Away from the quiz', 'danger'];

    return ['In the quiz', 'success'];
}

/* -------------------------------------------------------- staying current */

let channel = null;
let poller = null;
let ticker = null;

async function poll() {
    try {
        const response = await fetch(`/admin/proctoring/quizzes/${props.quiz.id}/console/status`, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });

        if (response.ok) {
            const data = await response.json();
            take(data.tiles, data.now);
            waiting.value = data.waiting;
        }
    } catch {
        // The next one tries again.
    }

    poller = setTimeout(poll, live.value ? 30000 : 8000);
}

onMounted(async () => {
    ticker = setInterval(() => (clock.value = Date.now()), 1000);
    poller = setTimeout(poll, 8000);

    const echo = await connect();
    channel = `quiz-proctor.${props.quiz.id}`;
    echo?.private(channel).listen('.attempt.updated', (event) => upsert(event.tile));
});

onBeforeUnmount(() => {
    clearInterval(ticker);
    clearTimeout(poller);
    if (channel) leave(channel);
});

/* ------------------------------------------------- the proctor's three */

const acting = ref(null); // { kind, tile }
const message = useForm({ text: '' });
const time = useForm({ minutes: 5, reason: '' });
const ending = useForm({ reason: '' });

function open(kind, tile) {
    if (kind === 'message') blankForm(message, { text: '' });
    if (kind === 'time') blankForm(time, { minutes: 5, reason: '' });
    if (kind === 'end') blankForm(ending, { reason: '' });
    acting.value = { kind, tile };
}

function send() {
    const { kind, tile } = acting.value;
    const form = { message, time, end: ending }[kind];
    const path = { message: 'message', time: 'time', end: 'end' }[kind];

    form.post(`/admin/proctoring/attempts/${tile.id}/${path}`, { preserveScroll: true, onSuccess: () => (acting.value = null) });
}

const titles = { message: 'Message', time: 'Give more time', end: 'End the attempt' };
</script>

<template>
    <Head :title="`Console · ${quiz.title}`" />

    <AppLayout
        :title="`Console · ${quiz.title}`"
        :breadcrumbs="[{ label: 'Admin', href: '/admin' }, { label: 'Proctoring', href: '/admin/proctoring' }, { label: quiz.title }]"
    >
        <div class="space-y-5">
            <PageHeader :title="quiz.title" :description="`${quiz.course}${sitting ? ` · ${sitting.open ? 'sitting now' : 'sitting'} ${sitting.window}` : ' · no sitting now'}${quiz.maxExits ? ` · submitted after leaving ${quiz.maxExits} ${quiz.maxExits === 1 ? 'time' : 'times'}` : ''}`">
                <template #actions>
                    <span class="flex items-center gap-2 text-xs" style="color: var(--text-muted)">
                        <span class="h-2 w-2 rounded-full" :class="live ? 'bg-signal-500' : 'bg-warn-500'" aria-hidden="true" />
                        {{ live ? 'Live' : 'Checking every few seconds' }}
                    </span>
                </template>
            </PageHeader>

            <div class="flex flex-wrap gap-2 text-sm">
                <UiBadge tone="brand" size="md">{{ counts.sitting }} sitting it</UiBadge>
                <UiBadge v-if="counts.attention" tone="danger" size="md">{{ counts.attention }} to look at</UiBadge>
                <UiBadge tone="neutral" size="md">{{ counts.finished }} finished</UiBadge>
                <UiBadge v-if="waiting.length" tone="warning" size="md">{{ waiting.length }} not started</UiBadge>
            </div>

            <UiCard v-if="!tiles.length">
                <UiEmptyState
                    :icon="MonitorCheck"
                    title="Nobody is sitting it yet"
                    :description="sitting ? 'Students appear here the moment they start.' : 'Students can start it only during a sitting. Sittings are set on the quiz’s proctoring settings.'"
                />
            </UiCard>

            <!-- One tile per student. -->
            <ul v-else class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3" aria-live="polite">
                <li
                    v-for="tile in sorted"
                    :key="tile.id"
                    class="rounded-[var(--radius-card)] border p-4 transition"
                    :style="{
                        background: 'var(--surface-raised)',
                        borderColor: fresh(tile) || away(tile) ? 'var(--color-danger-500)' : quiet(tile) ? 'var(--color-warn-500)' : 'var(--border-subtle)',
                        borderWidth: fresh(tile) || away(tile) ? '2px' : '1px',
                        opacity: tile.status === 'finished' ? 0.7 : 1,
                    }"
                >
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-semibold">
                                {{ tile.student }}
                                <span class="text-xs font-normal" style="color: var(--text-muted)">· attempt {{ tile.number }}</span>
                            </p>
                            <p class="mt-0.5 flex items-center gap-1.5 text-xs">
                                <WifiOff v-if="quiet(tile)" class="h-3 w-3" style="color: var(--color-warn-500)" aria-hidden="true" />
                                <UiBadge :tone="statusOf(tile)[1]" size="sm" dot>{{ statusOf(tile)[0] }}</UiBadge>
                            </p>
                        </div>
                        <span v-if="tile.status !== 'finished' && timeLeft(tile)" class="tnum text-sm font-semibold" :title="'Time left'">{{ timeLeft(tile) }}</span>
                    </div>

                    <div class="mt-3 flex flex-wrap gap-1.5">
                        <UiBadge v-if="tile.status !== 'finished' && tile.reported" :tone="tile.fullscreen ? 'success' : 'danger'" size="sm">Full screen {{ tile.fullscreen ? 'on' : 'off' }}</UiBadge>
                        <UiBadge :tone="tile.exits ? 'warning' : 'neutral'" size="sm">Left {{ tile.exits }}{{ quiz.maxExits ? ` of ${quiz.maxExits}` : '' }}</UiBadge>
                        <UiBadge :tone="tile.blocked ? 'warning' : 'neutral'" size="sm">Tried {{ tile.blocked }}</UiBadge>
                        <UiBadge v-if="tile.messages" tone="brand" size="sm">{{ tile.messages }} {{ tile.messages === 1 ? 'message' : 'messages' }}</UiBadge>
                    </div>

                    <p class="mt-3 text-xs" style="color: var(--text-muted)">Answered {{ tile.answered }} of {{ tile.total }}</p>
                    <div class="mt-1 h-1.5 overflow-hidden rounded-full" style="background: var(--surface-sunken)">
                        <div class="h-full rounded-full bg-brand-500" :style="{ width: `${tile.total ? Math.round((tile.answered / tile.total) * 100) : 0}%` }" />
                    </div>

                    <p v-if="tile.endedReason" class="mt-2 text-xs" style="color: var(--text-muted)">{{ tile.endedReason }}</p>

                    <div v-if="tile.status !== 'finished'" class="mt-3 flex flex-wrap gap-1">
                        <UiButton size="xs" variant="secondary" @click="open('message', tile)">
                            <template #leading><MessageSquareText class="h-3 w-3" /></template>
                            Message
                        </UiButton>
                        <UiButton size="xs" variant="ghost" @click="open('time', tile)">
                            <template #leading><TimerReset class="h-3 w-3" /></template>
                            More time
                        </UiButton>
                        <UiButton size="xs" variant="ghost" @click="open('end', tile)">
                            <template #leading><CircleStop class="h-3 w-3" style="color: var(--color-danger-500)" /></template>
                            End
                        </UiButton>
                    </div>
                </li>
            </ul>

            <UiCard v-if="waiting.length">
                <p class="text-sm font-medium">Not started yet</p>
                <p class="mt-1 text-sm" style="color: var(--text-muted)">{{ waiting.join(', ') }}</p>
            </UiCard>
        </div>

        <UiModal :open="acting !== null" :title="acting ? `${titles[acting.kind]} · ${acting.tile.student}` : ''" @close="acting = null">
            <form v-if="acting" id="proctor-form" class="space-y-4" @submit.prevent="send">
                <template v-if="acting.kind === 'message'">
                    <UiFormField label="Message" required hint="It appears over their quiz within a few seconds, until they press OK." :error="message.errors.text">
                        <UiTextarea v-model="message.text" :rows="3" maxlength="300" placeholder="Please keep the quiz in full screen." />
                    </UiFormField>
                </template>

                <template v-else-if="acting.kind === 'time'">
                    <UiFormField label="Minutes" required :hint="`Up to ${maxExtraMinutes} at a time, added to their clock now.`" :error="time.errors.minutes">
                        <UiInput v-model.number="time.minutes" type="number" min="1" :max="maxExtraMinutes" class="max-w-32" />
                    </UiFormField>
                    <UiFormField label="Why" required hint="Kept in the audit log, for example a connection that dropped." :error="time.errors.reason">
                        <UiInput v-model="time.reason" maxlength="300" />
                    </UiFormField>
                </template>

                <template v-else>
                    <p class="text-sm" style="color: var(--text-base)">
                        What they have answered is marked as it stands, and they are told why it ended. Whether they sit it again is the trainer's decision afterwards.
                    </p>
                    <UiFormField label="Why" required hint="The student reads this." :error="ending.errors.reason">
                        <UiTextarea v-model="ending.reason" :rows="3" maxlength="500" />
                    </UiFormField>
                </template>
            </form>
            <template #footer>
                <UiButton variant="ghost" @click="acting = null">Cancel</UiButton>
                <UiButton
                    type="submit"
                    form="proctor-form"
                    :variant="acting?.kind === 'end' ? 'danger' : 'primary'"
                    :loading="message.processing || time.processing || ending.processing"
                >
                    {{ acting?.kind === 'end' ? 'End the attempt' : acting?.kind === 'time' ? 'Add the time' : 'Send' }}
                </UiButton>
            </template>
        </UiModal>
    </AppLayout>
</template>
