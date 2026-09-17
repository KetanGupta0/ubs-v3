<script setup>
/**
 * Everything shared in one room.
 *
 * Scrolling a year of conversation to find the screenshot somebody sent is the
 * most common thing anybody does in a chat application, and the least pleasant.
 */
import { ref, watch } from 'vue';
import { ImageOff } from 'lucide-vue-next';

import UiModal from '@/components/UI/UiModal.vue';
import UiEmptyState from '@/components/UI/UiEmptyState.vue';
import VoiceNote from '@/components/Chat/VoiceNote.vue';

const props = defineProps({
    open: { type: Boolean, default: false },
    conversationId: { type: Number, required: true },
});

defineEmits(['close']);

const media = ref([]);
const loading = ref(false);

watch(() => props.open, async (isOpen) => {
    if (!isOpen) return;

    loading.value = true;

    try {
        const response = await fetch(`/chat/${props.conversationId}/gallery`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });

        media.value = response.ok ? (await response.json()).media : [];
    } finally {
        loading.value = false;
    }
});

const photos = () => media.value.filter((item) => item.kind === 'image');
const voice = () => media.value.filter((item) => item.kind === 'audio');
</script>

<template>
    <UiModal :open="open" size="lg" title="Shared in this conversation" @close="$emit('close')">
        <p v-if="loading" class="py-6 text-center text-sm" style="color: var(--text-muted)">Loading…</p>

        <UiEmptyState
            v-else-if="!media.length"
            :icon="ImageOff"
            title="Nothing shared yet"
            description="Photos and voice notes sent here collect in this list."
        />

        <div v-else class="space-y-5">
            <section v-if="photos().length">
                <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide" style="color: var(--text-muted)">
                    Photos
                </h3>

                <div class="grid grid-cols-3 gap-2 sm:grid-cols-4">
                    <a
                        v-for="item in photos()"
                        :key="item.id"
                        :href="item.mediaUrl"
                        target="_blank"
                        rel="noopener"
                        class="block overflow-hidden rounded-lg"
                    >
                        <img :src="item.mediaUrl" alt="" loading="lazy" class="aspect-square w-full object-cover" />
                    </a>
                </div>
            </section>

            <section v-if="voice().length">
                <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide" style="color: var(--text-muted)">
                    Voice messages
                </h3>

                <ul class="space-y-2">
                    <li
                        v-for="item in voice()"
                        :key="item.id"
                        class="rounded-lg p-2.5"
                        style="background: var(--surface-sunken)"
                    >
                        <p class="mb-1 text-xs" style="color: var(--text-muted)">
                            {{ item.sender.name }} · {{ item.dayLabel }} {{ item.sentAtLabel }}
                        </p>
                        <VoiceNote
                            :src="item.mediaUrl"
                            :peaks="item.peaks"
                            :duration="item.duration"
                            :duration-label="item.durationLabel"
                        />
                    </li>
                </ul>
            </section>
        </div>
    </UiModal>
</template>
