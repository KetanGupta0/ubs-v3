<script setup>
/**
 * Writing a message.
 *
 * Text, a photo, or a voice note, and nothing else — the same three kinds the
 * database will accept, so the screen and the schema agree about what a
 * message is.
 *
 * The recorder computes the waveform here, where the decoded audio already is.
 * There is no ffmpeg on the server, and asking it to decode Opus to draw
 * thirty bars would be a strange way to spend a request.
 */
import { ref, computed, onBeforeUnmount, nextTick } from 'vue';
import { Send, ImagePlus, Mic, Square, Trash2, X } from 'lucide-vue-next';

import UiButton from '@/components/UI/UiButton.vue';
import { toast } from '@/support/toast';

const props = defineProps({
    conversationId: { type: Number, required: true },
    replyTo: { type: Object, default: null },
});

const emit = defineEmits(['sent', 'typing', 'cancel-reply']);

const body = ref('');
const sending = ref(false);
const input = ref(null);
const fileInput = ref(null);

/* ------------------------------------------------------------- a picture */

const picked = ref(null);
const previewUrl = ref(null);

function choose(event) {
    const file = event.target.files?.[0];

    if (!file) return;

    if (!file.type.startsWith('image/')) {
        toast.error('Photos only here. Documents go in the documents section.');
        return;
    }

    picked.value = file;
    previewUrl.value = URL.createObjectURL(file);
}

function unpick() {
    if (previewUrl.value) URL.revokeObjectURL(previewUrl.value);

    picked.value = null;
    previewUrl.value = null;

    if (fileInput.value) fileInput.value.value = '';
}

/* ---------------------------------------------------------- a voice note */

const recording = ref(false);
const seconds = ref(0);

let recorder = null;
let chunks = [];
let stream = null;
let ticker = null;

const clock = computed(
    () => `${Math.floor(seconds.value / 60)}:${String(seconds.value % 60).padStart(2, '0')}`,
);

async function startRecording() {
    if (!navigator.mediaDevices?.getUserMedia || typeof MediaRecorder === 'undefined') {
        toast.error('This browser cannot record audio.');
        return;
    }

    try {
        stream = await navigator.mediaDevices.getUserMedia({ audio: true });
    } catch {
        // Refusing the microphone is a decision, not an error worth shouting
        // about. Say what happened and leave it there.
        toast.info('Microphone access is off, so a voice note cannot be recorded.');
        return;
    }

    chunks = [];
    recorder = new MediaRecorder(stream);
    recorder.ondataavailable = (event) => event.data.size && chunks.push(event.data);
    recorder.start();

    recording.value = true;
    seconds.value = 0;
    ticker = setInterval(() => {
        seconds.value += 1;

        // Five minutes is the cap on the server too. Stopping here means
        // nobody records six minutes and is told afterwards.
        if (seconds.value >= 300) stopRecording();
    }, 1000);
}

function tidyRecorder() {
    clearInterval(ticker);
    stream?.getTracks().forEach((track) => track.stop());
    recording.value = false;
    recorder = null;
    stream = null;
}

function cancelRecording() {
    if (!recorder) return;

    recorder.onstop = () => { chunks = []; };
    recorder.stop();
    tidyRecorder();
}

async function stopRecording() {
    if (!recorder) return;

    const length = seconds.value;

    recorder.onstop = async () => {
        const blob = new Blob(chunks, { type: recorder?.mimeType || 'audio/webm' });
        tidyRecorder();

        if (blob.size < 1200) {
            toast.info('That was too short to send.');
            return;
        }

        await send('audio', blob, { duration: length, peaks: await peaksFor(blob) });
    };

    recorder.stop();
}

/**
 * Thirty two bars, from the samples we already have in memory.
 *
 * Root mean square rather than peak, because a single click at the start
 * otherwise flattens the whole rest of the waveform.
 */
async function peaksFor(blob) {
    try {
        const context = new (window.AudioContext || window.webkitAudioContext)();
        const buffer = await context.decodeAudioData(await blob.arrayBuffer());
        const samples = buffer.getChannelData(0);
        const buckets = 32;
        const size = Math.floor(samples.length / buckets) || 1;
        const peaks = [];

        for (let i = 0; i < buckets; i++) {
            let sum = 0;

            for (let j = 0; j < size; j++) {
                const value = samples[i * size + j] || 0;
                sum += value * value;
            }

            peaks.push(Math.min(1, Math.sqrt(sum / size) * 2.2));
        }

        context.close();

        const loudest = Math.max(...peaks, 0.01);

        return peaks.map((peak) => Number((peak / loudest).toFixed(3)));
    } catch {
        // A waveform is a nicety. Losing it is not a reason to lose the message.
        return [];
    }
}

/* ----------------------------------------------------------------- sending */

