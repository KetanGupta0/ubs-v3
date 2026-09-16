<script setup>
/**
 * Drag and drop or tap to browse. Validates type and size on the client as a
 * courtesy; the server validates again, because this check is trivially
 * bypassed and is only here to save the user a failed upload.
 */
import { ref, computed } from 'vue';
import { UploadCloud, File as FileIcon, X } from 'lucide-vue-next';

const props = defineProps({
    modelValue: { type: [Array, null], default: () => [] },
    accept: { type: String, default: '*/*' },
    multiple: { type: Boolean, default: false },
    /** Megabytes. */
    maxSize: { type: Number, default: 10 },
    hint: { type: String, default: null },
});

const emit = defineEmits(['update:modelValue', 'rejected']);

const dragging = ref(false);
const input = ref(null);

const files = computed(() => props.modelValue ?? []);

function accepted(file) {
    if (file.size > props.maxSize * 1024 * 1024) {
        emit('rejected', { file, reason: `Larger than ${props.maxSize} MB` });
        return false;
    }
    return true;
}

function add(incoming) {
    const valid = Array.from(incoming).filter(accepted);
    if (!valid.length) return;

    emit('update:modelValue', props.multiple ? [...files.value, ...valid] : [valid[0]]);
}

function remove(index) {
    const next = [...files.value];
    next.splice(index, 1);
    emit('update:modelValue', next);
}

function onDrop(event) {
    dragging.value = false;
    add(event.dataTransfer.files);
}

function formatSize(bytes) {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(0)} KB`;
    return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
}
</script>

<template>
    <div class="space-y-3">
        <button
            type="button"
            :class="[
                'flex w-full flex-col items-center justify-center gap-2 rounded-[var(--radius-card)]',
                'border-2 border-dashed px-6 py-8 text-center transition',
                dragging ? 'border-brand-500 bg-brand-50 dark:bg-brand-950/40' : 'border-[var(--border-strong)]',
            ]"
            @click="input.click()"
            @dragover.prevent="dragging = true"
            @dragleave.prevent="dragging = false"
            @drop.prevent="onDrop"
        >
            <UploadCloud class="h-6 w-6" style="color: var(--text-muted)" aria-hidden="true" />
            <span class="text-sm font-medium" style="color: var(--text-strong)">
                Drop {{ multiple ? 'files' : 'a file' }} here, or tap to browse
            </span>
            <span class="text-xs" style="color: var(--text-muted)">
                {{ hint ?? `Up to ${maxSize} MB` }}
            </span>
        </button>

        <input
            ref="input"
            type="file"
            class="sr-only"
            :accept="accept"
            :multiple="multiple"
            @change="add($event.target.files); $event.target.value = ''"
        >

        <ul v-if="files.length" class="space-y-2">
            <li
                v-for="(file, index) in files"
                :key="`${file.name}-${index}`"
                class="flex items-center gap-3 rounded-xl border px-3 py-2"
                style="border-color: var(--border-subtle)"
            >
                <FileIcon class="h-4 w-4 shrink-0" style="color: var(--text-muted)" aria-hidden="true" />
                <span class="min-w-0 flex-1">
                    <span class="block truncate text-sm" style="color: var(--text-strong)">{{ file.name }}</span>
                    <span class="text-xs tnum" style="color: var(--text-muted)">{{ formatSize(file.size) }}</span>
                </span>
                <button
                    type="button"
                    class="rounded-lg p-1.5 transition hover:bg-[var(--surface-sunken)]"
                    style="color: var(--text-muted)"
                    :aria-label="`Remove ${file.name}`"
                    @click="remove(index)"
                >
                    <X class="h-3.5 w-3.5" />
                </button>
            </li>
        </ul>
    </div>
</template>
