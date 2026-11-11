<script setup>
/**
 * Choose a picture from the media library.
 *
 * Newest first, a page at a time. Finding an older picture by its words is
 * what the library screen's search is for; this is the quick reach for the
 * pictures somebody just uploaded.
 */
import { ref, watch } from 'vue';
import { ExternalLink } from 'lucide-vue-next';

import UiButton from '@/components/UI/UiButton.vue';
import UiModal from '@/components/UI/UiModal.vue';
import UiSpinner from '@/components/UI/UiSpinner.vue';

const props = defineProps({
    open: { type: Boolean, default: false },
});

const emit = defineEmits(['close', 'select']);

const items = ref([]);
const nextPage = ref(1);
const loading = ref(false);
const failed = ref(false);

async function load() {
    if (loading.value || nextPage.value === null) return;
    loading.value = true;
    failed.value = false;

    try {
        const response = await fetch(`/admin/media/picker?page=${nextPage.value}`, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });
        if (!response.ok) throw new Error(String(response.status));

        const data = await response.json();
        items.value.push(...data.items);
        nextPage.value = data.nextPage;
    } catch {
        failed.value = true;
    } finally {
        loading.value = false;
    }
}

// Fresh each time it opens, so a picture uploaded a moment ago is there.
watch(() => props.open, (open) => {
    if (!open) return;
    items.value = [];
    nextPage.value = 1;
    load();
});

function choose(item) {
    emit('select', item);
    emit('close');
}
</script>

<template>
    <UiModal :open="open" size="xl" title="Choose from the media library" @close="emit('close')">
        <div class="space-y-4">
            <p v-if="failed" class="text-sm text-danger-600">The library could not be loaded. Close this and try again.</p>

            <p v-else-if="!loading && !items.length" class="text-sm" style="color: var(--text-muted)">
                The library is empty. Upload a picture in this block, or add some in the media library first.
            </p>

            <ul v-if="items.length" class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                <li v-for="item in items" :key="item.id">
                    <button
                        type="button"
                        class="group block w-full overflow-hidden rounded-xl border text-left transition hover:border-brand-500 focus-visible:border-brand-500"
                        style="border-color: var(--border-subtle)"
                        @click="choose(item)"
                    >
                        <img :src="item.url" :alt="item.alt" loading="lazy" class="aspect-[4/3] w-full object-cover" style="background: var(--surface-sunken)">
                        <span class="block truncate px-2 py-1.5 text-xs" style="color: var(--text-muted)">{{ item.alt }}</span>
                    </button>
                </li>
            </ul>

            <div class="flex flex-wrap items-center justify-between gap-2">
                <a href="/admin/media" target="_blank" rel="noopener" class="inline-flex items-center gap-1.5 text-sm text-brand-600 hover:underline dark:text-brand-400">
                    <ExternalLink class="h-3.5 w-3.5" /> Open the library
                </a>
                <UiSpinner v-if="loading" />
                <UiButton v-else-if="nextPage" variant="secondary" size="sm" @click="load">Show more</UiButton>
            </div>
        </div>
    </UiModal>
</template>
