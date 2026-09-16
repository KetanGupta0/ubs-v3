<script setup>
import { ChevronDown } from 'lucide-vue-next';
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
    modelValue: { type: [String, Number, null], default: '' },
    /** Array of { value, label } or plain strings. */
    options: { type: Array, default: () => [] },
    placeholder: { type: String, default: null },
    invalid: { type: Boolean, default: false },
    size: { type: String, default: 'md' },
});

defineEmits(['update:modelValue']);

const field = useField();
const isInvalid = computed(() => props.invalid || field.value.invalid);

const normalise = (option) =>
    typeof option === 'object' ? option : { value: option, label: option };
</script>

<template>
    <div class="relative">
        <select
            :id="field.id"
            :aria-describedby="field.describedBy"
            v-bind="$attrs"
            :value="modelValue"
            :aria-invalid="isInvalid || undefined"
            :class="[
                'w-full appearance-none rounded-[var(--radius-field)] border bg-[var(--surface)]',
                'pl-3 pr-9 text-[var(--text-strong)] transition-[border-color] duration-[var(--duration-fast)]',
                'disabled:cursor-not-allowed disabled:opacity-60',
                size === 'sm' ? 'h-9 text-sm' : size === 'lg' ? 'h-12 text-base' : 'h-10 text-sm',
                isInvalid ? 'border-danger-500' : 'border-[var(--border-strong)] focus:border-brand-500',
            ]"
            @change="$emit('update:modelValue', $event.target.value)"
        >
            <option v-if="placeholder" value="" disabled :selected="!modelValue">
                {{ placeholder }}
            </option>
            <option
                v-for="option in options.map(normalise)"
                :key="option.value"
                :value="option.value"
            >
                {{ option.label }}
            </option>
        </select>

        <ChevronDown
            class="pointer-events-none absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2"
            style="color: var(--text-muted)"
            aria-hidden="true"
        />
    </div>
</template>
