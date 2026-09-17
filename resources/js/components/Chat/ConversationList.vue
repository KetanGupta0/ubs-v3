<script setup>
/**
 * Which rooms this person has.
 *
 * The badge means different things for different people, on purpose: a client
 * or a student sees what they have not read, and a member of staff sees which
 * threads are waiting on an answer. A staff unread count would either be zero
 * or every message in the room, and neither is worth looking at.
 */
import { Link } from '@inertiajs/vue3';
import { MessagesSquare, Users } from 'lucide-vue-next';

import UiAvatar from '@/components/UI/UiAvatar.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiEmptyState from '@/components/UI/UiEmptyState.vue';

defineProps({
    conversations: { type: Array, default: () => [] },
    activeId: { type: Number, default: null },
    staffView: { type: Boolean, default: false },
});
</script>

<template>
    <div class="flex h-full flex-col">
        <UiEmptyState
            v-if="!conversations.length"
            :icon="MessagesSquare"
            title="No conversations yet"
            description="A thread appears here as soon as there is somebody to talk to."
        />

        <ul v-else class="min-h-0 flex-1 overflow-y-auto">
            <li v-for="room in conversations" :key="room.id">
                <Link
                    :href="`/chat/${room.id}`"
                    class="flex items-start gap-3 border-b px-3 py-3 transition hover:bg-[var(--surface-sunken)]"
                    :style="{
                        borderColor: 'var(--border-subtle)',
                        background: room.id === activeId ? 'var(--surface-sunken)' : undefined,
                    }"
                    preserve-scroll
                >
                    <span class="shrink-0">
                        <span
                            v-if="room.type === 'batch_group'"
                            class="inline-flex h-9 w-9 items-center justify-center rounded-full"
                            style="background: var(--color-brand-50); color: var(--color-brand-600)"
                        >
                            <Users class="h-4 w-4" />
                        </span>
                        <UiAvatar v-else :name="room.title" :src="room.avatarUrl" size="sm" />
                    </span>

                    <span class="min-w-0 flex-1">
                        <span class="flex items-baseline justify-between gap-2">
                            <span class="truncate text-sm font-medium">{{ room.title }}</span>
                            <span class="shrink-0 text-[0.68rem]" style="color: var(--text-muted)">
                                {{ room.at }}
                            </span>
                        </span>

                        <span v-if="room.subtitle" class="block truncate text-[0.7rem]" style="color: var(--text-muted)">
                            {{ room.subtitle }}
                        </span>

                        <span class="mt-0.5 flex items-center justify-between gap-2">
                            <span class="truncate text-xs" style="color: var(--text-muted)">
                                {{ room.preview || 'No messages yet' }}
                            </span>

                            <UiBadge v-if="room.unread" tone="brand" size="sm">{{ room.unread }}</UiBadge>
                            <UiBadge v-else-if="staffView && room.awaitingReply" tone="warning" size="sm">
                                waiting
                            </UiBadge>
                        </span>
                    </span>
                </Link>
            </li>
        </ul>
    </div>
</template>
