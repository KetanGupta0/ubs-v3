<script setup>
/**
 * One public menu: an optional column title and its links, in order.
 *
 * Each menu saves on its own, so a half finished footer cannot hold back a
 * header change. Addresses are checked on the server against what the site
 * actually serves; the list offered while typing is only a convenience.
 */
import { computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { ArrowDown, ArrowUp, ExternalLink, Plus, Trash2 } from 'lucide-vue-next';

import FormSection from '@/components/Admin/FormSection.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiFormField from '@/components/UI/UiFormField.vue';
import UiInput from '@/components/UI/UiInput.vue';

const props = defineProps({
    menu: { type: Object, required: true },
    suggestions: { type: Array, default: () => [] },
});

const form = useForm({
    title: props.menu.title ?? '',
    items: props.menu.items.map((item) => ({ ...item })),
});

const listId = computed(() => `menu-paths-${props.menu.key}`);
const full = computed(() => form.items.length >= props.menu.max);
const err = (index, field) => form.errors[`items.${index}.${field}`];

function add() {
    form.items.push({ label: '', href: '' });
}

function remove(index) {
    form.items.splice(index, 1);
}

function move(index, by) {
    const target = index + by;
    if (target < 0 || target >= form.items.length) return;
    const [item] = form.items.splice(index, 1);
    form.items.splice(target, 0, item);
}

/** Picking a known page fills in its name when the label is still empty. */
function suggest(item) {
    const known = props.suggestions.find((entry) => entry.href === item.href);
    if (known && !item.label) item.label = known.label;
}

function save() {
    form.put(`/admin/menus/${props.menu.key}`, {
        preserveScroll: true,
        // What was saved is the new starting point, so Save rests until the next change.
        onSuccess: () => form.defaults(),
    });
}
</script>

<template>
    <FormSection
        :title="menu.name"
        :description="`Up to ${menu.max} links. A path on this site, like /contact, or an address starting with https://, which opens in a new tab.`"
    >
        <form class="space-y-4" @submit.prevent="save">
            <UiFormField v-if="menu.titled" label="Column title" required :error="form.errors.title">
                <UiInput v-model="form.title" class="max-w-xs" />
            </UiFormField>

            <datalist :id="listId">
                <option v-for="entry in suggestions" :key="entry.href" :value="entry.href">{{ entry.label }}</option>
            </datalist>

            <p v-if="!form.items.length" class="text-sm" style="color: var(--text-muted)">No links. Add one below.</p>

            <ol class="space-y-2">
                <li
                    v-for="(item, index) in form.items"
                    :key="index"
                    class="grid gap-2 rounded-xl border p-3 sm:grid-cols-[minmax(0,1fr)_minmax(0,1.4fr)_auto] sm:items-start"
                    style="border-color: var(--border-subtle)"
                >
                    <UiFormField :label="`Link ${index + 1}: words`" :error="err(index, 'label')">
                        <UiInput v-model="item.label" placeholder="About" />
                    </UiFormField>
                    <UiFormField label="Goes to" :error="err(index, 'href')">
                        <UiInput v-model="item.href" :list="listId" placeholder="/about" @change="suggest(item)" />
                    </UiFormField>
                    <span class="flex gap-1 sm:pt-6">
                        <UiButton variant="ghost" size="xs" icon aria-label="Move up" :disabled="index === 0" @click="move(index, -1)"><ArrowUp class="h-3.5 w-3.5" /></UiButton>
                        <UiButton variant="ghost" size="xs" icon aria-label="Move down" :disabled="index === form.items.length - 1" @click="move(index, 1)"><ArrowDown class="h-3.5 w-3.5" /></UiButton>
                        <UiButton variant="ghost" size="xs" icon aria-label="Remove link" @click="remove(index)"><Trash2 class="h-3.5 w-3.5" /></UiButton>
                        <a
                            v-if="item.href.startsWith('/') || item.href.startsWith('https://')"
                            :href="item.href"
                            target="_blank"
                            rel="noopener"
                            class="inline-flex h-7 w-7 items-center justify-center rounded-lg transition hover:bg-[var(--surface-sunken)]"
                            style="color: var(--text-muted)"
                            :aria-label="`Open ${item.href}`"
                        >
                            <ExternalLink class="h-3.5 w-3.5" />
                        </a>
                    </span>
                </li>
            </ol>

            <p v-if="form.errors.items" class="text-sm text-danger-600">{{ form.errors.items }}</p>

            <div class="flex flex-wrap items-center justify-between gap-2">
                <UiButton variant="secondary" size="sm" :disabled="full" @click="add">
                    <template #leading><Plus class="h-3.5 w-3.5" /></template>
                    {{ full ? `Full at ${menu.max}` : 'Add a link' }}
                </UiButton>
                <UiButton type="submit" size="sm" :loading="form.processing" :disabled="!form.isDirty">Save {{ menu.name.toLowerCase() }}</UiButton>
            </div>
        </form>
    </FormSection>
</template>
