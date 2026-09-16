<script setup>
defineProps({
    modelValue: { type: String, default: '' },
    rows: { type: Number, default: 4 },
    invalid: { type: Boolean, default: false },
});

defineEmits(['update:modelValue']);
</script>

<template>
    <textarea
        v-bind="$attrs"
        :value="modelValue"
        :rows="rows"
        :aria-invalid="invalid || undefined"
        :class="[
            'w-full rounded-[var(--radius-field)] border bg-[var(--surface)] px-3 py-2 text-sm',
            'text-[var(--text-strong)] placeholder:text-[var(--text-muted)]',
            'transition-[border-color] duration-[var(--duration-fast)] resize-y',
            'disabled:cursor-not-allowed disabled:opacity-60',
            invalid ? 'border-danger-500' : 'border-[var(--border-strong)] focus:border-brand-500',
        ]"
        @input="$emit('update:modelValue', $event.target.value)"
    />
</template>
