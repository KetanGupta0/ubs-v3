<script setup>
/**
 * A checkbox, bound either to a boolean or to a list.
 *
 * Bound to a list (v-model="form.fields" with :value="'college'"), it adds or
 * removes its own value. Until November 2026 the list form was declared and
 * not handled: an empty list showed as ticked, and the first click replaced
 * the whole list with true or false.
 */
import { computed } from 'vue';
import { Check, Minus } from 'lucide-vue-next';

const props = defineProps({
    modelValue: { type: [Boolean, Array], default: false },
    value: { type: [String, Number, Boolean], default: null },
    label: { type: String, default: null },
    /** Partially selected, for a table's select-all header. */
    indeterminate: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue']);

const inList = computed(() => Array.isArray(props.modelValue));
const checked = computed(() => (inList.value ? props.modelValue.includes(props.value) : Boolean(props.modelValue)));

function toggle(event) {
    const on = event.target.checked;

    if (!inList.value) {
        emit('update:modelValue', on);
        return;
    }

    const next = props.modelValue.filter((item) => item !== props.value);
    emit('update:modelValue', on ? [...next, props.value] : next);
}
</script>

<template>
    <label :class="['inline-flex items-center gap-2.5', disabled ? 'opacity-60' : 'cursor-pointer']">
        <span class="relative inline-flex">
            <input
                type="checkbox"
                class="peer sr-only"
                :checked="indeterminate || checked"
                :disabled="disabled"
                @change="toggle"
            >
            <span
                :class="[
                    'inline-flex h-[18px] w-[18px] items-center justify-center rounded-[5px] border transition',
                    'peer-focus-visible:outline peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2',
                    indeterminate || checked
                        ? 'border-brand-600 bg-brand-600 text-white'
                        : 'border-[var(--border-strong)] bg-[var(--surface)]',
                ]"
                style="outline-color: var(--ring)"
            >
                <Minus v-if="indeterminate" class="h-3 w-3" stroke-width="3" />
                <Check v-else-if="checked" class="h-3 w-3" stroke-width="3.5" />
            </span>
        </span>

        <span v-if="label" class="text-sm" style="color: var(--text-base)">{{ label }}</span>
    </label>
</template>
