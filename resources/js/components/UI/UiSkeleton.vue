<script setup>
defineProps({
    class: { type: String, default: 'h-4 w-full' },
});
</script>

<template>
    <span
        class="relative block overflow-hidden rounded-md bg-[var(--surface-sunken)] dark:bg-ink-800"
        :class="$props.class"
        aria-hidden="true"
    >
        <span
            class="absolute inset-0 -translate-x-full bg-gradient-to-r from-transparent via-white/40 to-transparent dark:via-white/8"
            style="animation: ub-shimmer 1.6s infinite"
        />
    </span>
</template>
