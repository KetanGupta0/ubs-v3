<script setup>
defineProps({
    title: { type: String, required: true },
    description: { type: String, default: null },
});
</script>

<template>
    <section
        class="rounded-[var(--radius-card)] border bg-[var(--surface)]"
        style="border-color: var(--border-subtle)"
    >
        <header class="border-b px-5 py-4" style="border-color: var(--border-subtle)">
            <h2 class="text-base font-semibold">{{ title }}</h2>
            <p v-if="description" class="mt-1 text-sm" style="color: var(--text-muted)">{{ description }}</p>
        </header>

        <div class="space-y-4 p-5">
            <slot />
        </div>
    </section>
</template>
