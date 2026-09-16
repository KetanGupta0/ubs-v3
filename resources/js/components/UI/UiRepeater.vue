<script setup>
/**
 * Edits a list of short strings, such as the industries a solution serves.
 *
 * Entries are added by typing and pressing Enter, which is how people expect a
 * tag field to behave, and removed with a single click. Order is preserved
 * because it is the order these appear on the public site.
 */
import { ref, nextTick } from 'vue';
import { X, Plus } from 'lucide-vue-next';

const props = defineProps({
    modelValue: { type: Array, default: () => [] },
    placeholder: { type: String, default: 'Type and press Enter' },
    /** Rendered as chips rather than rows. Right for short values. */
    chips: { type: Boolean, default: false },
    max: { type: Number, default: 40 },
});

const emit = defineEmits(['update:modelValue']);

const draft = ref('');
const input = ref(null);

function add() {
    const value = draft.value.trim();

    if (!value || props.modelValue.length >= props.max) return;
    if (props.modelValue.includes(value)) {
        draft.value = '';

        return;
    }

    emit('update:modelValue', [...props.modelValue, value]);
    draft.value = '';
}

function remove(index) {
    const next = [...props.modelValue];
    next.splice(index, 1);
    emit('update:modelValue', next);
}

function update(index, value) {
    const next = [...props.modelValue];
    next[index] = value;
    emit('update:modelValue', next);
}

/** Backspace on an empty box removes the last entry, as a tag field does. */
async function onBackspace() {
    if (draft.value === '' && props.modelValue.length) {
        remove(props.modelValue.length - 1);
        await nextTick();
        input.value?.focus();
    }
}
</script>

<template>
    <div class="space-y-2">
        <div v-if="chips && modelValue.length" class="flex flex-wrap gap-1.5">
            <span
                v-for="(item, index) in modelValue"
                :key="`${item}-${index}`"
                class="inline-flex items-center gap-1.5 rounded-full py-1 pl-3 pr-1.5 text-sm"
                style="background: var(--surface-sunken); color: var(--text-base)"
            >
                {{ item }}
                <button
                    type="button"
                    class="rounded-full p-0.5 transition hover:bg-[var(--border-subtle)]"
                    :aria-label="`Remove ${item}`"
                    @click="remove(index)"
                >
                    <X class="h-3 w-3" />
                </button>
            </span>
        </div>

        <ul v-else-if="modelValue.length" class="space-y-1.5">
            <li v-for="(item, index) in modelValue" :key="index" class="flex items-center gap-2">
                <input
                    :value="item"
                    type="text"
                    class="h-9 min-w-0 flex-1 rounded-[var(--radius-field)] border bg-[var(--surface)] px-3 text-sm"
                    style="border-color: var(--border-strong); color: var(--text-strong)"
                    @input="update(index, $event.target.value)"
                >
                <button
                    type="button"
                    class="shrink-0 rounded-lg p-2 transition hover:bg-[var(--surface-sunken)]"
                    style="color: var(--text-muted)"
                    aria-label="Remove this entry"
                    @click="remove(index)"
                >
                    <X class="h-3.5 w-3.5" />
                </button>
            </li>
        </ul>

        <div class="flex gap-2">
            <input
                ref="input"
                v-model="draft"
                type="text"
                :placeholder="placeholder"
                class="h-9 min-w-0 flex-1 rounded-[var(--radius-field)] border bg-[var(--surface)] px-3 text-sm"
                style="border-color: var(--border-strong); color: var(--text-strong)"
                @keydown.enter.prevent="add"
                @keydown.backspace="onBackspace"
            >
            <button
                type="button"
                class="inline-flex h-9 shrink-0 items-center gap-1.5 rounded-[var(--radius-field)] border px-3 text-sm font-medium transition hover:bg-[var(--surface-sunken)]"
                style="border-color: var(--border-strong); color: var(--text-base)"
                :disabled="!draft.trim()"
                @click="add"
            >
                <Plus class="h-3.5 w-3.5" /> Add
            </button>
        </div>

        <p v-if="modelValue.length >= max" class="text-xs" style="color: var(--text-muted)">
            That is the maximum of {{ max }}.
        </p>
    </div>
</template>
