<script setup>
/**
 * Date and date range input.
 *
 * Deliberately built on the native date control. It gives every platform its
 * own familiar picker, works with screen readers and keyboards for free, and
 * on phones it opens the OS wheel rather than a cramped custom calendar.
 */
import { computed } from 'vue';
import { Calendar } from 'lucide-vue-next';

const props = defineProps({
    modelValue: { type: [String, Object, null], default: null },
    /** true renders a from/to pair bound to { from, to }. */
    range: { type: Boolean, default: false },
    min: { type: String, default: null },
    max: { type: String, default: null },
    invalid: { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue']);

const value = computed(() => props.modelValue ?? (props.range ? { from: '', to: '' } : ''));

function updateRange(key, next) {
    emit('update:modelValue', { ...value.value, [key]: next });
}

const fieldClass = computed(() => [
    'h-10 w-full rounded-[var(--radius-field)] border bg-[var(--surface)] pl-9 pr-3 text-sm',
    'text-[var(--text-strong)] transition-[border-color]',
    props.invalid ? 'border-danger-500' : 'border-[var(--border-strong)] focus:border-brand-500',
]);
</script>

<template>
    <div :class="range ? 'grid grid-cols-1 gap-2 sm:grid-cols-2' : ''">
        <template v-if="range">
            <span class="relative">
                <Calendar class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2" style="color: var(--text-muted)" />
                <input
                    type="date"
                    :value="value.from"
                    :min="min"
                    :max="value.to || max"
                    aria-label="From date"
                    :class="fieldClass"
                    @input="updateRange('from', $event.target.value)"
                >
            </span>
            <span class="relative">
                <Calendar class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2" style="color: var(--text-muted)" />
                <input
                    type="date"
                    :value="value.to"
                    :min="value.from || min"
                    :max="max"
                    aria-label="To date"
                    :class="fieldClass"
                    @input="updateRange('to', $event.target.value)"
                >
            </span>
        </template>

        <span v-else class="relative block">
            <Calendar class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2" style="color: var(--text-muted)" />
            <input
                v-bind="$attrs"
                type="date"
                :value="value"
                :min="min"
                :max="max"
                :class="fieldClass"
                @input="emit('update:modelValue', $event.target.value)"
            >
        </span>
    </div>
</template>
