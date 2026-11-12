<script setup>
/**
 * The wording of one fixed page, section by section.
 *
 * Save keeps a draft that only staff see, through Preview. Publish puts every
 * draft on the page live at once, so a visitor never sees half an edit.
 */
import { computed } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Eye, RotateCcw, Send, Trash2 } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import FormSection from '@/components/Admin/FormSection.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiFormField from '@/components/UI/UiFormField.vue';
import UiInput from '@/components/UI/UiInput.vue';
import UiTextarea from '@/components/UI/UiTextarea.vue';

const props = defineProps({
    page: { type: Object, required: true },
    sections: { type: Array, required: true },
});

const fields = computed(() => props.sections.flatMap((section) => section.fields));

const form = useForm({
    values: Object.fromEntries(fields.value.map((field) => [field.key, field.value])),
});

const drafts = computed(() => fields.value.filter((field) => field.hasDraft).length);
const previewUrl = computed(() => `${props.page.path}${props.page.path.includes('?') ? '&' : '?'}preview=copy`);

function hint(field) {
    const notes = [];
    if (field.type === 'title') notes.push('Put *asterisks* around the words to highlight.');
    if (field.type === 'list') notes.push(`One per line, up to ${field.items}.`);
    if (field.tokens.length) notes.push(`${field.tokens.map((token) => `{${token}}`).join(' and ')} ${field.tokens.length === 1 ? 'is' : 'are'} filled in with the live figure.`);
    notes.push(field.type === 'list' ? `Each line up to ${field.max} characters.` : `Up to ${field.max} characters.`);

    return notes.join(' ');
}

const differsFromDefault = (field) => form.values[field.key] !== field.default;

function restore(field) {
    form.values[field.key] = field.default;
}

function save() {
    form.put(`/admin/copy/${props.page.key}`, { preserveScroll: true, onSuccess: () => form.defaults() });
}

function publish() {
    if (form.isDirty) {
        window.alert('Save your changes as a draft first, so what you publish is what you previewed.');
        return;
    }
    if (window.confirm(`Publish ${drafts.value} ${drafts.value === 1 ? 'change' : 'changes'} to the ${props.page.label} page? Visitors see them straight away.`)) {
        router.post(`/admin/copy/${props.page.key}/publish`, {}, { preserveScroll: true });
    }
}

function discard() {
    if (window.confirm('Throw away every unpublished change on this page? What visitors see stays as it is.')) {
        router.delete(`/admin/copy/${props.page.key}/drafts`, {
            preserveScroll: true,
            onSuccess: () => {
                form.defaults({ values: Object.fromEntries(fields.value.map((field) => [field.key, field.value])) });
                form.reset();
            },
        });
    }
}
</script>

<template>
    <Head :title="`Wording: ${page.label}`" />

    <AppLayout
        :title="`Wording: ${page.label}`"
        :breadcrumbs="[{ label: 'Admin', href: '/admin' }, { label: 'Page wording', href: '/admin/copy' }, { label: page.label }]"
    >
        <div class="mx-auto max-w-3xl">
            <PageHeader :title="`${page.label} page`" :description="`The words on ${page.path}. Save keeps a draft; Preview shows it on the real page; Publish puts it live.`">
                <template #actions>
                    <UiButton href="/admin/copy" variant="ghost" size="sm">
                        <template #leading><ArrowLeft class="h-3.5 w-3.5" /></template>
                        Page wording
                    </UiButton>
                </template>
            </PageHeader>

            <form class="space-y-5" @submit.prevent="save">
                <FormSection v-for="section in sections" :key="section.id" :title="section.label">
                    <div v-for="field in section.fields" :key="field.key">
                        <UiFormField :label="field.label" :hint="hint(field)" :error="form.errors[`values.${field.key}`]">
                            <UiTextarea
                                v-if="field.type === 'text' || field.type === 'list'"
                                v-model="form.values[field.key]"
                                :rows="field.type === 'list' ? Math.min(field.items, 8) : 3"
                            />
                            <UiInput v-else v-model="form.values[field.key]" />
                        </UiFormField>
                        <div class="mt-1 flex flex-wrap items-center gap-2 text-xs">
                            <UiBadge v-if="field.hasDraft" tone="warning" size="sm" dot>unpublished change</UiBadge>
                            <button
                                v-if="differsFromDefault(field)"
                                type="button"
                                class="inline-flex items-center gap-1 hover:underline"
                                style="color: var(--text-muted)"
                                :title="field.default"
                                @click="restore(field)"
                            >
                                <RotateCcw class="h-3 w-3" /> Starting wording
                            </button>
                        </div>
                    </div>
                </FormSection>

                <!-- Kept in view while scrolling a long page. -->
                <div
                    class="sticky bottom-20 z-10 flex flex-wrap items-center gap-2 rounded-[var(--radius-card)] border bg-[var(--surface)] p-3 shadow-[var(--shadow-card)] lg:bottom-4"
                    style="border-color: var(--border-subtle)"
                >
                    <span class="text-sm" style="color: var(--text-muted)">
                        <template v-if="form.isDirty">Changes not saved yet.</template>
                        <template v-else-if="drafts">{{ drafts }} unpublished {{ drafts === 1 ? 'change' : 'changes' }}.</template>
                        <template v-else>Matches what visitors see.</template>
                    </span>
                    <span class="flex-1" />
                    <UiButton v-if="drafts" variant="ghost" size="sm" @click="discard">
                        <template #leading><Trash2 class="h-3.5 w-3.5" /></template>
                        Throw away
                    </UiButton>
                    <UiButton :href="previewUrl" :inertia="false" target="_blank" rel="noopener" variant="secondary" size="sm">
                        <template #leading><Eye class="h-3.5 w-3.5" /></template>
                        Preview
                    </UiButton>
                    <UiButton type="submit" variant="secondary" size="sm" :loading="form.processing" :disabled="!form.isDirty">Save draft</UiButton>
                    <UiButton size="sm" :disabled="!drafts" @click="publish">
                        <template #leading><Send class="h-3.5 w-3.5" /></template>
                        Publish
                    </UiButton>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
