<script setup>
/**
 * Tab bar with an animated underline. Scrolls horizontally on phones instead
 * of wrapping, which keeps the row a single predictable line.
 */
defineProps({
    modelValue: { type: [String, Number], required: true },
    /** Array of { value, label, count? }. */
    tabs: { type: Array, required: true },
});

defineEmits(['update:modelValue']);
</script>

<template>
    <div
        class="-mx-4 overflow-x-auto px-4 scrollbar-thin sm:mx-0 sm:px-0"
        role="tablist"
    >
        <div class="flex min-w-max gap-1 border-b" style="border-color: var(--border-subtle)">
            <button
                v-for="tab in tabs"
                :key="tab.value"
                type="button"
                role="tab"
                :aria-selected="modelValue === tab.value"
                :class="[
                    'relative flex items-center gap-2 px-3.5 py-2.5 text-sm font-medium transition-colors',
                    modelValue === tab.value
                        ? 'text-brand-600 dark:text-brand-400'
                        : 'text-[var(--text-muted)] hover:text-[var(--text-strong)]',
                ]"
                @click="$emit('update:modelValue', tab.value)"
            >
                {{ tab.label }}

                <span
                    v-if="tab.count != null"
                    class="rounded-full bg-[var(--surface-sunken)] px-1.5 py-0.5 text-[0.68rem] tnum"
                >
                    {{ tab.count }}
                </span>

                <span
                    v-if="modelValue === tab.value"
                    class="absolute inset-x-2 -bottom-px h-0.5 rounded-full bg-brand-600 dark:bg-brand-400"
                />
            </button>
        </div>
    </div>
</template>
