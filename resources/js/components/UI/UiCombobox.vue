<script setup>
/**
 * Searchable single select with keyboard navigation.
 *
 * Used anywhere a plain <select> would be too long to scan: assigning a client
 * to a project, picking a batch, choosing a course.
 */
import { ref, computed, nextTick, onMounted, onBeforeUnmount } from 'vue';
import { Check, ChevronsUpDown, Search } from 'lucide-vue-next';

const props = defineProps({
    modelValue: { type: [String, Number, null], default: null },
    /** Array of { value, label, description? }. */
    options: { type: Array, default: () => [] },
    placeholder: { type: String, default: 'Select…' },
    searchPlaceholder: { type: String, default: 'Search…' },
    emptyText: { type: String, default: 'No matches' },
    invalid: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue']);

const open = ref(false);
const query = ref('');
const highlighted = ref(0);
const root = ref(null);
const searchInput = ref(null);

const selected = computed(() =>
    props.options.find((option) => option.value === props.modelValue) ?? null,
);

const filtered = computed(() => {
    const q = query.value.trim().toLowerCase();
    if (!q) return props.options;

    return props.options.filter(
        (option) =>
            option.label.toLowerCase().includes(q) ||
            option.description?.toLowerCase().includes(q),
    );
});

async function toggle() {
    if (props.disabled) return;

    open.value = !open.value;
    if (open.value) {
        query.value = '';
        highlighted.value = Math.max(
            0,
            filtered.value.findIndex((o) => o.value === props.modelValue),
        );
        await nextTick();
        searchInput.value?.focus();
    }
}

function choose(option) {
    emit('update:modelValue', option.value);
    open.value = false;
}

function move(step) {
    if (!filtered.value.length) return;
    highlighted.value =
        (highlighted.value + step + filtered.value.length) % filtered.value.length;
}

function onDocumentClick(event) {
    if (open.value && root.value && !root.value.contains(event.target)) {
        open.value = false;
    }
}

onMounted(() => document.addEventListener('click', onDocumentClick));
onBeforeUnmount(() => document.removeEventListener('click', onDocumentClick));
</script>

<template>
    <div ref="root" class="relative">
        <button
            type="button"
            :disabled="disabled"
            :aria-expanded="open"
            aria-haspopup="listbox"
            :class="[
                'flex h-10 w-full items-center justify-between gap-2 rounded-[var(--radius-field)] border',
                'bg-[var(--surface)] px-3 text-left text-sm transition disabled:opacity-60',
                invalid ? 'border-danger-500' : 'border-[var(--border-strong)]',
            ]"
            @click="toggle"
        >
            <span :style="{ color: selected ? 'var(--text-strong)' : 'var(--text-muted)' }" class="truncate">
                {{ selected?.label ?? placeholder }}
            </span>
            <ChevronsUpDown class="h-4 w-4 shrink-0" style="color: var(--text-muted)" aria-hidden="true" />
        </button>

        <Transition
            enter-active-class="transition duration-[var(--duration-fast)] ease-[var(--ease-out-expo)]"
            leave-active-class="transition duration-100"
            enter-from-class="opacity-0 -translate-y-1"
            leave-to-class="opacity-0"
        >
            <div
                v-if="open"
                class="absolute z-40 mt-1.5 w-full overflow-hidden rounded-xl border bg-[var(--surface)] shadow-[var(--shadow-pop)]"
                style="border-color: var(--border-subtle)"
            >
                <div class="relative border-b" style="border-color: var(--border-subtle)">
                    <Search class="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2" style="color: var(--text-muted)" />
                    <input
                        ref="searchInput"
                        v-model="query"
                        type="text"
                        :placeholder="searchPlaceholder"
                        class="h-10 w-full bg-transparent pl-9 pr-3 text-sm outline-none"
                        style="color: var(--text-strong)"
                        @keydown.down.prevent="move(1)"
                        @keydown.up.prevent="move(-1)"
                        @keydown.enter.prevent="filtered[highlighted] && choose(filtered[highlighted])"
                        @keydown.esc="open = false"
                    >
                </div>

                <ul class="max-h-60 overflow-y-auto scrollbar-thin p-1.5" role="listbox">
                    <li v-if="!filtered.length" class="px-2.5 py-6 text-center text-sm" style="color: var(--text-muted)">
                        {{ emptyText }}
                    </li>

                    <li
                        v-for="(option, index) in filtered"
                        :key="option.value"
                        role="option"
                        :aria-selected="option.value === modelValue"
                        :class="[
                            'flex cursor-pointer items-start gap-2 rounded-lg px-2.5 py-2 text-sm',
                            index === highlighted ? 'bg-[var(--surface-sunken)]' : '',
                        ]"
                        @mouseenter="highlighted = index"
                        @click="choose(option)"
                    >
                        <Check
                            :class="['mt-0.5 h-4 w-4 shrink-0 text-brand-600 dark:text-brand-400', option.value === modelValue ? '' : 'invisible']"
                        />
                        <span class="min-w-0">
                            <span class="block truncate" style="color: var(--text-strong)">{{ option.label }}</span>
                            <span v-if="option.description" class="block truncate text-xs" style="color: var(--text-muted)">
                                {{ option.description }}
                            </span>
                        </span>
                    </li>
                </ul>
            </div>
        </Transition>
    </div>
</template>
