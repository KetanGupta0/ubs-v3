<script setup>
defineProps({
    title: { type: String, required: true },
    description: { type: String, default: null },
});
</script>

<template>
    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div class="min-w-0">
            <h1 class="text-xl font-semibold sm:text-2xl">{{ title }}</h1>
            <p v-if="description" class="mt-1.5 max-w-2xl text-sm" style="color: var(--text-muted)">
                {{ description }}
            </p>
        </div>

        <div v-if="$slots.actions" class="flex flex-wrap items-center gap-2">
            <slot name="actions" />
        </div>
    </div>
</template>
