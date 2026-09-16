<script setup>
/**
 * Label, hint, error and required marker for a single control.
 *
 * It generates the id and wires aria-describedby/aria-invalid through a slot
 * prop, so every field in the app is accessible without each call site
 * remembering to do it.
 */
import { computed, useId } from 'vue';

import { provideField } from '@/composables/useField';

const props = defineProps({
    label: { type: String, default: null },
    hint: { type: String, default: null },
    error: { type: String, default: null },
    required: { type: Boolean, default: false },
    /** Render label and control side by side on wide screens. */
    horizontal: { type: Boolean, default: false },
});

const uid = useId();
const fieldId = computed(() => `field-${uid}`);
const hintId = computed(() => `hint-${uid}`);
const errorId = computed(() => `error-${uid}`);

const describedBy = computed(() => {
    const ids = [];
    if (props.hint) ids.push(hintId.value);
    if (props.error) ids.push(errorId.value);
    return ids.length ? ids.join(' ') : undefined;
});

provideField(computed(() => ({
    id: fieldId.value,
    describedBy: describedBy.value,
    invalid: Boolean(props.error),
})));
</script>

<template>
    <div :class="horizontal ? 'sm:grid sm:grid-cols-3 sm:gap-4 sm:items-start' : 'space-y-1.5'">
        <label
            v-if="label"
            :for="fieldId"
            class="block text-sm font-medium"
            style="color: var(--text-strong)"
        >
            {{ label }}
            <span v-if="required" class="text-danger-500" aria-hidden="true">*</span>
        </label>

        <div :class="horizontal ? 'sm:col-span-2 space-y-1.5' : 'space-y-1.5'">
            <slot
                :id="fieldId"
                :described-by="describedBy"
                :invalid="Boolean(error)"
            />

            <p v-if="hint && !error" :id="hintId" class="text-xs" style="color: var(--text-muted)">
                {{ hint }}
            </p>

            <p v-if="error" :id="errorId" class="text-xs font-medium text-danger-500">
                {{ error }}
            </p>
        </div>
    </div>
</template>
