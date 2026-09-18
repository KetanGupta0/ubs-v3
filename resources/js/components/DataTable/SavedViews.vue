<script setup>
/**
 * Filter sets somebody uses often.
 *
 * What is saved is the query, not the rows — the same query the URL already
 * carries — so a saved view and a link pasted to a colleague are the same
 * thing, and both are always current. Sharing one makes it visible to the
 * whole team; only the person who saved it can remove it, because somebody
 * else's bookmark is not a list screen's business.
 */
import { ref, computed, onMounted } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { Bookmark, BookmarkPlus, Trash2, Users } from 'lucide-vue-next';

import UiButton from '@/components/UI/UiButton.vue';
import UiDropdown from '@/components/UI/UiDropdown.vue';
import UiModal from '@/components/UI/UiModal.vue';
import UiInput from '@/components/UI/UiInput.vue';
import UiSwitch from '@/components/UI/UiSwitch.vue';
import UiFormField from '@/components/UI/UiFormField.vue';

const views = ref([]);
const saving = ref(false);

const screen = computed(() => window.location.pathname);

const form = useForm({ name: '', screen: '', state: {}, is_shared: false });

async function load() {
    try {
        const response = await fetch(`/views?screen=${encodeURIComponent(screen.value)}`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });

        views.value = response.ok ? (await response.json()).views : [];
    } catch {
        views.value = [];
    }
}

onMounted(load);

/** Whatever is in the URL right now, which is the whole state of the table. */
function currentState() {
    const params = new URLSearchParams(window.location.search);
    const state = {};

    for (const [key, value] of params.entries()) {
        const nested = key.match(/^(\w+)\[(.+)\]$/);

        if (nested) {
            state[nested[1]] ??= {};
            state[nested[1]][nested[2]] = value;
        } else {
            state[key] = value;
        }
    }

    return state;
}

function open() {
    form.clearErrors();
    form.name = '';
    form.is_shared = false;
    saving.value = true;
}

function save() {
    form.screen = screen.value;
    form.state = currentState();

    form.post('/views', {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            saving.value = false;
            form.reset();
            load();
        },
    });
}

function apply(view) {
    router.visit(view.href, { preserveState: false });
}

function remove(view) {
    if (!confirm(`Remove “${view.name}”?`)) return;

    router.delete(`/views/${view.id}`, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: load,
    });
}
</script>

<template>
    <UiDropdown>
        <template #trigger>
            <UiButton variant="secondary" size="sm">
                <template #leading><Bookmark class="h-4 w-4" /></template>
                <span class="hidden sm:inline">Views</span>
            </UiButton>
        </template>

        <p class="px-2.5 py-1.5 text-xs font-semibold uppercase tracking-wide" style="color: var(--text-muted)">
            Saved views
        </p>

        <p v-if="!views.length" class="px-2.5 pb-2 text-xs" style="color: var(--text-muted)">
            None yet. Filter the list how you like, then save it.
        </p>

        <div v-else class="max-h-64 overflow-y-auto">
            <div
                v-for="view in views"
                :key="view.id"
                class="flex items-center gap-2 px-2.5 py-1.5"
            >
                <button
                    type="button"
                    class="min-w-0 flex-1 truncate text-left text-sm transition hover:text-[var(--text-strong)]"
                    style="color: var(--text-base)"
                    @click="apply(view)"
                >
                    {{ view.name }}
                    <Users
                        v-if="view.shared"
                        class="ml-1 inline h-3 w-3"
                        style="color: var(--text-muted)"
                        aria-label="Shared with the team"
                    />
                </button>

                <button
                    v-if="view.mine"
                    type="button"
                    class="shrink-0 rounded p-1 transition hover:bg-[var(--surface-sunken)]"
                    aria-label="Remove view"
                    @click.stop="remove(view)"
                >
                    <Trash2 class="h-3.5 w-3.5" style="color: var(--color-danger-500)" />
                </button>
            </div>
        </div>

        <div class="border-t px-2.5 py-1.5" style="border-color: var(--border-subtle)">
            <button
                type="button"
                class="flex w-full items-center gap-2 py-1 text-sm transition hover:text-[var(--text-strong)]"
                style="color: var(--text-base)"
                @click="open"
            >
                <BookmarkPlus class="h-4 w-4" />
                Save this view
            </button>
        </div>
    </UiDropdown>

    <UiModal :open="saving" title="Save this view" @close="saving = false">
        <form class="space-y-4" @submit.prevent="save">
            <p class="text-sm" style="color: var(--text-muted)">
                The filters, the sort and the search as they are now. Nothing about the rows is stored, so the view
                always shows what is true today.
            </p>

            <UiFormField label="Call it" required :error="form.errors.name">
                <UiInput v-model="form.name" placeholder="Overdue invoices over a lakh" />
            </UiFormField>

            <UiSwitch
                v-model="form.is_shared"
                label="Share with the team"
                description="Everybody who can open this screen sees it. Only you can remove it."
            />
        </form>

        <template #footer>
            <UiButton variant="ghost" @click="saving = false">Cancel</UiButton>
            <UiButton :loading="form.processing" :disabled="!form.name" @click="save">Save</UiButton>
        </template>
    </UiModal>
</template>
