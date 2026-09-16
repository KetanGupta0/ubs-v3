<script setup>
/**
 * Edits a list of small objects, such as a feature with a title and a body.
 *
 * The shape is described by `fields`, so one component covers features,
 * engagement models, process steps, syllabus modules and the internship
 * documents, rather than five near identical editors that drift apart.
 */
import { GripVertical, Trash2, Plus, ChevronUp, ChevronDown } from 'lucide-vue-next';

import UiRepeater from './UiRepeater.vue';

const props = defineProps({
    modelValue: { type: Array, default: () => [] },
    /** [{ key, label, type: 'text'|'textarea'|'list', placeholder?, rows? }] */
    fields: { type: Array, required: true },
    addLabel: { type: String, default: 'Add another' },
    /** Used as the collapsed heading for each row. */
    titleKey: { type: String, default: null },
    max: { type: Number, default: 30 },
});

const emit = defineEmits(['update:modelValue']);

function blank() {
    return Object.fromEntries(props.fields.map((field) => [field.key, field.type === 'list' ? [] : '']));
}

function add() {
    if (props.modelValue.length >= props.max) return;
    emit('update:modelValue', [...props.modelValue, blank()]);
}

function remove(index) {
    const next = [...props.modelValue];
    next.splice(index, 1);
    emit('update:modelValue', next);
}

function set(index, key, value) {
    const next = props.modelValue.map((item, i) => (i === index ? { ...item, [key]: value } : item));
    emit('update:modelValue', next);
}

/** Order matters on the public page, so it has to be changeable. */
function move(index, direction) {
    const target = index + direction;
    if (target < 0 || target >= props.modelValue.length) return;

    const next = [...props.modelValue];
    [next[index], next[target]] = [next[target], next[index]];
    emit('update:modelValue', next);
}
</script>

<template>
    <div class="space-y-3">
        <div
            v-for="(item, index) in modelValue"
            :key="index"
            class="rounded-xl border p-4"
            style="border-color: var(--border-subtle); background: var(--surface-sunken)"
        >
            <div class="mb-3 flex items-center justify-between gap-2">
                <span class="flex items-center gap-2 text-xs font-semibold uppercase tracking-wide" style="color: var(--text-muted)">
                    <GripVertical class="h-3.5 w-3.5" aria-hidden="true" />
                    {{ titleKey && item[titleKey] ? item[titleKey] : `Item ${index + 1}` }}
                </span>

                <span class="flex items-center gap-0.5">
                    <button
                        type="button"
                        class="rounded p-1.5 transition hover:bg-[var(--surface)] disabled:opacity-30"
                        style="color: var(--text-muted)"
                        :disabled="index === 0"
                        aria-label="Move up"
                        @click="move(index, -1)"
                    >
                        <ChevronUp class="h-3.5 w-3.5" />
                    </button>
                    <button
                        type="button"
                        class="rounded p-1.5 transition hover:bg-[var(--surface)] disabled:opacity-30"
                        style="color: var(--text-muted)"
                        :disabled="index === modelValue.length - 1"
                        aria-label="Move down"
                        @click="move(index, 1)"
                    >
                        <ChevronDown class="h-3.5 w-3.5" />
                    </button>
                    <button
                        type="button"
                        class="rounded p-1.5 text-danger-500 transition hover:bg-red-50 dark:hover:bg-red-950/40"
                        aria-label="Remove this item"
                        @click="remove(index)"
                    >
                        <Trash2 class="h-3.5 w-3.5" />
                    </button>
                </span>
            </div>

            <div class="space-y-3">
                <div v-for="field in fields" :key="field.key">
                    <label class="mb-1 block text-xs font-medium" style="color: var(--text-muted)">
                        {{ field.label }}
                    </label>

                    <textarea
                        v-if="field.type === 'textarea'"
                        :value="item[field.key]"
                        :rows="field.rows ?? 2"
                        :placeholder="field.placeholder"
                        class="w-full rounded-[var(--radius-field)] border bg-[var(--surface)] px-3 py-2 text-sm"
                        style="border-color: var(--border-strong); color: var(--text-strong)"
                        @input="set(index, field.key, $event.target.value)"
                    />

                    <UiRepeater
                        v-else-if="field.type === 'list'"
                        :model-value="item[field.key] ?? []"
                        :placeholder="field.placeholder ?? 'Type and press Enter'"
                        @update:model-value="set(index, field.key, $event)"
                    />

                    <input
                        v-else
                        :value="item[field.key]"
                        type="text"
                        :placeholder="field.placeholder"
                        class="h-9 w-full rounded-[var(--radius-field)] border bg-[var(--surface)] px-3 text-sm"
                        style="border-color: var(--border-strong); color: var(--text-strong)"
                        @input="set(index, field.key, $event.target.value)"
                    >
                </div>
            </div>
        </div>

        <button
            type="button"
            class="inline-flex h-9 items-center gap-1.5 rounded-[var(--radius-field)] border border-dashed px-3 text-sm font-medium transition hover:bg-[var(--surface-sunken)] disabled:opacity-50"
            style="border-color: var(--border-strong); color: var(--text-base)"
            :disabled="modelValue.length >= max"
            @click="add"
        >
            <Plus class="h-3.5 w-3.5" /> {{ addLabel }}
        </button>
    </div>
</template>
