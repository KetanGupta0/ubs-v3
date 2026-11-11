<script setup>
/**
 * Writing the next version of a legal document.
 *
 * The form starts from the words in force (or the draft, if one was saved),
 * and nothing on the site changes until Publish. A published version is never
 * edited again: the next change is the next version.
 */
import { computed } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { ArrowLeft, ExternalLink, Eye, Trash2 } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import FormSection from '@/components/Admin/FormSection.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiFormField from '@/components/UI/UiFormField.vue';
import UiInput from '@/components/UI/UiInput.vue';
import UiTextarea from '@/components/UI/UiTextarea.vue';

const props = defineProps({
    document: { type: Object, required: true },
    form: { type: Object, required: true },
    history: { type: Array, default: () => [] },
});

const form = useForm({ ...props.form, publish: false });

const number = computed(() => props.document.nextNumber);
const base = computed(() => `/admin/legal/${props.document.key}`);

function save(publish) {
    if (publish && !window.confirm(
        `Publish version ${number.value}? It is in force at ${props.document.path} straight away and cannot be edited afterwards: a later change becomes version ${number.value + 1}.`,
    )) {
        return;
    }

    form.publish = publish;
    form.post(base.value, {
        preserveScroll: true,
        // The server's form is the truth after a save: a published draft
        // leaves no draft behind, so its change note must not linger here.
        onSuccess: (page) => {
            form.defaults({ ...page.props.form, publish: false });
            form.reset();
        },
    });
}

function discard() {
    if (window.confirm(`Throw away the draft of version ${props.document.draft.number}? The version in force is not affected.`)) {
        router.delete(`${base.value}/draft`, {
            preserveScroll: true,
            onSuccess: (page) => {
                form.defaults({ ...page.props.form, publish: false });
                form.reset();
            },
        });
    }
}
</script>

<template>
    <Head :title="document.title" />

    <AppLayout
        :title="document.title"
        :breadcrumbs="[{ label: 'Admin', href: '/admin' }, { label: 'Legal documents', href: '/admin/legal' }, { label: document.title }]"
    >
        <div class="mx-auto max-w-3xl">
            <PageHeader :title="document.title">
                <template #actions>
                    <UiButton href="/admin/legal" variant="ghost" size="sm">
                        <template #leading><ArrowLeft class="h-3.5 w-3.5" /></template>
                        Legal documents
                    </UiButton>
                    <UiButton v-if="document.current" :href="document.path" :inertia="false" target="_blank" rel="noopener" variant="secondary" size="sm">
                        <template #leading><ExternalLink class="h-3.5 w-3.5" /></template>
                        View live
                    </UiButton>
                </template>
            </PageHeader>

            <p class="-mt-2 mb-5 flex flex-wrap items-center gap-2 text-sm" style="color: var(--text-muted)">
                <template v-if="document.current">
                    <UiBadge tone="success" size="sm" dot>in force</UiBadge>
                    Version {{ document.current.number }} since {{ document.current.publishedAt }}, at
                    <a :href="document.path" target="_blank" rel="noopener" class="underline">{{ document.path }}</a>.
                </template>
                <template v-else>
                    <UiBadge tone="warning" size="sm" dot>not published</UiBadge>
                    Visitors get a "not found" at {{ document.path }} and the footer leaves it out until the first version is published.
                </template>
            </p>

            <form class="space-y-5" @submit.prevent="save(false)">
                <FormSection
                    :title="`Version ${number}`"
                    :description="document.draft
                        ? `Your draft, last saved ${document.draft.updatedAt}. Nothing on the site changes until you publish it.`
                        : document.current
                            ? 'Starts from the words in force. Saving keeps a draft; nothing on the site changes until you publish it.'
                            : 'The first version. Nothing is shown on the site until you publish it.'"
                >
                    <UiFormField label="Introduction" hint="One or two sentences under the title." :error="form.errors.intro">
                        <UiTextarea v-model="form.intro" :rows="2" />
                    </UiFormField>

                    <UiFormField
                        label="Text"
                        required
                        hint="Markdown. Start each section with ## and its heading on a line of its own. **bold**, [a link](https://…), lists with - at the start of a line. HTML is removed."
                        :error="form.errors.body"
                    >
                        <UiTextarea v-model="form.body" :rows="22" class="font-mono text-[13px] leading-relaxed" spellcheck="true" />
                    </UiFormField>

                    <UiFormField
                        label="What changed"
                        :required="!!document.current"
                        :hint="document.current
                            ? 'Needed to publish. Shown with this version, so somebody who agreed to the last one can see what is different.'
                            : 'Optional for the first version.'"
                        :error="form.errors.change_note"
                    >
                        <UiInput v-model="form.change_note" placeholder="Clarified how long enquiries are kept" />
                    </UiFormField>
                </FormSection>

                <div class="flex flex-wrap items-center gap-2">
                    <UiButton type="submit" variant="secondary" :loading="form.processing && !form.publish">Save draft</UiButton>
                    <UiButton v-if="document.draft" :href="`${base}/versions/${document.draft.number}`" :inertia="false" target="_blank" rel="noopener" variant="ghost">
                        <template #leading><Eye class="h-4 w-4" /></template>
                        Preview saved draft
                    </UiButton>
                    <span class="flex-1" />
                    <UiButton v-if="document.draft" variant="ghost" @click="discard">
                        <template #leading><Trash2 class="h-4 w-4" /></template>
                        Throw away draft
                    </UiButton>
                    <UiButton :loading="form.processing && form.publish" @click="save(true)">Publish version {{ number }}</UiButton>
                </div>
            </form>

            <FormSection
                class="mt-8"
                title="History"
                description="Every version ever published stays readable on the site, newest first. Published versions cannot be changed or deleted."
            >
                <p v-if="!history.length" class="text-sm" style="color: var(--text-muted)">Nothing written yet.</p>
                <ol v-else class="divide-y text-sm" style="border-color: var(--border-subtle)">
                    <li v-for="entry in history" :key="entry.number" class="flex flex-wrap items-center gap-x-3 gap-y-1 py-3 first:pt-0 last:pb-0" style="border-color: var(--border-subtle)">
                        <span class="font-medium" style="color: var(--text-strong)">Version {{ entry.number }}</span>
                        <UiBadge
                            :tone="entry.current ? 'success' : entry.status === 'draft' ? 'neutral' : 'brand'"
                            size="sm"
                            dot
                        >
                            {{ entry.current ? 'in force' : entry.status === 'draft' ? 'draft' : 'earlier' }}
                        </UiBadge>
                        <span v-if="entry.publishedAt" style="color: var(--text-muted)">
                            {{ entry.publishedAt }}<template v-if="entry.publishedBy">, by {{ entry.publishedBy }}</template>
                        </span>
                        <span class="flex-1" />
                        <UiButton :href="`${base}/versions/${entry.number}`" :inertia="false" target="_blank" rel="noopener" variant="ghost" size="xs">
                            <template #leading><Eye class="h-3 w-3" /></template>
                            Read
                        </UiButton>
                        <span v-if="entry.changeNote" class="w-full" style="color: var(--text-muted)">{{ entry.changeNote }}</span>
                    </li>
                </ol>
            </FormSection>
        </div>
    </AppLayout>
</template>
