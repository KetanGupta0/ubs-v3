<script setup>
defineProps({
    modelValue: { type: Boolean, default: false },
    label: { type: String, default: null },
    description: { type: String, default: null },
    disabled: { type: Boolean, default: false },
});

defineEmits(['update:modelValue']);
</script>

<template>
    <label :class="['flex items-start gap-3', disabled ? 'opacity-60' : 'cursor-pointer']">
        <button
            type="button"
            role="switch"
            :aria-checked="modelValue"
            :aria-label="label || undefined"
            :disabled="disabled"
            :class="[
                'relative mt-0.5 inline-flex h-6 w-11 shrink-0 rounded-full transition-colors duration-[var(--duration-fast)]',
                'disabled:cursor-not-allowed',
                modelValue ? 'bg-brand-600' : 'bg-ink-300 dark:bg-ink-700',
            ]"
            @click="$emit('update:modelValue', !modelValue)"
        >
            <span
                :class="[
                    'absolute top-0.5 left-0.5 h-5 w-5 rounded-full bg-white shadow-sm',
                    'transition-transform duration-[var(--duration-fast)] ease-[var(--ease-spring)]',
                    modelValue && 'translate-x-5',
                ]"
            />
        </button>

        <span v-if="label || description" class="min-w-0">
            <span v-if="label" class="block text-sm font-medium" style="color: var(--text-strong)">
                {{ label }}
            </span>
            <span v-if="description" class="block text-xs" style="color: var(--text-muted)">
                {{ description }}
            </span>
        </span>
    </label>
</template>
