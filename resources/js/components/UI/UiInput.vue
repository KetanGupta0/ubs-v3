<script setup>
import { computed } from 'vue';

import { useField } from '@/composables/useField';

/*
 * Attributes are placed on the control, never on the wrapper. Left to fall
 * through, an id passed in here would land on the outer element too, and a
 * label pointing at that id would resolve to the wrapper instead of the input,
 * which is a label that looks right and does nothing.
 */
defineOptions({ inheritAttrs: false });

const props = defineProps({
    modelValue: { type: [String, Number], default: '' },
    type: { type: String, default: 'text' },
    size: { type: String, default: 'md' },
    invalid: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
});

defineEmits(['update:modelValue']);

const field = useField();
const isInvalid = computed(() => props.invalid || field.value.invalid);

const sizes = {
    sm: 'h-9 text-sm',
    md: 'h-10 text-sm',
    lg: 'h-12 text-base',
};

const classes = computed(() => [
    'w-full rounded-[var(--radius-field)] border bg-[var(--surface)] px-3',
    'text-[var(--text-strong)] placeholder:text-[var(--text-muted)]',
    'transition-[border-color,box-shadow] duration-[var(--duration-fast)]',
    'disabled:cursor-not-allowed disabled:opacity-60',
    sizes[props.size],
    isInvalid.value
        ? 'border-danger-500 focus:border-danger-500'
        : 'border-[var(--border-strong)] focus:border-brand-500',
]);
</script>

<template>
    <div class="relative">
        <span
            v-if="$slots.leading"
            class="pointer-events-none absolute inset-y-0 left-3 flex items-center"
            style="color: var(--text-muted)"
        >
            <slot name="leading" />
        </span>

        <input
            :id="field.id"
            :aria-describedby="field.describedBy"
            v-bind="$attrs"
            :type="type"
            :value="modelValue"
            :disabled="disabled"
            :aria-invalid="isInvalid || undefined"
            :class="[classes, $slots.leading && 'pl-10', $slots.trailing && 'pr-10']"
            @input="$emit('update:modelValue', $event.target.value)"
        >

        <span v-if="$slots.trailing" class="absolute inset-y-0 right-2 flex items-center">
            <slot name="trailing" />
        </span>
    </div>
</template>
