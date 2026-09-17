<script setup>
/**
 * A voice message.
 *
 * The waveform is drawn from peaks computed once when the message was
 * recorded, not by decoding the audio in every viewer's browser. Dragging the
 * bars seeks, because that is what everybody expects of a waveform and a bar
 * that does nothing when you press it is a picture pretending to be a control.
 */
import { ref, computed, onBeforeUnmount } from 'vue';
import { Play, Pause } from 'lucide-vue-next';

const props = defineProps({
    src: { type: String, required: true },
    peaks: { type: Array, default: () => [] },
    duration: { type: Number, default: null },
    durationLabel: { type: String, default: null },
    mine: { type: Boolean, default: false },
});

const audio = ref(null);
const playing = ref(false);
const at = ref(0);

// A flat line for a note recorded before peaks existed, or on a browser that
// refused to hand them over. Better than an empty box.
const bars = computed(() => (props.peaks.length ? props.peaks : Array(32).fill(0.35)));

const progress = computed(() => {
    const total = props.duration || audio.value?.duration || 0;

    return total ? Math.min(1, at.value / total) : 0;
});

const elapsed = computed(() => {
    const seconds = Math.floor(at.value);

    return `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`;
});

function toggle() {
    if (!audio.value) return;

    if (playing.value) {
        audio.value.pause();
        return;
    }

    audio.value.play().catch(() => { playing.value = false; });
}

function seek(event) {
    if (!audio.value) return;

    const box = event.currentTarget.getBoundingClientRect();
    const ratio = Math.max(0, Math.min(1, (event.clientX - box.left) / box.width));
    const total = audio.value.duration || props.duration || 0;

    if (total) {
        audio.value.currentTime = ratio * total;
        at.value = audio.value.currentTime;
    }
}

onBeforeUnmount(() => audio.value?.pause());
</script>

<template>
    <div class="flex items-center gap-3">
        <audio
            ref="audio"
            :src="src"
            preload="none"
            @play="playing = true"
            @pause="playing = false"
            @ended="playing = false; at = 0"
            @timeupdate="at = $event.target.currentTime"
        />

        <button
            type="button"
            class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full transition"
            :style="{
                background: mine ? 'rgba(255,255,255,0.22)' : 'var(--surface-sunken)',
                color: mine ? '#fff' : 'var(--text-strong)',
            }"
            :aria-label="playing ? 'Pause' : 'Play'"
            @click="toggle"
        >
            <component :is="playing ? Pause : Play" class="h-4 w-4" />
        </button>

        <button
            type="button"
            class="flex h-8 min-w-[7rem] flex-1 items-center gap-[2px]"
            aria-label="Seek"
            @click="seek"
        >
            <span
                v-for="(peak, index) in bars"
                :key="index"
                class="w-[3px] shrink-0 rounded-full transition-[background-color]"
                :style="{
                    height: `${Math.max(12, peak * 100)}%`,
                    background: index / bars.length <= progress
                        ? (mine ? '#fff' : 'var(--color-brand-500)')
                        : (mine ? 'rgba(255,255,255,0.4)' : 'var(--border-strong)'),
                }"
            />
        </button>

        <span
            class="shrink-0 text-xs tnum"
            :style="{ color: mine ? 'rgba(255,255,255,0.8)' : 'var(--text-muted)' }"
        >
            {{ playing || at > 0 ? elapsed : (durationLabel ?? '0:00') }}
        </span>
    </div>
</template>
