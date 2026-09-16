<script setup>
import { ChevronDown } from 'lucide-vue-next';

defineProps({
    modelValue: { type: [String, Number, null], default: '' },
    /** Array of { value, label } or plain strings. */
    options: { type: Array, default: () => [] },
    placeholder: { type: String, default: null },
    invalid: { type: Boolean, default: false },
    size: { type: String, default: 'md' },
});

defineEmits(['update:modelValue']);

const normalise = (option) =>
    typeof option === 'object' ? option : { value: option, label: option };
</script>

<template>
    <div class="relative">
        <select
            v-bind="$attrs"
            :value="modelValue"
            :aria-invalid="invalid || undefined"
            :class="[
                'w-full appearance-none rounded-[var(--radius-field)] border bg-[var(--surface)]',
                'pl-3 pr-9 text-[var(--text-strong)] transition-[border-color] duration-[var(--duration-fast)]',
                'disabled:cursor-not-allowed disabled:opacity-60',
                size === 'sm' ? 'h-9 text-sm' : size === 'lg' ? 'h-12 text-base' : 'h-10 text-sm',
                invalid ? 'border-danger-500' : 'border-[var(--border-strong)] focus:border-brand-500',
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
