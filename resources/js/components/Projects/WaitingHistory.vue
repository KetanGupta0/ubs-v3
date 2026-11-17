<script setup>
/**
 * The steps of a "waiting on you" item (CL10): asked, replied, asked again,
 * done. Both sides read the same list, so nobody's memory of who sent what
 * when is the record.
 */
import { CheckCircle2, CornerDownRight, MessageSquareReply, Paperclip, RotateCcw, XCircle } from 'lucide-vue-next';

defineProps({
    events: { type: Array, default: () => [] },
    /** Where a file downloads from, given its id. */
    fileHref: { type: Function, default: null },
});

const labels = {
    raised: 'Asked',
    answered: 'Replied',
    reopened: 'Asked again',
    closed: 'Marked done',
    cancelled: 'No longer needed',
};

const icons = {
    raised: CornerDownRight,
    answered: MessageSquareReply,
    reopened: RotateCcw,
    closed: CheckCircle2,
    cancelled: XCircle,
};
</script>

<template>
    <ol class="space-y-3">
        <li v-for="(event, index) in events" :key="index" class="flex gap-2.5">
            <component :is="icons[event.action] ?? CornerDownRight" class="mt-0.5 h-4 w-4 shrink-0" style="color: var(--text-muted)" aria-hidden="true" />
            <div class="min-w-0 text-sm">
                <p>
                    <span class="font-medium">{{ labels[event.action] ?? event.action }}</span>
                    <span style="color: var(--text-muted)"><template v-if="event.by"> by {{ event.by }}</template> · {{ event.at }}</span>
                </p>
                <p v-if="event.body" class="mt-0.5 whitespace-pre-line break-words">{{ event.body }}</p>
                <ul v-if="event.files.length" class="mt-1 space-y-0.5">
                    <li v-for="file in event.files" :key="file.id" class="flex items-center gap-1.5 text-xs">
                        <Paperclip class="h-3 w-3 shrink-0" style="color: var(--text-muted)" aria-hidden="true" />
                        <a v-if="fileHref" :href="fileHref(file.id)" class="truncate font-medium text-brand-600 hover:underline dark:text-brand-400">{{ file.name }}</a>
                        <span v-else class="truncate">{{ file.name }}</span>
                        <span style="color: var(--text-muted)">{{ file.size }}</span>
                    </li>
                </ul>
            </div>
        </li>
    </ol>
</template>