async function send(kind, file = null, extra = {}) {
    if (sending.value) return;

    const data = new FormData();
    data.append('kind', kind);

    if (props.replyTo) data.append('reply_to_id', props.replyTo.id);
    if (body.value.trim()) data.append('body', body.value.trim());

    if (file) {
        data.append('file', file, kind === 'audio' ? 'voice-note.webm' : (file.name || 'photo.jpg'));
    }

    if (extra.duration) data.append('duration', String(extra.duration));
    (extra.peaks || []).forEach((peak) => data.append('peaks[]', String(peak)));

    sending.value = true;

    try {
        const response = await fetch(`/chat/${props.conversationId}/messages`, {
            method: 'POST',
            body: data,
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
            },
            credentials: 'same-origin',
        });

        if (!response.ok) {
            const problem = await response.json().catch(() => ({}));
            throw new Error(problem.message || 'That did not send. Try again.');
        }

        const payload = await response.json();

        body.value = '';
        unpick();
        emit('cancel-reply');

        // Straight onto the screen. The socket brings the same message a
        // moment later and the thread deduplicates on the id.
        emit('sent', payload.message);

        nextTick(() => {
            if (input.value) {
                input.value.style.height = 'auto';
                input.value.focus();
            }
        });
    } catch (error) {
        toast.error(error.message || 'That did not send. Try again.');
    } finally {
        sending.value = false;
    }
}

function submit() {
    if (picked.value) {
        send('image', picked.value);
        return;
    }

    if (body.value.trim()) {
        send('text');
    }
}

/** Enter sends, shift and enter makes a new line. */
function onKeydown(event) {
    if (event.key === 'Enter' && !event.shiftKey) {
        event.preventDefault();
        submit();
    }
}

function grow(event) {
    const el = event.target;

    el.style.height = 'auto';
    el.style.height = `${Math.min(el.scrollHeight, 160)}px`;

    emit('typing');
}

onBeforeUnmount(() => {
    cancelRecording();
    if (previewUrl.value) URL.revokeObjectURL(previewUrl.value);
});
</script>

<template>
    <div class="border-t px-3 py-2.5 sm:px-4" style="border-color: var(--border-subtle); background: var(--surface)">
        <!-- replying to -->
        <div
            v-if="replyTo"
            class="mb-2 flex items-start gap-2 rounded-lg border-l-2 px-2.5 py-1.5 text-xs"
            style="background: var(--surface-sunken); border-color: var(--color-brand-500)"
        >
            <span class="min-w-0 flex-1">
                <span class="block font-medium">Replying to {{ replyTo.sender.name }}</span>
                <span class="block truncate" style="color: var(--text-muted)">
                    {{ replyTo.body || (replyTo.kind === 'image' ? 'Photo' : 'Voice message') }}
                </span>
            </span>

            <button type="button" aria-label="Cancel reply" @click="$emit('cancel-reply')">
                <X class="h-3.5 w-3.5" style="color: var(--text-muted)" />
            </button>
        </div>

        <!-- the photo waiting to go -->
        <div v-if="previewUrl" class="mb-2 flex items-center gap-3">
            <img :src="previewUrl" alt="" class="h-16 w-16 rounded-lg object-cover" />
            <span class="text-xs" style="color: var(--text-muted)">
                Add a caption if you like, then send.
            </span>
            <button type="button" class="ml-auto" aria-label="Remove photo" @click="unpick">
                <Trash2 class="h-4 w-4" style="color: var(--color-danger-500)" />
            </button>
        </div>

        <!-- recording -->
        <div v-if="recording" class="flex items-center gap-3">
            <span class="flex items-center gap-2 text-sm">
                <span class="h-2.5 w-2.5 animate-pulse rounded-full" style="background: var(--color-danger-500)" />
                Recording {{ clock }}
            </span>

            <span class="ml-auto flex items-center gap-2">
                <UiButton variant="ghost" size="sm" @click="cancelRecording">Discard</UiButton>
                <UiButton size="sm" @click="stopRecording">
                    <template #leading><Square class="h-3.5 w-3.5" /></template>
                    Send
                </UiButton>
            </span>
        </div>

        <!-- the ordinary case -->
        <div v-else class="flex items-end gap-2">
            <input
                ref="fileInput"
                type="file"
                accept="image/jpeg,image/png,image/webp,image/gif"
                class="hidden"
                @change="choose"
            />

            <button
                type="button"
                class="rounded-xl p-2 transition hover:bg-[var(--surface-sunken)]"
                style="color: var(--text-muted)"
                aria-label="Add a photo"
                @click="fileInput?.click()"
            >
                <ImagePlus class="h-5 w-5" />
            </button>

            <textarea
                ref="input"
                v-model="body"
                rows="1"
                placeholder="Write a message"
                class="max-h-40 min-h-[2.5rem] flex-1 resize-none rounded-xl border bg-[var(--surface)] px-3 py-2 text-sm
                       text-[var(--text-strong)] placeholder:text-[var(--text-muted)] focus:border-brand-500 focus:outline-none"
                style="border-color: var(--border-strong)"
                @keydown="onKeydown"
                @input="grow"
            />

            <button
                v-if="!body.trim() && !picked"
                type="button"
                class="rounded-xl p-2 transition hover:bg-[var(--surface-sunken)]"
                style="color: var(--text-muted)"
                aria-label="Record a voice message"
                @click="startRecording"
            >
                <Mic class="h-5 w-5" />
            </button>

            <UiButton v-else :loading="sending" size="sm" class="h-10" aria-label="Send" @click="submit">
                <Send class="h-4 w-4" />
            </UiButton>
        </div>

        <p class="mt-1.5 px-1 text-[0.68rem]" style="color: var(--text-muted)">
            Text, photos and voice notes. Anything else belongs in the documents section.
        </p>
    </div>
</template>
