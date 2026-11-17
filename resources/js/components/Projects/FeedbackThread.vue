<script setup>
/** The steps and comments on a piece of staging feedback (CL13), both sides alike. */
import { CircleDot, MessageSquare } from 'lucide-vue-next';

defineProps({ events: { type: Array, default: () => [] } });
</script>

<template>
    <ol class="space-y-3">
        <li v-for="(event, index) in events" :key="index" class="flex gap-2.5">
            <component :is="event.status ? CircleDot : MessageSquare" class="mt-0.5 h-4 w-4 shrink-0" style="color: var(--text-muted)" aria-hidden="true" />
            <div class="min-w-0 text-sm">
                <p>
                    <span class="font-medium">{{ event.statusLabel ?? 'Comment' }}</span>
                    <span style="color: var(--text-muted)"><template v-if="event.by"> · {{ event.by }}</template> · {{ event.at }}</span>
                </p>
                <p v-if="event.body" class="mt-0.5 whitespace-pre-line break-words">{{ event.body }}</p>
            </div>
        </li>
    </ol>
</template>
