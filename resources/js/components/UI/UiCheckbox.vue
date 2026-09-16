<script setup>
import { Check, Minus } from 'lucide-vue-next';

defineProps({
    modelValue: { type: [Boolean, Array], default: false },
    value: { type: [String, Number, Boolean], default: null },
    label: { type: String, default: null },
    /** Partially selected, for a table's select-all header. */
    indeterminate: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue']);

function toggle(event) {
    const checked = event.target.checked;
    emit('update:modelValue', checked);
}
</script>

<template>
    <label :class="['inline-flex items-center gap-2.5', disabled ? 'opacity-60' : 'cursor-pointer']">
        <span class="relative inline-flex">
            <input
                type="checkbox"
                class="peer sr-only"
                :checked="indeterminate || Boolean(modelValue)"
                :disabled="disabled"
                @change="toggle"
            >
            <span
                :class="[
                    'inline-flex h-[18px] w-[18px] items-center justify-center rounded-[5px] border transition',
                    'peer-focus-visible:outline peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2',
                    indeterminate || modelValue
                        ? 'border-brand-600 bg-brand-600 text-white'
                        : 'border-[var(--border-strong)] bg-[var(--surface)]',
                ]"
                style="outline-color: var(--ring)"
            >
                <Minus v-if="indeterminate" class="h-3 w-3" stroke-width="3" />
                <Check v-else-if="modelValue" class="h-3 w-3" stroke-width="3.5" />
            </span>
        </span>

        <span v-if="label" class="text-sm" style="color: var(--text-base)">{{ label }}</span>
    </label>
</template>
