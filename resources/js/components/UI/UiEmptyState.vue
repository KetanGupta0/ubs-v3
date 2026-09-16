<script setup>
import { Inbox } from 'lucide-vue-next';

defineProps({
    title: { type: String, required: true },
    description: { type: String, default: null },
    /** Lucide component. Defaults to a generic inbox. */
    icon: { type: [Object, Function], default: null },
});
</script>

<template>
    <div class="flex flex-col items-center justify-center px-6 py-14 text-center">
        <span
            class="mb-4 inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-[var(--surface-sunken)]"
            style="color: var(--text-muted)"
        >
            <component :is="icon || Inbox" class="h-5 w-5" aria-hidden="true" />
        </span>

        <h3 class="text-base font-semibold">{{ title }}</h3>
        <p v-if="description" class="mt-1 max-w-sm text-sm" style="color: var(--text-muted)">
            {{ description }}
        </p>

        <div v-if="$slots.action" class="mt-5">
            <slot name="action" />
        </div>
    </div>
</template>
