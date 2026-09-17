<script setup>
/**
 * One message.
 *
 * A removed message keeps its place and says so. A hole where a message used
 * to be is worse than an honest gap, especially when the reply above it quotes
 * something that is no longer there.
 */
import { computed } from 'vue';
import { Check, CheckCheck, Reply, Trash2, Ban } from 'lucide-vue-next';

import UiAvatar from '@/components/UI/UiAvatar.vue';
import VoiceNote from '@/components/Chat/VoiceNote.vue';

const props = defineProps({
    message: { type: Object, required: true },
    showSender: { type: Boolean, default: false },
    canRemove: { type: Boolean, default: false },
});

const emit = defineEmits(['reply', 'remove', 'open', 'media-loaded']);

const mine = computed(() => props.message.mine);

/** One tick sent, two delivered, two filled read. */
const receipt = computed(() => {
    if (!mine.value || props.message.removed) return null;
    if (props.message.readBy > 0) return 'read';
    if (props.message.deliveredTo > 0) return 'delivered';

    return 'sent';
});
</script>

<template>
    <div class="group flex gap-2" :class="mine ? 'flex-row-reverse' : ''">
        <UiAvatar
            v-if="showSender && !mine"
            :name="message.sender.name"
            :src="message.sender.avatarUrl"
            size="xs"
            class="mt-auto shrink-0"
        />
        <span v-else-if="!mine" class="w-6 shrink-0" />

        <div class="max-w-[min(32rem,78%)]">
            <p
                v-if="showSender && !mine"
                class="mb-0.5 px-1 text-xs font-medium"
                style="color: var(--text-muted)"
            >
                {{ message.sender.name }}
            </p>

            <div
                class="relative rounded-2xl px-3 py-2 text-sm"
                :class="mine ? 'rounded-br-sm' : 'rounded-bl-sm'"
                :style="mine
                    ? { background: 'var(--color-brand-600)', color: '#fff' }
                    : { background: 'var(--surface-sunken)', color: 'var(--text-base)' }"
            >
                <!-- what this replies to -->
                <button
                    v-if="message.replyTo"
                    type="button"
                    class="mb-1.5 block w-full rounded-lg border-l-2 px-2 py-1 text-left text-xs"
                    :style="mine
                        ? { background: 'rgba(255,255,255,0.14)', borderColor: 'rgba(255,255,255,0.6)' }
                        : { background: 'var(--surface)', borderColor: 'var(--color-brand-500)' }"
                    @click="$emit('open', message.replyTo.id)"
                >
                    <span class="block font-medium">{{ message.replyTo.sender }}</span>
                    <span class="block truncate opacity-80">{{ message.replyTo.preview }}</span>
                </button>

                <p
                    v-if="message.removed"
                    class="flex items-center gap-1.5 italic"
                    :style="{ color: mine ? 'rgba(255,255,255,0.75)' : 'var(--text-muted)' }"
                >
                    <Ban class="h-3.5 w-3.5" />
                    This message was removed
                </p>

                <template v-else>
                    <button
                        v-if="message.kind === 'image' && message.mediaUrl"
                        type="button"
                        class="block overflow-hidden rounded-xl"
                        @click="$emit('open', message.id)"
                    >
                        <!--
                            A photo arrives with no height and then takes some,
                            which pushes the newest message off the bottom of a
                            thread that had just scrolled to it. Saying so lets
                            the thread scroll again once the space is real.
                        -->
                        <img
                            :src="message.mediaUrl"
                            :width="message.width"
                            :height="message.height"
                            alt=""
                            loading="lazy"
                            class="max-h-80 w-full object-cover"
                            @load="emit('media-loaded')"
                        />
                    </button>

                    <VoiceNote
                        v-else-if="message.kind === 'audio' && message.mediaUrl"
                        :src="message.mediaUrl"
                        :peaks="message.peaks"
                        :duration="message.duration"
                        :duration-label="message.durationLabel"
                        :mine="mine"
                    />

                    <p v-if="message.body" class="whitespace-pre-wrap break-words" :class="message.kind !== 'text' ? 'mt-1.5' : ''">
                        {{ message.body }}
                    </p>
                </template>

                <span
                    class="mt-1 flex items-center justify-end gap-1 text-[0.65rem]"
                    :style="{ color: mine ? 'rgba(255,255,255,0.75)' : 'var(--text-muted)' }"
                >
                    {{ message.sentAtLabel }}
                    <Check v-if="receipt === 'sent'" class="h-3 w-3" />
                    <CheckCheck v-else-if="receipt === 'delivered'" class="h-3 w-3" />
                    <CheckCheck v-else-if="receipt === 'read'" class="h-3 w-3" style="color: #7dd3fc" />
                </span>
            </div>
        </div>

        <!-- actions, on hover on a mouse and always within reach on a phone -->
        <div
            class="flex items-center gap-0.5 self-center opacity-0 transition group-hover:opacity-100 focus-within:opacity-100"
        >
            <button
                v-if="!message.removed"
                type="button"
                class="rounded-lg p-1.5 transition hover:bg-[var(--surface-sunken)]"
                style="color: var(--text-muted)"
                aria-label="Reply"
                @click="$emit('reply', message)"
            >
                <Reply class="h-3.5 w-3.5" />
            </button>

            <button
                v-if="!message.removed && (mine || canRemove)"
                type="button"
                class="rounded-lg p-1.5 transition hover:bg-[var(--surface-sunken)]"
                style="color: var(--text-muted)"
                aria-label="Remove"
                @click="$emit('remove', message)"
            >
                <Trash2 class="h-3.5 w-3.5" />
            </button>
        </div>
    </div>
</template>
