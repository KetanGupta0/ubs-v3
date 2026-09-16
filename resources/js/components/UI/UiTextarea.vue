<script setup>
import { computed } from 'vue';

import { useField } from '@/composables/useField';

const props = defineProps({
    modelValue: { type: String, default: '' },
    rows: { type: Number, default: 4 },
    invalid: { type: Boolean, default: false },
});

defineEmits(['update:modelValue']);

const field = useField();
const isInvalid = computed(() => props.invalid || field.value.invalid);
</script>

<template>
    <textarea
        :id="field.id"
        :aria-describedby="field.describedBy"
        v-bind="$attrs"
        :value="modelValue"
        :rows="rows"
        :aria-invalid="isInvalid || undefined"
        :class="[
            'w-full rounded-[var(--radius-field)] border bg-[var(--surface)] px-3 py-2 text-sm',
            'text-[var(--text-strong)] placeholder:text-[var(--text-muted)]',
            'transition-[border-color] duration-[var(--duration-fast)] resize-y',
            'disabled:cursor-not-allowed disabled:opacity-60',
            isInvalid ? 'border-danger-500' : 'border-[var(--border-strong)] focus:border-brand-500',
        ]"
        @input="$emit('update:modelValue', $event.target.value)"
    />
</template>
